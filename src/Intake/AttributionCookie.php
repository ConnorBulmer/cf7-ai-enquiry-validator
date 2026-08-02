<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

use CF7AIV\Infrastructure\Settings;

final class AttributionCookie {
	public const NAME = 'cf7aiv_attribution';

	public static function serverFallback(): void {
		if (headers_sent() || ! self::isNavigationRequest($_SERVER)
			|| (defined('REST_REQUEST') && REST_REQUEST)
			|| (defined('DOING_AJAX') && DOING_AJAX)
			|| (function_exists('wp_doing_ajax') && wp_doing_ajax())
			|| (function_exists('is_feed') && is_feed())) { return; }
		$persisted = self::storedCookieValues($_COOKIE);
		$google = array_intersect_key($persisted, array_flip(['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization']));
		$consent = self::resolveConsentState(
			function_exists('wp_get_consent_type') ? wp_get_consent_type() : null,
			function_exists('wp_has_consent') ? (bool) wp_has_consent('statistics') : null,
			function_exists('wp_has_consent') ? (bool) wp_has_consent('marketing') : null,
			$google
		);
		if (! $consent['statistics'] && ! $consent['marketing']) { self::expireExistingCookie(); return; }
		$values = self::filterByConsent(self::mergeValues($_GET, $persisted, $_SERVER), $consent);
		if ($values === []) { self::expireExistingCookie(); return; }
		$settings = function_exists('get_option') ? (array) get_option(Settings::OPTION, []) : [];
		$days = max(1, min(365, (int) ($settings['attribution']['retention_days'] ?? 90)));
		setcookie(self::NAME, self::rawCookieJson($values), self::cookieOptions(time() + ($days * DAY_IN_SECONDS)));
	}

	/** @return array{statistics: bool, marketing: bool} */
	public static function resolveConsentState(mixed $consentType, ?bool $statistics, ?bool $marketing, array $google = []): array {
		$wordpress = self::resolveWordPressConsent($consentType, $statistics, $marketing);
		if ($wordpress !== null) { return $wordpress; }
		return [
			'statistics' => self::granted($google['analytics_storage'] ?? null),
			'marketing' => self::granted($google['ad_storage'] ?? null),
		];
	}

	/** @return array{statistics: bool, marketing: bool}|null */
	public static function resolveWordPressConsent(mixed $consentType, ?bool $statistics, ?bool $marketing): ?array {
		if (! is_string($consentType)) { return null; }
		$normalized = preg_replace('/[-_\s]/', '', strtolower(trim($consentType))) ?? '';
		if ($normalized !== 'optin') { return null; }
		return ['statistics' => $statistics === true, 'marketing' => $marketing === true];
	}

