(function () {
	'use strict';
	var cfg = window.cf7aivAttribution || {};
	var clickKeys = ['gclid', 'gbraid', 'wbraid', 'msclkid'];
	var consentKeys = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];

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
		try { raw = localStorage.getItem(cfg.cookieName) || ''; } catch (e) {}
		if (!raw) raw = readCookie(cfg.cookieName || 'cf7aiv_attribution');
		try { return JSON.parse(raw) || {}; } catch (e) { return {}; }
	}
	function writeStored(value) {
		var raw = JSON.stringify(value);
		try { localStorage.setItem(cfg.cookieName, raw); } catch (e) {}
		var age = Math.max(1, Number(cfg.retentionDays || 90)) * 86400;
		document.cookie = (cfg.cookieName || 'cf7aiv_attribution') + '=' + encodeURIComponent(raw) + ';path=/;max-age=' + age + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
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
	function captureConsent(target) {
		(window.dataLayer || []).forEach(function (entry) {
			var row = Array.prototype.slice.call(entry || []);
			if (row[0] === 'consent' && (row[1] === 'default' || row[1] === 'update') && row[2]) {
				consentKeys.forEach(function (key) { if (row[2][key] === 'granted' || row[2][key] === 'denied') target[key] = row[2][key]; });
			}
		});
	}
	function collect() {
		var stored = readStored();
		var params = new URLSearchParams(location.search);
		clickKeys.forEach(function (key) {
			var fresh = validClick(key, params.get(key) || '');
			if (!fresh && key === 'gclid') fresh = validClick(key, params.get('zc_gad') || '');
			if (fresh) stored[key] = fresh;
			else if (!validClick(key, stored[key] || '')) delete stored[key];
		});
		['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'].forEach(function (key) {
			if (params.has(key)) stored[key] = (params.get(key) || '').slice(0, 512);
		});
		if (!stored.first_landing_page) stored.first_landing_page = location.href.slice(0, 2048);
		stored.current_page = location.href.slice(0, 2048);
		if (!stored.referrer && document.referrer) stored.referrer = document.referrer.slice(0, 2048);
		Object.assign(stored, gaIdentity());
		captureConsent(stored);
		if (!stored.submission_id) stored.submission_id = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2);
		writeStored(stored);
		return stored;
	}
	function populate(root, values) {
		values = values || collect();
		Object.keys(cfg.fields || {}).forEach(function (field) {
			var input = root.querySelector('[name="' + field + '"]');
			if (input) input.value = values[cfg.fields[field]] || '';
		});
	}
	function populateAll() {
		var values = collect();
		document.querySelectorAll('.wpcf7 form').forEach(function (form) { populate(form, values); });
	}
	document.addEventListener('DOMContentLoaded', populateAll);
	document.addEventListener('submit', function (event) { if (event.target && event.target.closest('.wpcf7')) populate(event.target); }, true);
	document.addEventListener('wpcf7beforesubmit', function (event) { populate(event.target); });
	document.addEventListener('wpcf7mailsent', function () {
		var values = readStored(); delete values.submission_id; writeStored(values);
	});
}());
