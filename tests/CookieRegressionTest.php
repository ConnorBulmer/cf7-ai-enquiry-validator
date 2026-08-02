<?php

declare(strict_types=1);

namespace CF7AIV\Intake {
	function headers_sent(): bool { return false; }
	function setcookie(string $name, string $value, array $options = []): bool {
		$GLOBALS['cookie_write'] = compact('name', 'value', 'options');
		return true;
	}
}

namespace {
	define('ABSPATH', __DIR__ . '/');
	define('DAY_IN_SECONDS', 86400);
	function get_option(string $name, mixed $default = false): mixed { return ['attribution' => ['retention_days' => 90]]; }
	function is_ssl(): bool { return true; }
	require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

	function cookie_assert(bool $condition, string $message): void {
		if (! $condition) { throw new RuntimeException($message); }
	}
	$consentFailures = [];
	function consent_assert(bool $condition, string $message): void {
		if (! $condition) { $GLOBALS['consentFailures'][] = $message; }
	}
	function consent_navigation(array $query, array $cookies = [], array $serverOverrides = []): void {
		unset($GLOBALS['cookie_write']);
		$_COOKIE = $cookies;
		$_GET = $query;
		$_SERVER = array_replace([
			'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test',
			'REQUEST_URI' => '/contact/?' . http_build_query($query),
			'HTTP_ACCEPT' => 'text/html', 'HTTP_SEC_FETCH_MODE' => 'navigate',
			'HTTP_SEC_FETCH_DEST' => 'document', 'HTTPS' => 'on',
			'HTTP_REFERER' => 'https://search.example/results',
		], $serverOverrides);
		CF7AIV\Intake\AttributionCookie::serverFallback();
	}
	function consent_payload(): array {
		$write = $GLOBALS['cookie_write'] ?? null;
		return is_array($write) ? (json_decode($write['value'] ?? '', true) ?: []) : [];
	}
	function consent_cookie_expired(): bool {
		$write = $GLOBALS['cookie_write'] ?? null;
		return is_array($write)
			&& ($write['name'] ?? '') === CF7AIV\Intake\AttributionCookie::NAME
			&& ($write['value'] ?? null) === ''
			&& (int) ($write['options']['expires'] ?? 0) < time()
			&& ($write['options']['path'] ?? '') === '/'
			&& ($write['options']['secure'] ?? null) === true
			&& ($write['options']['httponly'] ?? null) === false
			&& ($write['options']['samesite'] ?? '') === 'Lax';
	}

	// This runs before the WordPress Consent API is made available below: no
	// consent signal is an opt-out, rather than permission to use a fallback.
	consent_navigation(['gclid' => 'fresh_GOOGLE-123', 'utm_source' => 'fresh-source']);
	consent_assert(! isset($GLOBALS['cookie_write']), 'Unknown consent must not write the server fallback attribution cookie.');

	if (! function_exists('wp_has_consent')) {
		function wp_has_consent(string $category): bool {
			return (bool) ($GLOBALS['consent_categories'][$category] ?? false);
		}
	}
	if (! function_exists('wp_get_consent_type')) {
		function wp_get_consent_type(): mixed {
			return $GLOBALS['consent_type'] ?? false;
		}
	}
	$GLOBALS['consent_type'] = false;
	$GLOBALS['consent_categories'] = ['statistics' => true, 'marketing' => true];
	consent_navigation(['gclid' => 'fresh_GOOGLE-123', 'utm_source' => 'fresh-source']);
	consent_assert(! isset($GLOBALS['cookie_write']), 'A permissive WordPress Consent API with no known consent type must not write the server fallback cookie.');
	$gcmFallbackCookie = json_encode([
		'analytics_storage' => 'granted', 'ad_storage' => 'denied',
		'utm_source' => 'stored-gcm-source',
	]);
	consent_navigation([], [CF7AIV\Intake\AttributionCookie::NAME => $gcmFallbackCookie]);
	consent_assert((consent_payload()['utm_source'] ?? '') === 'stored-gcm-source', 'Known stored Google consent must remain the server fallback when WordPress authority is unqualified.');

	$GLOBALS['consent_type'] = 'optout';
	$GLOBALS['consent_categories'] = ['statistics' => true, 'marketing' => true];
	consent_navigation(['gclid' => 'fresh_GOOGLE-123', 'utm_source' => 'fresh-source']);
	consent_assert(! isset($GLOBALS['cookie_write']), 'WordPress opt-out defaults must not authorize strict opt-in server attribution storage.');

