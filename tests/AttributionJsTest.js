'use strict';

const path = require('path');
const script = path.join(__dirname, '..', 'assets', 'js', 'attribution.js');
const failures = [];

function check(condition, message) {
	if (!condition) failures.push(message);
}

function makeHarness(options = {}) {
	const listeners = {};
	const stored = new Map(Object.entries(options.storage || {}));
	const cookies = new Map(Object.entries(options.cookies || {}));
	const inputs = {};
	Object.keys(options.fields || {}).forEach((field) => { inputs[field] = { value: '' }; });
	const form = {
		querySelector: (selector) => {
			const match = selector.match(/^\[name="(.+)"\]$/);
			return match ? inputs[match[1]] || null : null;
		},
		closest: (selector) => selector === '.wpcf7' ? {} : null,
	};
	const pluginName = 'cf7aiv_attribution';
	let pluginWrites = 0;

	global.window = {
		cf7aivAttribution: { cookieName: pluginName, retentionDays: 90, fields: options.fields || {} },
		dataLayer: options.dataLayer || [],
		crypto: global.crypto,
	};
	global.location = {
		search: options.search || '?gclid=TESTCLICK123&gbraid=GBRAID123&wbraid=WBRAID123&msclkid=0123456789abcdef0123456789abcdef&utm_source=paid-search&utm_campaign=care',
		href: options.href || 'https://example.test/landing/?gclid=TESTCLICK123&gbraid=GBRAID123&wbraid=WBRAID123&msclkid=0123456789abcdef0123456789abcdef&utm_source=paid-search&utm_campaign=care',
		protocol: 'https:',
	};
	global.localStorage = {
		getItem: (key) => stored.has(key) ? stored.get(key) : null,
		setItem: (key, value) => { if (key === pluginName) pluginWrites++; stored.set(key, value); },
		removeItem: (key) => stored.delete(key),
	};
	global.document = {
		referrer: 'https://search.example/results',
		addEventListener: (name, callback) => { (listeners[name] ||= []).push(callback); },
		querySelectorAll: (selector) => selector === '.wpcf7 form' ? [form] : [],
	};
	Object.defineProperty(global.document, 'cookie', {
		get: () => [...cookies.entries()].map(([key, value]) => key + '=' + value).join('; '),
		set: (value) => {
			const [pair, ...attributes] = value.split(';');
			const separator = pair.indexOf('=');
			const key = pair.slice(0, separator);
			const cookieValue = pair.slice(separator + 1);
			if (attributes.some((attribute) => /^max-age=0$/i.test(attribute.trim()))) cookies.delete(key);
			else cookies.set(key, cookieValue);
		},
	});

	delete require.cache[require.resolve(script)];
	require(script);
	return {
		inputs,
		payload: () => stored.has(pluginName) ? JSON.parse(stored.get(pluginName)) : null,
		hasCookie: () => cookies.has(pluginName),
		pluginWrites: () => pluginWrites,
		emit: (name, event = {}) => (listeners[name] || []).forEach((listener) => listener(event)),
	};
}

function consent(statistics, marketing) {
	return [['consent', 'update', {
		analytics_storage: statistics ? 'granted' : 'denied',
		ad_storage: marketing ? 'granted' : 'denied',
		ad_user_data: marketing ? 'granted' : 'denied',
		ad_personalization: marketing ? 'granted' : 'denied',
	}]];
}

const fields = {
	cf7aiv_submission_id: 'submission_id',
	cf7aiv_gclid: 'gclid',
	cf7aiv_utm_source: 'utm_source',
};

// A collector that writes before a granted consent branch must fail this case.
{
	const page = makeHarness({ fields });
	page.emit('DOMContentLoaded');
	check(page.payload() === null && !page.hasCookie(), 'no consent signal must not persist attribution in localStorage or the plugin cookie');
	check(Object.values(page.inputs).every((input) => input.value === ''), 'no consent signal must leave injected attribution fields empty');
}

// A collector that reuses stale storage after a denied branch must fail this case.
{
	const stale = JSON.stringify({ gclid: 'STALECLICK123', utm_source: 'stale-source', submission_id: 'stale-submission' });
	const page = makeHarness({ storage: { cf7aiv_attribution: stale }, cookies: { cf7aiv_attribution: encodeURIComponent(stale) }, dataLayer: consent(false, false), fields });
	page.emit('DOMContentLoaded');
	check(page.payload() === null && !page.hasCookie(), 'explicit denial must delete stale plugin attribution storage');
	check(Object.values(page.inputs).every((input) => input.value === ''), 'explicit denial must clear injected attribution fields');
}

