(function () {
	'use strict';
	var cfg = window.cf7aivAttribution || {};
	var storageName = cfg.cookieName || 'cf7aiv_attribution';
	var clickKeys = ['gclid', 'gbraid', 'wbraid', 'msclkid'];
	var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];
	var consentKeys = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];
	var submissionId = createSubmissionId();

	function createSubmissionId() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2);
	}
	function validClick(key, value) {
		if (typeof value !== 'string') return '';
		value = value.trim();
		if (key === 'msclkid') return /^[a-f0-9]{32}$/i.test(value) ? value : '';
		return /^[A-Za-z0-9._~-]{8,256}$/.test(value) ? value : '';
	}
	function readCookie(name) {
		var prefix = name + '=';
		var part = document.cookie.split('; ').find(function (item) { return item.indexOf(prefix) === 0; });
		return part ? decodeURIComponent(part.slice(prefix.length)) : '';
	}
	function readStored() {
		var raw = '';
		try { raw = localStorage.getItem(storageName) || ''; } catch (e) {}
		if (!raw) raw = readCookie(storageName);
		try { return JSON.parse(raw) || {}; } catch (e) { return {}; }
	}
	function writeStored(value) {
		var raw = JSON.stringify(value);
		try { localStorage.setItem(storageName, raw); } catch (e) {}
		var age = Math.max(1, Number(cfg.retentionDays || 90)) * 86400;
		document.cookie = storageName + '=' + encodeURIComponent(raw) + ';path=/;max-age=' + age + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	}
	function clearFields(root) {
		Object.keys(cfg.fields || {}).forEach(function (field) {
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
	function knownConsentType(value) {
		if (typeof value !== 'string') return false;
		value = value.toLowerCase().replace(/[-_\s]/g, '');
		return value === 'optin' || value === 'optout';
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
				if (typeof window.wp_get_consent_type !== 'function' || !knownConsentType(window.wp_get_consent_type())) {
					return { statistics: false, marketing: false, source: 'wordpress' };
				}
				return {
					statistics: window.wp_has_consent('statistics') === true,
					marketing: window.wp_has_consent('marketing') === true,
					source: 'wordpress',
				};
			} catch (e) { return { statistics: false, marketing: false, source: 'wordpress' }; }
		}
		var mode = googleConsentMode();
		return {
			statistics: mode.analytics_storage === 'granted',
			marketing: mode.ad_storage === 'granted',
			source: 'google',
			mode: mode,
		};
	}
	function hasClickParameter(value) {
		try {
			var url = new URL(value);
			return clickKeys.concat(['zc_gad']).some(function (key) { return url.searchParams.has(key); });
		} catch (e) { return false; }
	}
	function withoutClickParameters(value) {
		try {
			var url = new URL(value);
			clickKeys.concat(['zc_gad']).forEach(function (key) { url.searchParams.delete(key); });
			return url.toString();
		} catch (e) { return value; }
	}
	function filterStored(values, consent) {
		if (!consent.marketing) {
			clickKeys.forEach(function (key) { delete values[key]; });
			if (hasClickParameter(values.first_landing_page || '')) delete values.first_landing_page;
			['current_page', 'referrer'].forEach(function (key) {
				if (values[key]) values[key] = withoutClickParameters(values[key]);
			});
		}
		if (!consent.statistics) {
			utmKeys.concat(['ga_client_id', 'ga_session_id', 'first_landing_page', 'current_page', 'referrer']).forEach(function (key) { delete values[key]; });
		}
		return values;
	}
	function gaIdentity() {
		var result = {};
		var ga = readCookie('_ga').match(/^GA\d+\.\d+\.(\d+\.\d+)$/);
		if (ga) result.ga_client_id = ga[1];
		document.cookie.split('; ').some(function (part) {
			if (part.indexOf('_ga_') !== 0) return false;
			var match = decodeURIComponent(part.split('=').slice(1).join('=')).match(/(?:^|\.)s(\d{6,20})(?:\.|$)/);
			if (match) result.ga_session_id = match[1];
			return !!match;
		});
		return result;
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
			return null;
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
			var current = consent.marketing ? location.href.slice(0, 2048) : withoutClickParameters(location.href).slice(0, 2048);
			if (!stored.first_landing_page) stored.first_landing_page = current;
			stored.current_page = current;
			if (!stored.referrer && document.referrer) stored.referrer = withoutClickParameters(document.referrer).slice(0, 2048);
			Object.assign(stored, gaIdentity());
		}
		captureConsent(stored, consent);
		if (stored.submission_id) submissionId = stored.submission_id;
		stored.submission_id = submissionId;
		writeStored(stored);
		return stored;
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
	function populateAll() {
		var values = collect();
		document.querySelectorAll('.wpcf7 form').forEach(function (form) { populate(form, values); });
	}
	function completeSubmission() {
		var consent = consentState();
		if (!consent.statistics && !consent.marketing) {
			deleteStored();
			return;
		}
		var values = filterStored(readStored(), consent);
		delete values.submission_id;
		submissionId = createSubmissionId();
		writeStored(values);
	}
	document.addEventListener('DOMContentLoaded', populateAll);
	document.addEventListener('submit', function (event) {
		if (event.target && event.target.closest('.wpcf7')) populate(event.target, collect());
	}, true);
	document.addEventListener('wpcf7beforesubmit', function (event) { populate(event.target, collect()); });
	document.addEventListener('wpcf7mailsent', completeSubmission);
	document.addEventListener('wp_listen_for_consent_change', populateAll);
}());
