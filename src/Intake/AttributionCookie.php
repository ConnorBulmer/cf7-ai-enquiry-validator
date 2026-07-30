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
		$values = self::buildServerValues($_GET, $_COOKIE, $_SERVER);
		if ($values === []) { return; }
		$settings = function_exists('get_option') ? (array) get_option(Settings::OPTION, []) : [];
		$days = max(1, min(365, (int) ($settings['attribution']['retention_days'] ?? 90)));
		setcookie(self::NAME, self::rawCookieJson($values), [
			'expires' => time() + ($days * DAY_IN_SECONDS), 'path' => '/',
			'secure' => function_exists('is_ssl') && is_ssl(), 'httponly' => false, 'samesite' => 'Lax',
		]);
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
		$persisted = [];
		if (isset($cookies[self::NAME]) && is_string($cookies[self::NAME])) {
			// PHP normally exposes the single-decoded value. The second attempt
			// only upgrades cookies written by the earlier double-encoded build.
			$decoded = json_decode($cookies[self::NAME], true);
			if (! is_array($decoded)) { $decoded = json_decode(rawurldecode($cookies[self::NAME]), true); }
			$persisted = is_array($decoded) ? $decoded : [];
		}
		return self::mergeValues($query, $persisted, $server);
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

	private static function isUrlKey(string $key): bool {
		return in_array($key, ['first_landing_page', 'current_page', 'referrer'], true);
	}

	private static function limit(string $value, int $length): string {
		return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
	}
}
