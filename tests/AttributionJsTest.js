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
	if (typeof options.wpHasConsent === 'function') window.wp_has_consent = options.wpHasConsent;
	if (Object.prototype.hasOwnProperty.call(options, 'wpConsentType')) window.wp_get_consent_type = () => options.wpConsentType;
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
		referrer: Object.prototype.hasOwnProperty.call(options, 'referrer') ? options.referrer : 'https://search.example/results',
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
	cf7aiv_ad_storage: 'ad_storage',
	cf7aiv_analytics_storage: 'analytics_storage',
	cf7aiv_ad_user_data: 'ad_user_data',
	cf7aiv_ad_personalization: 'ad_personalization',
};

// A collector that writes before a granted consent branch must fail this case.
{
	const page = makeHarness({ fields });
	page.emit('DOMContentLoaded');
	check(page.payload() === null && !page.hasCookie(), 'no consent signal must not persist attribution in localStorage or the plugin cookie');
	check(page.inputs.cf7aiv_submission_id.value !== '', 'no consent signal must still inject an ephemeral operational source-link token');
	check(trackingFieldsEmpty(page), 'no consent signal must leave tracking attribution fields empty');
}

// wp_has_consent is permissive when the Consent API has no configured type;
// function presence alone must not authorize attribution storage without a
// known Google Consent Mode fallback.
{
	const page = makeHarness({
		wpHasConsent: () => true,
		fields,
	});
	page.emit('DOMContentLoaded');
	check(page.payload() === null && !page.hasCookie(), 'an installed WordPress Consent API with no known consent type must not persist attribution');
}

{
	const page = makeHarness({
		wpHasConsent: () => true,
		dataLayer: consent(true, true),
		fields,
	});
	page.emit('DOMContentLoaded');
	const payload = page.payload() || {};
	check(payload.gclid === 'TESTCLICK123' && payload.utm_source === 'paid-search', 'known Google consent must remain the fallback when WordPress consent authority is unqualified');
}

{
	const page = makeHarness({
		wpConsentType: 'optout',
		wpHasConsent: () => true,
		fields,
	});
	page.emit('DOMContentLoaded');
	check(page.payload() === null && !page.hasCookie(), 'WordPress opt-out defaults must not authorize strict opt-in attribution storage');
}

// A collector that reuses stale storage after a denied branch must fail this case.
{
	const stale = JSON.stringify({ gclid: 'STALECLICK123', utm_source: 'stale-source', submission_id: 'stale-submission' });
	const page = makeHarness({ storage: { cf7aiv_attribution: stale }, cookies: { cf7aiv_attribution: encodeURIComponent(stale) }, dataLayer: consent(false, false), fields });
	page.emit('DOMContentLoaded');
	check(page.payload() === null && !page.hasCookie(), 'explicit denial must delete stale plugin attribution storage');
	check(page.inputs.cf7aiv_submission_id.value !== '' && page.inputs.cf7aiv_submission_id.value !== 'stale-submission', 'explicit denial must replace any stale stored source-link token with a fresh ephemeral token');
	check(trackingFieldsEmpty(page), 'explicit denial must clear injected tracking attribution fields');
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
	const page = makeHarness({
		dataLayer: consent(true, false),
		search: '?GCLID=TESTCLICK123&%67braid=GBRAID123&utm_source=paid%20search',
		href: 'https://example.test/landing/?GCLID=TESTCLICK123&%67braid=GBRAID123&utm_source=paid%20search#step?WbRaId=WBRAID123&view=details',
		referrer: 'https://search.example/results?%6Dsclkid=0123456789abcdef0123456789abcdef&q=care#results?ZC_GAD=TESTCLICK123&page=2',
		fields,
	});
	page.emit('DOMContentLoaded');
	const payload = page.payload() || {};
	check(payload.current_page === 'https://example.test/landing/?utm_source=paid%20search#step?view=details', 'statistics URLs must remove mixed-case and encoded click-ID names from queries and query-like fragments');
	check(payload.referrer === 'https://search.example/results?q=care#results?page=2', 'statistics referrers must remove encoded click-ID names and query-like fragment identifiers');
}