// A collector that treats analytics consent as advertising consent must fail these checks.
{
	const page = makeHarness({
		dataLayer: consent(true, false),
		cookies: { _ga: 'GA1.1.123456789.1234567890', _ga_CONTAINER: 'GS1.1.s1234567890.o1' },
		storage: { cf7aiv_attribution: JSON.stringify({ gclid: 'STALECLICK123', gbraid: 'STALEGBRAID123', wbraid: 'STALEWBRAID123', msclkid: 'fedcba9876543210fedcba9876543210', utm_source: 'stale-source', first_landing_page: 'https://example.test/?gclid=STALECLICK123', current_page: 'https://example.test/?gbraid=STALEGBRAID123', referrer: 'https://search.example/results' }) },
		fields,
	});
	page.emit('DOMContentLoaded');
	const payload = page.payload() || {};
	check(payload.utm_source === 'paid-search' && payload.first_landing_page === 'https://example.test/landing/?utm_source=paid-search&utm_campaign=care' && payload.current_page === 'https://example.test/landing/?utm_source=paid-search&utm_campaign=care' && payload.referrer === 'https://search.example/results', 'statistics consent must retain UTM, referrer, and click-ID-sanitized landing URLs');
	check(payload.ga_client_id === '123456789.1234567890' && payload.ga_session_id === '1234567890', 'statistics consent must retain GA client and session identifiers');
	check(!('gclid' in payload) && !('gbraid' in payload) && !('wbraid' in payload) && !('msclkid' in payload), 'statistics-only consent must exclude advertising click identifiers');
}

{
	const stale = JSON.stringify({ gclid: 'STALECLICK123', utm_source: 'stale-source', ga_client_id: '123456789.1234567890', first_landing_page: 'https://example.test/?utm_source=stale-source', current_page: 'https://example.test/?utm_source=stale-source', referrer: 'https://search.example/results' });
	const page = makeHarness({ dataLayer: consent(false, true), storage: { cf7aiv_attribution: stale }, cookies: { cf7aiv_attribution: encodeURIComponent(stale) }, fields });
	page.emit('DOMContentLoaded');
	const payload = page.payload() || {};
	check(payload.gclid === 'TESTCLICK123', 'marketing consent must retain valid advertising click identifiers.');
	check(!('utm_source' in payload) && !('utm_campaign' in payload) && !('ga_client_id' in payload) && !('first_landing_page' in payload) && !('current_page' in payload) && !('referrer' in payload), 'marketing-only consent must remove stale analytics and navigation attribution.');
}

{
	const page = makeHarness({ dataLayer: consent(true, true), fields });
	page.emit('DOMContentLoaded');
	const payload = page.payload() || {};
	check(page.hasCookie() && payload.gclid === 'TESTCLICK123' && payload.gbraid === 'GBRAID123' && payload.utm_source === 'paid-search', 'marketing plus statistics consent must persist valid attribution');
	check(page.inputs.cf7aiv_gclid.value === 'TESTCLICK123' && page.inputs.cf7aiv_utm_source.value === 'paid-search' && page.inputs.cf7aiv_submission_id.value === payload.submission_id, 'granted attribution must populate injected fields');
}

{
	const page = makeHarness({ fields });
	page.emit('DOMContentLoaded');
	check(page.payload() === null, 'consent granted after load must begin with no pre-consent storage');
	window.dataLayer.push(...consent(true, true));
	page.emit('wp_listen_for_consent_change', { detail: { statistics: 'allow', marketing: 'allow' } });
	const payload = page.payload() || {};
	check(payload.gclid === 'TESTCLICK123' && page.pluginWrites() === 1, 'consent granted after load must collect attribution exactly once');
}

{
	const page = makeHarness({ dataLayer: consent(true, true), fields });
	page.emit('DOMContentLoaded');
	window.dataLayer.push(...consent(false, false));
	page.emit('wp_listen_for_consent_change', { detail: { statistics: 'deny', marketing: 'deny' } });
	check(page.payload() === null && !page.hasCookie(), 'consent withdrawal must remove disallowed attribution storage');
	check(Object.values(page.inputs).every((input) => input.value === ''), 'consent withdrawal must clear hidden attribution fields');
}

{
	const page = makeHarness({ dataLayer: consent(true, true), fields });
	page.emit('DOMContentLoaded');
	const first = page.payload().submission_id;
	page.emit('wp_listen_for_consent_change', { detail: { statistics: 'allow', marketing: 'allow' } });
	page.emit('submit', { target: formTarget(page) });
	page.emit('wpcf7beforesubmit', { target: formTarget(page) });
	check(page.payload().submission_id === first, 'repeated consent and CF7 submit hooks must not create a second submission ID before wpcf7mailsent');
	page.emit('wpcf7mailsent');
	check(!('submission_id' in page.payload()), 'wpcf7mailsent must clear the completed submission ID');
}

function formTarget(page) {
	return { closest: (selector) => selector === '.wpcf7' ? {} : null, querySelector: (selector) => {
		const match = selector.match(/^\[name="(.+)"\]$/);
		return match ? page.inputs[match[1]] || null : null;
	} };
}

if (failures.length) {
	throw new Error('Consent-safe attribution regressions:\n- ' + failures.join('\n- '));
}

console.log('PASS AttributionJsTest');
