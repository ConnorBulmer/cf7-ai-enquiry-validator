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
	function readStored() {
		var raw = '';
		try { raw = localStorage.getItem(storageName) || ''; } catch (e) {}
		if (!raw) raw = readCookie(storageName);
		try {
			var stored = JSON.parse(raw) || {};
			delete stored.submission_id;
			return stored;
		} catch (e) { return {}; }
	}
	function writeStored(value) {
		var persisted = Object.assign({}, value);
		delete persisted.submission_id;
		var raw = JSON.stringify(persisted);
		try { localStorage.setItem(storageName, raw); } catch (e) {}
		var age = Math.max(1, Number(cfg.retentionDays || 90)) * 86400;
		document.cookie = storageName + '=' + encodeURIComponent(raw) + ';path=/;max-age=' + age + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
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
		(window.dataLayer || []).forEach(function (entry) {
			var row = Array.prototype.slice.call(entry || []);
			if (row[0] !== 'consent' || (row[1] !== 'default' && row[1] !== 'update') || !row[2]) return;
			consentKeys.forEach(function (key) {
				if (row[2][key] === 'granted' || row[2][key] === 'denied') mode[key] = row[2][key];
			});
		});
		return mode;
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
		var mode = googleConsentMode();
		return {
			statistics: mode.analytics_storage === 'granted',
			marketing: mode.ad_storage === 'granted',
			source: 'google',
			mode: mode,
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
		for (var index = 0; index < value.split('&').length; index++) {
			var part = value.split('&')[index];
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
			var sessionMatch = entry.value.match(/(?:^|[.$])s(\d{6,20})(?:[.$]|$)/);
			if (clientMatch && sessionMatch) {
				pairs.push({ ga_client_id: clientMatch[1], ga_session_id: sessionMatch[1] });
			}
		});
		return pairs.length === 1 ? pairs[0] : {};
	}
	function captureConsent(target, consent) {
		if (consent.source === 'wordpress') {
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
	function collect() {
		var consent = consentState();
		if (!consent.statistics && !consent.marketing) {
			deleteStored();
			return {};
		}
		var stored = filterStored(readStored(), consent);
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
			if (!stored.referrer && document.referrer) {
				var referrer = sanitizeUrl(document.referrer);
				if (referrer) stored.referrer = referrer.value.slice(0, 2048);
			}
			Object.assign(stored, gaIdentity());
		}
		captureConsent(stored, consent);
		writeStored(stored);
		return stored;
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
	document.addEventListener('DOMContentLoaded', populateAll);
	document.addEventListener('submit', function (event) {
		if (event.target && event.target.closest('.wpcf7')) populateForm(formElement(event.target));
	}, true);
	document.addEventListener('wpcf7beforesubmit', function (event) { populateForm(formElement(event.target)); });
	document.addEventListener('wpcf7mailsent', rotateSubmission);
	document.addEventListener('wpcf7mailfailed', rotateSubmission);
	document.addEventListener('wp_listen_for_consent_change', populateAll);
}());
