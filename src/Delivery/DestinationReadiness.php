<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;

use CF7AIV\Admin\Ga4Validation;
use CF7AIV\Destinations\ServerGtmDestination;

/**
 * Whether a destination may send, and whether an item carries what it needs.
 *
 * The delivery worker and Tracking Health each had their own copy of these
 * rules, and the copies drifted. The worker had no server_gtm branch at all, so
 * a configured tagging server was reported ready while every item was gated
 * "destination not configured". And when GA4 validation moved into its own
 * option, Health followed it but delivery kept reading the old settings flag,
 * which nothing sets any more - so a GA4 property validated and switched to live
 * since then never sent a conversion, while Health showed it ready. One copy
 * cannot disagree with itself.
 */
final class DestinationReadiness {
	public const KEYS = ['ga4', 'server_gtm', 'microsoft_uet', 'webhook'];

	/**
	 * GA4 may send live once its debug validation has passed.
	 *
	 * debug_validated is where that pass was recorded before 0.2.30. Installs that
	 * went live then still carry it and nothing else, so it counts until the
	 * property is validated again; from then on only the new record does, so a
	 * re-validation that fails or is still running stops live sending as it
	 * would anywhere else. A changed measurement ID or secret clears both.
	 *
	 * @param array<string,mixed> $config
	 */
	public static function ga4Live(array $config): bool {
		if (empty($config['live_enabled'])) { return false; }
		if (Ga4Validation::isPassed()) { return true; }
		return Ga4Validation::state() === Ga4Validation::IDLE && ! empty($config['debug_validated']);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param callable(string):string $secret Resolves a stored credential by name.
	 */
	public static function configured(string $key, array $settings, callable $secret): bool {
		$config = (array) ($settings['destinations'][$key] ?? []);
		if (empty($config['enabled'])) { return false; }
		$has = static fn (string $name): bool => trim((string) $secret($name)) !== '';
		return match ($key) {
			'ga4' => trim((string) ($config['measurement_id'] ?? '')) !== '' && $has('ga4_api_secret')
				&& (! empty($config['dry_run']) || self::ga4Live($config)),
			'server_gtm' => ServerGtmDestination::isValidEndpoint((string) ($config['endpoint_url'] ?? ''))
				&& trim((string) ($config['measurement_id'] ?? '')) !== '' && $has('server_gtm_api_secret'),
			'microsoft_uet' => trim((string) ($config['tag_id'] ?? '')) !== '' && $has('microsoft_uet_token'),
			'webhook' => self::webhookUrl($config) && $has('webhook_secret'),
			default => false,
		};
	}

	/**
	 * Does the item carry an identifier this destination can attribute with?
	 *
	 * The tagging server receives a GA4-shaped event: its GA4 client needs the
	 * client id and its Google Ads tag needs a Google click id, so either will do.
	 * Without both the event would go out under a made-up client id with nothing
	 * to attribute it to.
	 *
	 * @param array<string,mixed> $attribution @param array<string,mixed> $item @param array<string,mixed> $settings @param array<string,mixed> $consent
	 */
	public static function signalsReady(string $key, array $attribution, array $item, array $settings, array $consent): bool {
		return match ($key) {
			'ga4' => self::firstSignal($attribution, ['client_id', 'ga_client_id']) !== '',
			'server_gtm' => self::firstSignal($attribution, ['client_id', 'ga_client_id', 'gclid', 'gbraid', 'wbraid']) !== '',
			'microsoft_uet' => self::firstSignal($attribution, ['msclkid']) !== ''
				|| (! empty($settings['privacy']['enhanced_matching']) && ($consent['ad_user_data'] ?? 'unknown') === 'granted'
					&& (self::validHash($item['email_hash'] ?? null) || self::validHash($item['phone_hash'] ?? null))),
			'webhook' => true,
			default => false,
		};
	}

	/** @param array<string,mixed> $values @param list<string> $keys */
	private static function firstSignal(array $values, array $keys): string {
		foreach ($keys as $key) {
			$value = $values[$key] ?? '';
			if (is_scalar($value) && trim((string) $value) !== '') { return trim((string) $value); }
		}
		return '';
	}

	private static function validHash(mixed $value): bool {
		return is_string($value) && preg_match('/^[a-f0-9]{64}$/', strtolower($value)) === 1;
	}

	/** @param array<string,mixed> $config */
	private static function webhookUrl(array $config): bool {
		$url = trim((string) ($config['url'] ?? ''));
		return filter_var($url, FILTER_VALIDATE_URL) !== false
			&& (str_starts_with(strtolower($url), 'https://') || ! empty($config['allow_insecure']));
	}
}