	$GLOBALS['consent_type'] = 'optin';
	$GLOBALS['consent_categories'] = ['statistics' => true, 'marketing' => true];

	$_COOKIE[CF7AIV\Intake\AttributionCookie::NAME] = json_encode([
		'gclid' => 'persisted_GOOGLE-456',
		'msclkid' => '0123456789abcdef0123456789abcdef',
		'utm_source' => 'persisted-source',
		'first_landing_page' => 'https://example.test/original-landing',
	]);
	$_GET = [
		'gclid' => 'fresh_GOOGLE-123',
		'msclkid' => 'invalid',
		'utm_source' => 'fresh-source',
		'utm_campaign' => 'summer-care',
	];
	$_SERVER['HTTP_HOST'] = 'example.test';
	$_SERVER['REQUEST_URI'] = '/contact/?gclid=fresh_GOOGLE-123&utm_source=fresh-source';
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['HTTP_REFERER'] = 'https://search.example/results';

	$purePayload = CF7AIV\Intake\AttributionCookie::buildServerValues($_GET, $_COOKIE, $_SERVER);
	cookie_assert($purePayload['gclid'] === 'fresh_GOOGLE-123' && $purePayload['utm_source'] === 'fresh-source', 'The pure fallback helper must merge validated fresh attribution over stored values.');
	cookie_assert($purePayload['current_page'] === 'https://example.test/contact/?gclid=fresh_GOOGLE-123&utm_source=fresh-source', 'The pure fallback helper must derive the current URL.');
	$pureJson = CF7AIV\Intake\AttributionCookie::rawCookieJson($purePayload);
	cookie_assert(str_starts_with($pureJson, '{') && json_decode($pureJson, true) === $purePayload, 'The raw cookie helper must return directly parseable, unencoded JSON.');

	CF7AIV\Intake\AttributionCookie::serverFallback();
	$write = $GLOBALS['cookie_write'] ?? null;
	cookie_assert(is_array($write), 'Valid attribution or UTM input must write the server fallback cookie.');
	cookie_assert(str_starts_with($write['value'], '{'), 'setcookie must receive raw JSON rather than pre-encoded JSON.');
	$payload = json_decode($write['value'], true);
	cookie_assert(is_array($payload), 'The cookie must parse after a single browser decode.');
	cookie_assert($payload['gclid'] === 'fresh_GOOGLE-123', 'Fresh valid click IDs must override persisted values.');
	cookie_assert($payload['msclkid'] === '0123456789abcdef0123456789abcdef', 'Invalid fresh click IDs must not erase valid persisted values.');
	cookie_assert($payload['utm_source'] === 'fresh-source' && $payload['utm_campaign'] === 'summer-care', 'Fresh UTMs must override and extend persisted UTMs.');
	cookie_assert($payload['first_landing_page'] === 'https://example.test/original-landing', 'The first landing page must remain stable once captured.');
	cookie_assert($payload['current_page'] === 'https://example.test/contact/?gclid=fresh_GOOGLE-123&utm_source=fresh-source', 'The fallback must capture the current server URL.');
	cookie_assert($payload['referrer'] === 'https://search.example/results', 'The fallback must capture the HTTP referrer.');

	unset($GLOBALS['cookie_write']);
	$_COOKIE = [];
	$_GET = ['utm_medium' => 'organic'];
	CF7AIV\Intake\AttributionCookie::serverFallback();
	cookie_assert(isset($GLOBALS['cookie_write']), 'UTM-only visits must still create a server fallback cookie.');

	$paidLandingCookie = $GLOBALS['cookie_write']['value'];
	unset($GLOBALS['cookie_write']);
	$_COOKIE[CF7AIV\Intake\AttributionCookie::NAME] = $paidLandingCookie;
	$_GET = [];
	$_SERVER = [
		'REQUEST_METHOD' => 'GET',
		'HTTP_HOST' => 'example.test',
		'REQUEST_URI' => '/wp-content/uploads/2026/07/site-icon.webp',
		'HTTP_ACCEPT' => 'image/avif,image/webp,image/png,*/*;q=0.8',
		'HTTP_SEC_FETCH_MODE' => 'no-cors',
		'HTTP_SEC_FETCH_DEST' => 'image',
		'HTTP_REFERER' => 'https://example.test/contact/?gclid=fresh_GOOGLE-123&utm_source=fresh-source',
		'HTTPS' => 'on',
	];
	cookie_assert(! CF7AIV\Intake\AttributionCookie::isNavigationRequest($_SERVER), 'Static image requests must never be treated as HTML navigation.');
	CF7AIV\Intake\AttributionCookie::serverFallback();
	cookie_assert(! isset($GLOBALS['cookie_write']), 'A parallel static asset request must not overwrite the paid landing attribution cookie.');
	cookie_assert($_COOKIE[CF7AIV\Intake\AttributionCookie::NAME] === $paidLandingCookie, 'Rejected asset requests must preserve the fresh landing cookie unchanged.');