{
	const stale = JSON.stringify({
		first_landing_page: 'not a valid absolute URL',
		referrer: 'https://search.example/results?utm_source=%E0%A4%A',
	});
	const page = makeHarness({
		dataLayer: consent(true, false),
		storage: { cf7aiv_attribution: stale },
		referrer: '',
		href: 'https://example.test/safe/?utm_source=care',
		search: '?utm_source=care',
		fields,
	});
	page.emit('DOMContentLoaded');
	const payload = page.payload() || {};
	check(payload.first_landing_page === 'https://example.test/safe/?utm_source=care', 'unsafe stored landing URLs must be dropped instead of surviving consent filtering');
	check(!('referrer' in payload), 'stored URLs with unsafe percent decoding must be dropped entirely');
}

{
	const stale = JSON.stringify({ first_landing_page: 'https://example%ZZ.test/path?utm_source=care' });
	const page = makeHarness({
		dataLayer: consent(true, false),
		storage: { cf7aiv_attribution: stale },
		referrer: '',
		href: 'https://example.test/safe/?utm_source=care',
		search: '?utm_source=care',
		fields,
	});
	page.emit('DOMContentLoaded');
	check((page.payload() || {}).first_landing_page === 'https://example.test/safe/?utm_source=care', 'JavaScript must reject the same unsafe hostname encoding as the PHP sanitizer');
}

{
	const page = makeHarness({
		dataLayer: consent(true, false),
		cookies: { cf7aiv_attribution: '%E0%A4%A' },
		fields,
	});
	let threw = false;
	try { page.emit('DOMContentLoaded'); } catch (error) { threw = true; }
	check(!threw && page.payload() !== null, 'malformed plugin cookie encoding must be treated as absent without aborting collection');
}

foreachMalformedGaCookie([
	{ _ga: '%E0%A4%A' },
	{ _ga_CONTAINER: '%E0%A4%A' },
]);

function foreachMalformedGaCookie(cases) {
	cases.forEach((cookies) => {
		const page = makeHarness({ dataLayer: consent(true, false), cookies, fields });
		let threw = false;
		try { page.emit('DOMContentLoaded'); } catch (error) { threw = true; }
		check(!threw && page.payload() !== null, 'malformed GA cookie encoding must be treated as absent without aborting collection');
	});
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

// When the configured WordPress Consent API is authoritative, stale Google
// Consent Mode values must not replace the effective consent injected into CF7.
{
	const page = makeHarness({
		wpConsentType: 'optin',
		wpHasConsent: () => true,
		dataLayer: consent(false, false),
		fields,
	});
	page.emit('DOMContentLoaded');
	const payload = page.payload() || {};
	check(
		payload.ad_storage === 'granted' && payload.analytics_storage === 'granted'
			&& payload.ad_user_data === 'granted' && payload.ad_personalization === 'granted',
		'configured WordPress consent grants must be the effective stored consent when Google Consent Mode is stale'
	);
	check(
		page.inputs.cf7aiv_ad_storage.value === 'granted' && page.inputs.cf7aiv_analytics_storage.value === 'granted'
			&& page.inputs.cf7aiv_ad_user_data.value === 'granted' && page.inputs.cf7aiv_ad_personalization.value === 'granted',
		'configured WordPress consent grants must be injected into CF7 instead of stale Google denial'
	);
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
	check(page.inputs.cf7aiv_submission_id.value !== '', 'consent withdrawal must preserve an ephemeral operational source-link token');
	check(trackingFieldsEmpty(page), 'consent withdrawal must clear hidden tracking attribution fields');
}

{
	const page = makeHarness({ fields });
	page.emit('DOMContentLoaded');
	const first = page.inputs.cf7aiv_submission_id.value;
	page.emit('submit', { target: formTarget(page) });
	page.emit('wpcf7beforesubmit', { target: formTarget(page) });
	check(page.inputs.cf7aiv_submission_id.value === first, 'repeated no-consent submit hooks must keep one operational source-link token for the submission');
	page.emit('wpcf7mailsent');
	page.emit('wpcf7beforesubmit', { target: formTarget(page) });
	check(page.inputs.cf7aiv_submission_id.value !== '' && page.inputs.cf7aiv_submission_id.value !== first, 'a completed no-consent submission must rotate its operational source-link token before the next submission');
	check(page.payload() === null && !page.hasCookie() && trackingFieldsEmpty(page), 'no-consent token rotation must not create attribution storage or populate tracking fields');
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

function trackingFieldsEmpty(page) {
	return Object.entries(page.inputs).every(([name, input]) => name === 'cf7aiv_submission_id' || input.value === '');
}

if (failures.length) {
	throw new Error('Consent-safe attribution regressions:\n- ' + failures.join('\n- '));
}

console.log('PASS AttributionJsTest');