	/** @param array<string, mixed> $server */
	public static function isNavigationRequest(array $server): bool {
		$method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
		if ($method !== 'GET') { return false; }
		if (strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') { return false; }

		$uri = (string) ($server['REQUEST_URI'] ?? '/');
		$path = strtolower((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
		if (preg_match('~(?:^|/)(?:wp-json|feed)(?:/|$)|/wp-admin/(?:admin-ajax|admin-post)\.php$~', $path) === 1
			|| str_contains(strtolower($uri), 'rest_route=')) { return false; }
		if (preg_match('/\.(?:avif|bmp|css|eot|gif|ico|jpe?g|js|json|map|mp3|mp4|otf|pdf|png|svg|ttf|webm|webp|woff2?|xml|zip)$/i', $path) === 1) { return false; }

		$accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));
		if ($accept !== '' && ! str_contains($accept, 'text/html') && ! str_contains($accept, 'application/xhtml+xml')) { return false; }
		$mode = strtolower((string) ($server['HTTP_SEC_FETCH_MODE'] ?? ''));
		if ($mode !== '' && $mode !== 'navigate') { return false; }
		$destination = strtolower((string) ($server['HTTP_SEC_FETCH_DEST'] ?? ''));
		if ($destination !== '' && ! in_array($destination, ['document', 'iframe'], true)) { return false; }
		$purpose = strtolower((string) ($server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_PURPOSE'] ?? ''));
		if (str_contains($purpose, 'prefetch')) { return false; }
		return true;
	}

	/**
	 * Pure server-fallback merge used by the runtime and standalone tests.
	 * Fresh query attribution wins, while the first landing page remains stable.
	 *
	 * @param array<string, mixed> $query
	 * @param array<string, mixed> $cookies
	 * @param array<string, mixed> $server
	 * @return array<string, string>
	 */
	public static function buildServerValues(array $query, array $cookies, array $server): array {
		return self::mergeValues($query, self::storedCookieValues($cookies), $server);
	}

	/** @param array<string, string> $values */
	public static function rawCookieJson(array $values): string {
		$json = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		return is_string($json) ? $json : '{}';
	}

	/**
	 * @param array<string, mixed> $query
	 * @param array<string, mixed> $persisted
	 * @param array<string, mixed> $server
	 * @return array<string, string>
	 */
	private static function mergeValues(array $query, array $persisted, array $server): array {
		$values = [];
		$allowed = array_unique(array_values(FieldNames::map()));
		foreach ($allowed as $key) {
			if (isset($persisted[$key]) && is_scalar($persisted[$key])) {
				$value = trim((string) $persisted[$key]);
				if ($value !== '') { $values[$key] = self::limit($value, self::isUrlKey($key) ? 2048 : 512); }
			}
		}

		$freshClicks = [];
		foreach (array_merge(IdentifierValidator::CLICK_KEYS, ['zc_gad']) as $key) {
			if (isset($query[$key]) && is_scalar($query[$key])) { $freshClicks[$key] = (string) $query[$key]; }
		}
		$clicks = Attribution::merge($freshClicks, $values);
		foreach (IdentifierValidator::CLICK_KEYS as $key) { unset($values[$key]); }
		$values = array_merge($values, $clicks);

		foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'] as $key) {
			if (! isset($query[$key]) || ! is_scalar($query[$key])) { continue; }
			$value = trim((string) $query[$key]);
			if ($value !== '') { $values[$key] = self::limit($value, 512); }
		}

		$current = self::currentUrl($server);
		if ($current !== '') {
			if (empty($values['first_landing_page'])) { $values['first_landing_page'] = $current; }
			$values['current_page'] = $current;
		}
		if (isset($server['HTTP_REFERER']) && is_scalar($server['HTTP_REFERER'])) {
			$referrer = trim((string) $server['HTTP_REFERER']);
			if ($referrer !== '') { $values['referrer'] = self::limit($referrer, 2048); }
		}
		return $values;
	}

	/** @param array<string, mixed> $server */
	private static function currentUrl(array $server): string {
		$host = isset($server['HTTP_HOST']) && is_scalar($server['HTTP_HOST']) ? (string) $server['HTTP_HOST'] : '';
		$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?? '';
		if ($host === '') { return ''; }
		$uri = isset($server['REQUEST_URI']) && is_scalar($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '/';
		$uri = str_replace(["\r", "\n"], '', $uri);
		$secure = ! empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off';
		return self::limit(($secure ? 'https://' : 'http://') . $host . ($uri !== '' ? $uri : '/'), 2048);
	}

	/**
	 * @param array<string, string> $values
	 * @param array{statistics: bool, marketing: bool} $consent
	 * @return array<string, string>
	 */
	private static function filterByConsent(array $values, array $consent): array {
		if (! $consent['marketing']) {
			foreach (IdentifierValidator::CLICK_KEYS as $key) { unset($values[$key]); }
			foreach (['first_landing_page', 'current_page', 'referrer'] as $key) {
				if (! isset($values[$key])) { continue; }
				$sanitized = self::sanitizeUrl($values[$key]);
				if ($sanitized === null || ($key === 'first_landing_page' && $sanitized['had_click'])) { unset($values[$key]); }
				else { $values[$key] = $sanitized['value']; }
			}
			if (! isset($values['first_landing_page']) && isset($values['current_page'])) {
				$values['first_landing_page'] = $values['current_page'];
			}
		}
		if (! $consent['statistics']) {
			foreach (array_merge(
				['ga_client_id', 'ga_session_id', 'first_landing_page', 'current_page', 'referrer'],
				['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id']
			) as $key) { unset($values[$key]); }
		}
		return $values;
	}

	/** @param array<string, mixed> $cookies @return array<string, mixed> */
	private static function storedCookieValues(array $cookies): array {
		if (! isset($cookies[self::NAME]) || ! is_string($cookies[self::NAME])) { return []; }
		try {
			$decoded = json_decode($cookies[self::NAME], true);
			if (! is_array($decoded)) { $decoded = json_decode(rawurldecode($cookies[self::NAME]), true); }
			return is_array($decoded) ? $decoded : [];
		} catch (\Throwable) { return []; }
	}

	private static function expireExistingCookie(): void {
		if (! isset($_COOKIE[self::NAME])) { return; }
		setcookie(self::NAME, '', self::cookieOptions(time() - DAY_IN_SECONDS));
	}

	/** @return array{expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
	private static function cookieOptions(int $expires): array {
		return [
			'expires' => $expires, 'path' => '/',
			'secure' => function_exists('is_ssl') && is_ssl(), 'httponly' => false, 'samesite' => 'Lax',
		];
	}

	private static function granted(mixed $value): bool {
		return is_scalar($value) && strtolower(trim((string) $value)) === 'granted';
	}

	private static function decodeUrlPart(string $value): ?string {
		if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) { return null; }
		$decoded = rawurldecode(str_replace('+', ' ', $value));
		return preg_match('//u', $decoded) === 1 ? $decoded : null;
	}

	/** @return array{value:string,had_click:bool}|null */
	private static function sanitizeParameters(string $value): ?array {
		if ($value === '') { return ['value' => '', 'had_click' => false]; }
		$kept = [];
		$hadClick = false;
		$identifiers = array_merge(IdentifierValidator::CLICK_KEYS, ['zc_gad']);
		foreach (explode('&', $value) as $part) {
			$separator = strpos($part, '=');
			$rawName = $separator === false ? $part : substr($part, 0, $separator);
			$rawValue = $separator === false ? '' : substr($part, $separator + 1);
			$name = self::decodeUrlPart($rawName);
			if ($name === null || self::decodeUrlPart($rawValue) === null) { return null; }
			if (in_array(strtolower($name), $identifiers, true)) { $hadClick = true; continue; }
			$kept[] = $part;
		}
		return ['value' => implode('&', $kept), 'had_click' => $hadClick];
	}

	/** @return array{value:string,had_click:bool}|null */
	private static function sanitizeFragment(string $value): ?array {
		if ($value === '') { return ['value' => '', 'had_click' => false]; }
		$question = strpos($value, '?');
		$prefix = '';
		if ($question !== false) {
			$prefix = substr($value, 0, $question);
			$parameters = substr($value, $question + 1);
			if (self::decodeUrlPart($prefix) === null) { return null; }
		} elseif (str_contains($value, '=')) {
			$parameters = $value;
		} else {
			return self::decodeUrlPart($value) === null ? null : ['value' => $value, 'had_click' => false];
		}
		$sanitized = self::sanitizeParameters($parameters);
		if ($sanitized === null) { return null; }
		return [
			'value' => $prefix . ($sanitized['value'] !== '' ? '?' . $sanitized['value'] : ''),
			'had_click' => $sanitized['had_click'],
		];
	}

	/** @return array{value:string,had_click:bool}|null */
	private static function sanitizeUrl(string $value): ?array {
		if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) { return null; }
		try { $parts = parse_url($value); } catch (\Throwable) { return null; }
		if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
			|| ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
			|| (string) $parts['host'] === ''
			|| self::decodeUrlPart((string) $parts['host']) === null
			|| self::decodeUrlPart((string) ($parts['path'] ?? '')) === null) { return null; }
		$query = self::sanitizeParameters((string) ($parts['query'] ?? ''));
		$fragment = self::sanitizeFragment((string) ($parts['fragment'] ?? ''));
		if ($query === null || $fragment === null) { return null; }
		$scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
		$user = $parts['user'] ?? '';
		$pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
		$auth = $user !== '' ? $user . $pass . '@' : '';
		$host = $parts['host'] ?? '';
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		$path = $parts['path'] ?? '';
		$queryString = $query['value'] !== '' ? '?' . $query['value'] : '';
		$fragmentString = $fragment['value'] !== '' ? '#' . $fragment['value'] : '';
		return [
			'value' => self::limit($scheme . $auth . $host . $port . $path . $queryString . $fragmentString, 2048),
			'had_click' => $query['had_click'] || $fragment['had_click'],
		];
	}

	private static function isUrlKey(string $key): bool {
		return in_array($key, ['first_landing_page', 'current_page', 'referrer'], true);
	}

	private static function limit(string $value, int $length): string {
		return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
	}
}