	foreach ([
		['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact/', 'HTTP_ACCEPT' => 'text/html'],
		['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-json/wp/v2/pages', 'HTTP_ACCEPT' => 'application/json'],
		['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/feed/', 'HTTP_ACCEPT' => 'application/rss+xml'],
		['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/contact/', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SEC_FETCH_MODE' => 'cors'],
	] as $nonNavigation) {
		cookie_assert(! CF7AIV\Intake\AttributionCookie::isNavigationRequest($nonNavigation), 'POST, REST, feed, and non-navigation fetch requests must be rejected.');
	}
	cookie_assert(CF7AIV\Intake\AttributionCookie::isNavigationRequest([
		'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/care-home/?utm_source=test',
		'HTTP_ACCEPT' => 'text/html,application/xhtml+xml', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document',
	]), 'A real HTML document navigation must remain eligible for server fallback attribution.');

	$GLOBALS['consent_categories'] = ['statistics' => false, 'marketing' => false];
	consent_navigation(['gclid' => 'fresh_GOOGLE-123', 'utm_source' => 'fresh-source']);
	consent_assert(! isset($GLOBALS['cookie_write']), 'Denied consent must not write the server fallback attribution cookie.');
	consent_navigation(
		['gclid' => 'fresh_GOOGLE-123', 'utm_source' => 'fresh-source'],
		[CF7AIV\Intake\AttributionCookie::NAME => json_encode(['gclid' => 'stale_CLICK-123', 'utm_source' => 'stale-source'])]
	);
	consent_assert(consent_cookie_expired(), 'Denied consent must expire an existing attribution cookie with matching cookie attributes.');

	$GLOBALS['consent_categories'] = ['statistics' => true, 'marketing' => false];
	$staleFullConsent = json_encode(['gclid' => 'persisted_GOOGLE-456', 'gbraid' => 'PERSISTEDGBRAID123', 'wbraid' => 'PERSISTEDWBRAID123', 'msclkid' => 'fedcba9876543210fedcba9876543210', 'utm_source' => 'persisted-source', 'first_landing_page' => 'https://example.test/?gclid=persisted_GOOGLE-456', 'current_page' => 'https://example.test/?gbraid=PERSISTEDGBRAID123', 'referrer' => 'https://search.example/results']);
	consent_navigation(['gclid' => 'fresh_GOOGLE-123', 'gbraid' => 'GBRAID123', 'wbraid' => 'WBRAID123', 'msclkid' => '0123456789abcdef0123456789abcdef', 'utm_source' => 'fresh-source', 'utm_campaign' => 'summer-care'], [CF7AIV\Intake\AttributionCookie::NAME => $staleFullConsent]);
	$statisticsPayload = consent_payload();
	consent_assert(($statisticsPayload['utm_source'] ?? '') === 'fresh-source', 'Statistics consent must retain UTM attribution.');
	consent_assert(($statisticsPayload['first_landing_page'] ?? '') === 'https://example.test/contact/?utm_source=fresh-source&utm_campaign=summer-care' && ($statisticsPayload['current_page'] ?? '') === 'https://example.test/contact/?utm_source=fresh-source&utm_campaign=summer-care', 'Statistics consent must retain click-ID-sanitized landing and current URLs.');
	consent_assert(! isset($statisticsPayload['gclid']) && ! isset($statisticsPayload['gbraid']) && ! isset($statisticsPayload['wbraid']) && ! isset($statisticsPayload['msclkid']), 'Statistics-only consent must strip advertising click identifiers.');
	consent_navigation(
		['utm_source' => 'paid search'],
		[],
		[
			'REQUEST_URI' => '/contact/?GCLID=%43LICK123&%67braid=GBRAID123&utm_source=paid%20search#step?WbRaId=WBRAID123&view=details',
			'HTTP_REFERER' => 'https://search.example/results?%6Dsclkid=0123456789abcdef0123456789abcdef&q=care#results?ZC_GAD=CLICK123&page=2',
		]
	);
	$sanitizedPayload = consent_payload();
	consent_assert(($sanitizedPayload['current_page'] ?? '') === 'https://example.test/contact/?utm_source=paid%20search#step?view=details', 'Server statistics URLs must remove mixed-case and encoded click-ID names from queries and query-like fragments.');
	consent_assert(($sanitizedPayload['referrer'] ?? '') === 'https://search.example/results?q=care#results?page=2', 'Server referrers must remove encoded click-ID names and query-like fragment identifiers.');
	$unsafeStored = json_encode([
		'first_landing_page' => 'not a valid absolute URL',
		'referrer' => 'https://search.example/results?utm_source=%E0%A4%A',
	]);
	consent_navigation(
		['utm_source' => 'care'],
		[CF7AIV\Intake\AttributionCookie::NAME => $unsafeStored],
		['HTTP_REFERER' => null]
	);
	$unsafeFiltered = consent_payload();
	consent_assert(($unsafeFiltered['first_landing_page'] ?? '') === 'https://example.test/contact/?utm_source=care', 'Unsafe stored landing URLs must be dropped and replaced with the safe current landing.');
	consent_assert(! isset($unsafeFiltered['referrer']), 'Stored URLs with unsafe percent decoding must be dropped entirely by the server sanitizer.');
	consent_navigation(
		['utm_source' => 'care'],
		[CF7AIV\Intake\AttributionCookie::NAME => json_encode(['first_landing_page' => 'https://example%ZZ.test/path?utm_source=care'])],
		['HTTP_REFERER' => null]
	);
	$unsafeHostFiltered = consent_payload();
	consent_assert(($unsafeHostFiltered['first_landing_page'] ?? '') === 'https://example.test/contact/?utm_source=care', 'Stored URLs with unsafe hostname encoding must be dropped instead of surviving PHP sanitization.');
	consent_navigation(
		[],
		[CF7AIV\Intake\AttributionCookie::NAME => json_encode(['gclid' => 'stale_CLICK-123'])],
		['HTTP_HOST' => null, 'HTTP_REFERER' => null]
	);
	consent_assert(consent_cookie_expired(), 'Statistics-only downgrade filtering to no values must expire the stale attribution cookie.');

	$GLOBALS['consent_categories'] = ['statistics' => false, 'marketing' => true];
	consent_navigation(['gclid' => 'fresh_GOOGLE-123', 'utm_source' => 'fresh-source', 'utm_campaign' => 'summer-care'], [CF7AIV\Intake\AttributionCookie::NAME => $staleFullConsent]);
	$marketingPayload = consent_payload();
	consent_assert(($marketingPayload['gclid'] ?? '') === 'fresh_GOOGLE-123', 'Marketing consent must retain valid advertising click identifiers.');
	consent_assert(! isset($marketingPayload['utm_source'], $marketingPayload['utm_campaign'], $marketingPayload['first_landing_page'], $marketingPayload['current_page'], $marketingPayload['referrer']), 'Marketing-only consent must strip analytics and navigation attribution.');
	consent_navigation(
		[],
		[CF7AIV\Intake\AttributionCookie::NAME => json_encode(['utm_source' => 'stale-source'])],
		['HTTP_HOST' => null, 'HTTP_REFERER' => null]
	);
	consent_assert(consent_cookie_expired(), 'Marketing-only downgrade filtering to no values must expire the stale attribution cookie.');

	$GLOBALS['consent_categories'] = ['statistics' => true, 'marketing' => true];
	consent_navigation(['gclid' => 'fresh_GOOGLE-123', 'utm_source' => 'fresh-source', 'utm_campaign' => 'summer-care']);
	$fullPayload = consent_payload();
	consent_assert(($fullPayload['gclid'] ?? '') === 'fresh_GOOGLE-123' && ($fullPayload['utm_source'] ?? '') === 'fresh-source' && isset($fullPayload['first_landing_page'], $fullPayload['referrer']), 'Full consent must retain advertising, analytics, and navigation attribution.');

	if ($consentFailures !== []) {
		throw new RuntimeException("Consent-safe server attribution regressions:\n- " . implode("\n- ", $consentFailures));
	}

	echo "PASS CookieRegressionTest\n";
}
