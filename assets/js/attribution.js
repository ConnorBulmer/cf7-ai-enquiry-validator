(function () {
	'use strict';
	var cfg = window.cf7aivAttribution || {};
	var storageName = cfg.cookieName || 'cf7aiv_attribution';
	var clickKeys = ['gclid', 'gbraid', 'wbraid', 'msclkid'];
	var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
	var consentKeys = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];
	var submissionIds = new WeakMap();

	function createSubmissionId() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2);
	}
	function validClick(key, value) {
		if (typeof value !== 'string') return '';
		value = value.trim();
		if (key === 'msclkid') return /^[a-f0-9]{32}$/i.test(value) ? value : '';
		return /^[A-Za-z0-9._~-]{8,256}$/.test(value) ? value : '';
	}
	function cookieEntries() {
		if (!document.cookie) return [];
		return document.cookie.split(';').map(function (part) {
			part = part.trim();
			var separator = part.indexOf('=');
			if (separator < 1) return null;
			try {
				return { name: part.slice(0, separator), value: decodeURIComponent(part.slice(separator + 1)) };
			} catch (e) { return null; }
		}).filter(function (entry) { return entry !== null; });
	}
	function readCookie(name) {
		var matches = cookieEntries().filter(function (entry) { return entry.name === name; });
		return matches.length === 1 ? matches[0].value : '';
	}
	function parseStored(raw) {
		try {
			var value = JSON.parse(raw || '{}');
			return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
		} catch (e) { return {}; }
	}
	/*
	 * The cookie is the copy the server fallback also writes, on navigations
	 * the script never sees - a landing request whose query a redirect then
	 * dropped, for one. Reading only localStorage whenever it existed lost
	 * those, and an older click id held there beat the fresh one. The cookie
	 * therefore wins, and localStorage fills in what the cookie does not hold:
	 * long URLs left out to keep it under the size limit, or everything once
	 * the cookie has expired or been removed.
	 */
	function readStored() {
		var local = '';
		try { local = localStorage.getItem(storageName) || ''; } catch (e) {}
		var stored = Object.assign(parseStored(local), parseStored(readCookie(storageName)));
		delete stored.submission_id;
		return stored;
	}
	/*
	 * A browser silently refuses a cookie over about 4KB and keeps the old one,
	 * which would then win the next read. Long URLs are left out first; the
	 * full copy stays in localStorage.
	 */
	function cookiePayload(persisted) {
		var payload = Object.assign({}, persisted);
		var raw = JSON.stringify(payload);
		['referrer', 'current_page', 'first_landing_page'].forEach(function (key) {
			if (encodeURIComponent(raw).length > 3500 && key in payload) {
				delete payload[key];
				raw = JSON.stringify(payload);
			}
		});
		return raw;
	}
	function cookieSuffix() {
		return ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	}
	function expireCookie() {
		document.cookie = storageName + '=' + cookieSuffix().replace(';path=/', ';path=/;Max-Age=0');
	}
	function writeStored(value) {
		var persisted = Object.assign({}, value);
		delete persisted.submission_id;
		var raw = JSON.stringify(persisted);
		var cookieRaw = cookiePayload(persisted);
		/*
		 * Skip a write that would change nothing. One consent decision can reach
		 * us twice - a Consent Mode dataLayer push and a WordPress Consent API
		 * event - and re-storing an identical payload is pure noise.
		 */
		var currentLocal = null;
		try { currentLocal = localStorage.getItem(storageName); } catch (e) {}
		if (currentLocal === raw && readCookie(storageName) === cookieRaw) { return; }
		if (currentLocal !== raw) { try { localStorage.setItem(storageName, raw); } catch (e) {} }
		var age = Math.max(1, Number(cfg.retentionDays || 90)) * 86400;
		document.cookie = storageName + '=' + encodeURIComponent(cookieRaw) + ';path=/;max-age=' + age + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	}

	function clearFields(root) {
		Object.keys(cfg.fields || {}).forEach(function (field) {
			if (cfg.fields[field] === 'submission_id') return;
			var input = root.querySelector('[name="' + field + '"]');
			if (input) input.value = '';
		});
	}
	function clearAllFields() {
		document.querySelectorAll('.wpcf7 form').forEach(clearFields);
	}
	function deleteStored() {
		try { localStorage.removeItem(storageName); } catch (e) {}
		document.cookie = storageName + '=;path=/;Max-Age=0;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
		clearAllFields();
	}
	function explicitOptInType(value) {
		if (typeof value !== 'string') return false;
		value = value.toLowerCase().replace(/[-_\s]/g, '');
		return value === 'optin';
	}
	function googleConsentMode() {
		var mode = {};
		var decided = {};
		(window.dataLayer || []).forEach(function (entry) {
			var row = Array.prototype.slice.call(entry || []);
			if (row[0] !== 'consent' || (row[1] !== 'default' && row[1] !== 'update') || !row[2]) return;
			consentKeys.forEach(function (key) {
				if (row[2][key] === 'granted' || row[2][key] === 'denied') {
					mode[key] = row[2][key];
					if (row[1] === 'update') decided[key] = true;
				}
			});
		});
		return { values: mode, decided: decided };
	}
	/*
	 * Complianz answers false for every category while its banner is still
	 * waiting, which is "not asked yet", not a refusal. Its own cookies record
	 * that a choice was made.
	 */
	function complianzDecided() {
		var prefix = (window.complianz && typeof window.complianz.prefix === 'string') ? window.complianz.prefix : 'cmplz_';
		if (readCookie(prefix + 'banner-status') === 'dismissed') return true;
		return ['marketing', 'statistics'].some(function (category) {
			var value = readCookie(prefix + category);
			return value === 'allow' || value === 'deny';
		});
	}
	/**
	 * Complianz publishes consent through its own function and its own events.
	 *
	 * It does not necessarily emit Consent Mode entries into dataLayer, and its
	 * WordPress Consent API bridge is optional, so a site can have consent fully
	 * granted while both routes this script already knew about stay silent. On
	 * such a site no attribution was ever stored and no conversion could ever be
	 * delivered - with nothing logged, because as far as the script could tell
	 * consent had simply never been decided.
	 *
	 * Its categories map the same way the WordPress Consent API branch maps them.
	 */
	function complianzConsent() {
		if (typeof window.cmplz_has_consent !== 'function') return null;
		try {
			return {
				statistics: window.cmplz_has_consent('statistics') === true,
				marketing: window.cmplz_has_consent('marketing') === true,
				source: 'complianz',
			};
		} catch (e) { return null; }
	}
	function consentState() {
		if (typeof window.wp_has_consent === 'function') {
			try {
				if (typeof window.wp_get_consent_type === 'function' && explicitOptInType(window.wp_get_consent_type())) {
					return {
						statistics: window.wp_has_consent('statistics') === true,
						marketing: window.wp_has_consent('marketing') === true,
						source: 'wordpress',
					};
				}
			} catch (e) {}
		}
		var complianz = complianzConsent();
		if (complianz) return complianz;
		var google = googleConsentMode();
		return {
			statistics: google.values.analytics_storage === 'granted',
			marketing: google.values.ad_storage === 'granted',
			source: 'google',
			mode: google.values,
			decided: google.decided,
		};
	}
	function decodeUrlPart(value) {
		if (/%(?![0-9a-f]{2})/i.test(value)) return null;
		try { return decodeURIComponent(value.replace(/\+/g, ' ')); } catch (e) { return null; }
	}
	function sanitizeParameters(value) {
		var kept = [];
		var hadClick = false;
		var identifiers = clickKeys.concat(['zc_gad']);
		if (!value) return { value: '', hadClick: false };
		var parts = value.split('&');
		for (var index = 0; index < parts.length; index++) {
			var part = parts[index];
			var separator = part.indexOf('=');
			var rawName = separator === -1 ? part : part.slice(0, separator);
			var rawValue = separator === -1 ? '' : part.slice(separator + 1);
			var name = decodeUrlPart(rawName);
			if (name === null || decodeUrlPart(rawValue) === null) return null;
			if (identifiers.indexOf(name.toLowerCase()) !== -1) {
				hadClick = true;
				continue;
			}
			kept.push(part);
		}
		return { value: kept.join('&'), hadClick: hadClick };
	}
	function sanitizeFragment(value) {
		if (!value) return { value: '', hadClick: false };
		var question = value.indexOf('?');
		var prefix = '';
		var parameters = '';
		if (question !== -1) {
			prefix = value.slice(0, question);
			parameters = value.slice(question + 1);
			if (decodeUrlPart(prefix) === null) return null;
		} else if (value.indexOf('=') !== -1) {
			parameters = value;
		} else {
			return decodeUrlPart(value) === null ? null : { value: value, hadClick: false };
		}
		var sanitized = sanitizeParameters(parameters);
		if (!sanitized) return null;
		return {
			value: prefix + (sanitized.value ? '?' + sanitized.value : ''),
			hadClick: sanitized.hadClick,
		};
	}
	function sanitizeUrl(value) {
		if (typeof value !== 'string' || /[\u0000-\u001f\u007f]/.test(value)) return null;
		try {
			var url = new URL(value);
			if ((url.protocol !== 'http:' && url.protocol !== 'https:') || !url.hostname || decodeUrlPart(url.pathname) === null) return null;
			var query = sanitizeParameters(url.search.slice(1));
			var fragment = sanitizeFragment(url.hash.slice(1));
			if (!query || !fragment) return null;
			url.search = query.value ? '?' + query.value : '';
			url.hash = fragment.value ? '#' + fragment.value : '';
			return { value: url.toString(), hadClick: query.hadClick || fragment.hadClick };
		} catch (e) { return null; }
	}
	function filterStored(values, consent) {
		if (!consent.marketing) {
			clickKeys.forEach(function (key) { delete values[key]; });
			['first_landing_page', 'current_page', 'referrer'].forEach(function (key) {
				if (!values[key]) return;
				var sanitized = sanitizeUrl(values[key]);
				if (!sanitized || (key === 'first_landing_page' && sanitized.hadClick)) delete values[key];
				else values[key] = sanitized.value;
			});
		}
		if (!consent.statistics) {
			utmKeys.concat(['ga_client_id', 'ga_session_id', 'first_landing_page', 'current_page', 'referrer']).forEach(function (key) { delete values[key]; });
		}
		return values;
	}
	function gaIdentity() {
		var measurement = typeof cfg.measurementId === 'string' ? cfg.measurementId.trim().toUpperCase() : '';
		if (!/^G-[A-Z0-9]+$/.test(measurement)) return {};
		var suffix = measurement.slice(2).replace(/[^A-Z0-9]/g, '_');
		var ending = '_ga_' + suffix;
		var entries = cookieEntries();
		var pairs = [];
		entries.forEach(function (entry) {
			if (!entry.name.endsWith(ending)) return;
			var prefix = entry.name.slice(0, -ending.length);
			var clients = entries.filter(function (candidate) { return candidate.name === prefix + '_ga'; });
			if (clients.length !== 1) return;
			var clientMatch = clients[0].value.match(/^GA\d+\.\d+\.(\d+\.\d+)$/);
			// GS2 marks the session id with an s (GS2.1.s1712345678$o5$g1...);
			// GS1, still common, puts it bare in the third field
			// (GS1.1.1712345678.5.1...).
			var sessionMatch = entry.value.match(/(?:^|[.$])s(\d{6,20})(?:[.$]|$)/) || entry.value.match(/^GS1\.\d+\.(\d{6,20})(?:\.|$)/);
			if (clientMatch && sessionMatch) {
				pairs.push({ ga_client_id: clientMatch[1], ga_session_id: sessionMatch[1] });
			}
		});
		return pairs.length === 1 ? pairs[0] : {};
	}
	function captureConsent(target, consent) {
		// Both of these answer by category rather than by Consent Mode key, and
		// both answer synchronously, so their reply maps straight across.
		if (consent.source === 'wordpress' || consent.source === 'complianz') {
			target.analytics_storage = consent.statistics ? 'granted' : 'denied';
			target.ad_storage = consent.marketing ? 'granted' : 'denied';
			target.ad_user_data = consent.marketing ? 'granted' : 'denied';
			target.ad_personalization = consent.marketing ? 'granted' : 'denied';
			return;
		}
		consentKeys.forEach(function (key) {
			if (consent.mode[key] === 'granted' || consent.mode[key] === 'denied') target[key] = consent.mode[key];
		});
	}
	/**
	 * Has the visitor refused this category, as opposed to not having decided?
	 *
	 * The WordPress Consent API is synchronous and authoritative, so if it
	 * answered, the decision is real. Consent Mode is published asynchronously
	 * through dataLayer, and only an `update` is a decision. The standard setup
	 * pushes `default` denied in the page head on every page, before the
	 * consent manager has read the visitor's stored choice; treating that as a
	 * refusal deleted the click id captured on the landing page as soon as the
	 * visitor opened a second page, before the granted update arrived. That held
	 * per category too: a default granting statistics but not advertising
	 * deleted the click ids while the advertising answer was still pending.
	 */
	function refused(consent, category) {
		if (consent[category]) { return false; }
		if (consent.source === 'wordpress') { return true; }
		if (consent.source === 'complianz') { return complianzDecided(); }
		return (consent.decided || {})[category === 'statistics' ? 'analytics_storage' : 'ad_storage'] === true;
	}
	function collect() {
		var consent = consentState();
		if (!consent.statistics && !consent.marketing) {
			/*
			 * Only discard stored attribution when consent has genuinely been
			 * refused. collect() runs on every page load, and with a Consent Mode
			 * consent manager nothing has been published at that point - treating
			 * "not yet known" as "denied" deleted the click id captured on the
			 * landing page before the visitor ever reached the form. Measured on a
			 * staging site: gclid stored correctly on the ad landing page, then
			 * wiped on the next page view, leaving first_landing_page as /contact/.
			 *
			 * Undecided, localStorage is left untouched and nothing is submitted.
			 * The cookie goes, because the server fallback reads consent from it
			 * and would otherwise keep renewing it on the strength of a choice the
			 * visitor has not repeated.
			 */
			if (refused(consent, 'statistics') || refused(consent, 'marketing')) { deleteStored(); }
			else { expireCookie(); clearAllFields(); }
			return {};
		}
		// What is submitted follows current consent; what is kept follows refusals.
		var retained = filterStored(readStored(), { statistics: !refused(consent, 'statistics'), marketing: !refused(consent, 'marketing') });
		var stored = filterStored(Object.assign({}, retained), consent);
		var params = new URLSearchParams(location.search);
		if (consent.marketing) {
			clickKeys.forEach(function (key) {
				var fresh = validClick(key, params.get(key) || '');
				if (!fresh && key === 'gclid') fresh = validClick(key, params.get('zc_gad') || '');
				if (fresh) stored[key] = fresh;
				else if (!validClick(key, stored[key] || '')) delete stored[key];
			});
		}
		if (consent.statistics) {
			utmKeys.forEach(function (key) {
				if (params.has(key)) stored[key] = (params.get(key) || '').slice(0, 512);
			});
			var currentUrl = consent.marketing ? { value: location.href.slice(0, 2048) } : sanitizeUrl(location.href);
			if (currentUrl) {
				var current = currentUrl.value.slice(0, 2048);
				if (!stored.first_landing_page) stored.first_landing_page = current;
				stored.current_page = current;
			}
			// Only an external referrer says where the visitor came from. On a
			// direct landing the second page's referrer is the first page.
			if (!stored.referrer && document.referrer && !sameSite(document.referrer)) {
				var referrer = sanitizeUrl(document.referrer);
				if (referrer) stored.referrer = referrer.value.slice(0, 2048);
			}
			Object.assign(stored, gaIdentity());
		}
		captureConsent(stored, consent);
		writeStored(Object.assign(retained, stored));
		return stored;
	}
	function sameSite(value) {
		try { return new URL(value).hostname === new URL(location.href).hostname; } catch (e) { return false; }
	}
	function formElement(target) {
		if (!target) return null;
		if (target.matches && target.matches('.wpcf7 form')) return target;
		if (target.closest) {
			var closest = target.closest('.wpcf7 form');
			if (closest) return closest;
		}
		return target.querySelector ? target.querySelector('form') : null;
	}
	function submissionIdFor(form) {
		var value = submissionIds.get(form);
		if (!value) {
			value = createSubmissionId();
			submissionIds.set(form, value);
		}
		return value;
	}
	function populate(root, values) {
		if (!values) {
			clearFields(root);
			return;
		}
		Object.keys(cfg.fields || {}).forEach(function (field) {
			var input = root.querySelector('[name="' + field + '"]');
			if (input) input.value = values[cfg.fields[field]] || '';
		});
	}
	function populateForm(form, values) {
		if (!form) return;
		var formValues = Object.assign({}, values || collect());
		formValues.submission_id = submissionIdFor(form);
		populate(form, formValues);
	}
	function populateAll() {
		var values = collect();
		document.querySelectorAll('.wpcf7 form').forEach(function (form) { populateForm(form, values); });
	}
	function rotateSubmission(event) {
		var form = formElement(event && event.target);
		if (!form) return;
		submissionIds.set(form, createSubmissionId());
		populateForm(form);
	}
	/*
	 * Consent Mode consent managers publish through dataLayer, not through the
	 * WordPress Consent API, and they publish asynchronously - after
	 * DOMContentLoaded.
	 *
	 * Without this, collect() runs once at DOMContentLoaded, sees no consent
	 * signal yet, and stores nothing. The consent update lands a moment later,
	 * but nothing re-collects, so a click id present on the landing URL is lost
	 * for good: attribution_ready never becomes 1 and no conversion is ever
	 * dispatched. Measured on a staging site using a Consent Mode banner - at DOMContentLoaded
	 * the dataLayer held zero consent entries; the granted update arrived after.
	 *
	 * Wrapping push is the usual way to observe Consent Mode. It runs the
	 * original push first so GTM behaviour is unchanged, and it is installed at
	 * script execution rather than on DOMContentLoaded so it is in place before a
	 * consent manager loaded later can publish. Anything pushed before this
	 * script runs is still picked up, because googleConsentMode() scans the whole
	 * dataLayer array on every collect().
	 */
	function watchConsentMode() {
		try {
			window.dataLayer = window.dataLayer || [];
			if (window.dataLayer.__cf7aivWatched) return;
			var originalPush = window.dataLayer.push;
			if (typeof originalPush !== 'function') return;
			window.dataLayer.push = function () {
				var result = originalPush.apply(this, arguments);
				try {
					var row = Array.prototype.slice.call(arguments[0] || []);
					if (row[0] === 'consent' && (row[1] === 'default' || row[1] === 'update')) {
						populateAll();
					}
				} catch (e) {}
				return result;
			};
			window.dataLayer.__cf7aivWatched = true;
		} catch (e) {}
	}
	watchConsentMode();

	document.addEventListener('DOMContentLoaded', populateAll);
	document.addEventListener('submit', function (event) {
		if (event.target && event.target.closest('.wpcf7')) populateForm(formElement(event.target));
	}, true);
	/*
	 * Contact Form 7 builds the FormData it sends before it announces
	 * beforesubmit, and passes it along. Filling the inputs alone is then too
	 * late for a submission that skipped the native submit event, so the values
	 * are written into that FormData as well.
	 */
	document.addEventListener('wpcf7beforesubmit', function (event) {
		var form = formElement(event.target);
		populateForm(form);
		var formData = event.detail && event.detail.formData;
		if (!form || !formData || typeof formData.set !== 'function') return;
		Object.keys(cfg.fields || {}).forEach(function (field) {
			var input = form.querySelector('[name="' + field + '"]');
			if (input) formData.set(field, input.value);
		});
	});
	/*
	 * Only a sent message starts a new submission. A mail failure is still
	 * captured, and the visitor usually presses Send again: keeping its token
	 * makes that retry update the same enquiry instead of adding a second one,
	 * which would be classified and sent as a second conversion.
	 */
	document.addEventListener('wpcf7mailsent', rotateSubmission);
	document.addEventListener('wp_listen_for_consent_change', populateAll);
	/*
	 * Complianz announces each category as its own event once the visitor
	 * decides, and fires them on the document. Without these the first decision
	 * on a page is only noticed on the next navigation, which is exactly when the
	 * click identifier from the landing URL has already been lost.
	 */
	['cmplz_event_marketing', 'cmplz_event_statistics', 'cmplz_status_change', 'cmplz_fire_categories'].forEach(function (eventName) {
		document.addEventListener(eventName, populateAll);
	});
}());
