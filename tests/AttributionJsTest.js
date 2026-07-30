'use strict';

const assert = require('assert');
const path = require('path');

const listeners = {};
const stored = new Map();
let cookie = '';

global.window = {
	cf7aivAttribution: { cookieName: 'cf7aiv_attribution', retentionDays: 90, fields: {} },
	dataLayer: [],
	crypto: global.crypto,
};
global.location = {
	search: '?gclid=TESTCLICK123&utm_source=paid-search&utm_campaign=care',
	href: 'https://example.test/landing/?gclid=TESTCLICK123&utm_source=paid-search&utm_campaign=care',
	protocol: 'https:',
};
global.localStorage = {
	getItem: (key) => stored.get(key) || null,
	setItem: (key, value) => stored.set(key, value),
};
global.document = {
	referrer: 'https://search.example/',
	addEventListener: (name, callback) => { listeners[name] = callback; },
	querySelectorAll: () => [],
};
Object.defineProperty(global.document, 'cookie', {
	get: () => cookie,
	set: (value) => { cookie = value; },
});

require(path.join(__dirname, '..', 'assets', 'js', 'attribution.js'));
assert.strictEqual(typeof listeners.DOMContentLoaded, 'function', 'collector must register DOMContentLoaded');
listeners.DOMContentLoaded();

const raw = stored.get('cf7aiv_attribution');
assert.ok(raw, 'form-free landing page must persist attribution to localStorage');
const payload = JSON.parse(raw);
assert.strictEqual(payload.gclid, 'TESTCLICK123');
assert.strictEqual(payload.utm_source, 'paid-search');
assert.strictEqual(payload.first_landing_page, global.location.href);
assert.strictEqual(payload.current_page, global.location.href);
assert.ok(typeof payload.submission_id === 'string' && payload.submission_id.length > 10);
assert.ok(cookie.startsWith('cf7aiv_attribution='), 'form-free landing page must also refresh the attribution cookie');

console.log('PASS AttributionJsTest');
