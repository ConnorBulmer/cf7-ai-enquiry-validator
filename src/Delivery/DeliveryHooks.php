<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;

use CF7AIV\Destinations\GA4Destination;
use CF7AIV\Destinations\MicrosoftUetDestination;
use CF7AIV\Destinations\ServerGtmDestination;
use CF7AIV\Destinations\WebhookDestination;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Infrastructure\WordPressHttpTransport;

final class DeliveryHooks {
	public static function register(): void {
		add_action('cf7aiv_evaluate_delivery', [self::class, 'process'], 10, 1);
		add_action('cf7aiv_deliver_item', [self::class, 'process'], 10, 1);
		add_filter('cf7aiv_destinations', [self::class, 'destinations']);
	}
	public static function process(mixed $itemId): void { if ((int) $itemId > 0) { DeliveryWorker::standard()->process((int) $itemId); } }
	/** @param array<string,mixed> $destinations @return array<string,mixed> */
	public static function destinations(array $destinations): array {
		$settings = function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : Settings::defaults(); $transport = new WordPressHttpTransport(); $config = (array) ($settings['destinations'] ?? []); $enhanced = ! empty($settings['privacy']['enhanced_matching']);
		$ga = (array) ($config['ga4'] ?? []);
		if (! empty($ga['enabled']) && ! empty($ga['dry_run'])) {
			$destinations['ga4'] = new GA4Destination($transport, (string) ($ga['measurement_id'] ?? ''), Crypto::secret('ga4_api_secret'), false, $enhanced, true);
		} elseif (! empty($ga['enabled']) && DestinationReadiness::ga4Live($ga)) {
			$destinations['ga4'] = new GA4Destination($transport, (string) ($ga['measurement_id'] ?? ''), Crypto::secret('ga4_api_secret'), false, $enhanced, false);
		}
		$serverGtm = (array) ($config['server_gtm'] ?? []);
		if (! empty($serverGtm['enabled'])) {
			$destinations['server_gtm'] = new ServerGtmDestination(
				$transport,
				(string) ($serverGtm['endpoint_url'] ?? ''),
				(string) ($serverGtm['measurement_id'] ?? ''),
				Crypto::secret('server_gtm_api_secret'),
				$enhanced,
				! empty($serverGtm['dry_run'])
			);
		}
		$ms = (array) ($config['microsoft_uet'] ?? []); if (! empty($ms['enabled'])) { $destinations['microsoft_uet'] = new MicrosoftUetDestination($transport, (string) ($ms['tag_id'] ?? ''), Crypto::secret('microsoft_uet_token'), $enhanced, ! empty($ms['dry_run'])); }
		$webhook = (array) ($config['webhook'] ?? []); if (! empty($webhook['enabled'])) { $destinations['webhook'] = new WebhookDestination($transport, (string) ($webhook['url'] ?? ''), Crypto::secret('webhook_secret'), ! empty($webhook['dry_run']), null, ! empty($webhook['allow_insecure'])); }
		return $destinations;
	}
}
