<?php

declare(strict_types=1);

namespace CF7AIV\Destinations;

use CF7AIV\Contracts\DestinationInterface;
use CF7AIV\Delivery\ResponseClassifier;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\HttpTransportInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Sends GA4 Measurement Protocol-shaped events to an existing server-side GTM
 * tagging server. The receiving container remains responsible for its GA4
 * client, Conversion Linker, native Google Ads tag, and narrowly scoped trigger.
 */
final class ServerGtmDestination implements DestinationInterface {
	public function __construct(
		private readonly HttpTransportInterface $transport,
		private readonly string $serverUrl,
		private readonly string $measurementId,
		private readonly string $apiSecret,
		private readonly bool $enhancedMatching = false,
		private readonly bool $dryRun = false,
		private readonly ?int $now = null
	) {}

	public function key(): string {
		return 'server_gtm';
	}

	public function send(ConversionEvent $event): DeliveryResult {
		$this->validateConfiguration();

		$warnings = [];
		$now = $this->now ?? time();
		$occurred = strtotime($event->occurredAt);
		$params = [
			'event_id' => $event->eventId,
			'transaction_id' => $event->eventId,
			'value' => $event->value,
			'currency' => $event->currency,
		];

		if (($event->attribution['session_id'] ?? '') !== '') {
			$params['session_id'] = (string) $event->attribution['session_id'];
		}
		foreach (['gclid', 'gbraid', 'wbraid'] as $identifier) {
			if (($event->attribution[$identifier] ?? '') !== '') {
				$params[$identifier] = (string) $event->attribution[$identifier];
			}
		}
		if (($event->context['form_id'] ?? '') !== '') {
			$params['cf7aiv_form_id'] = (string) $event->context['form_id'];
		}
		if (($event->context['source_type'] ?? '') !== '') {
			$params['cf7aiv_source_type'] = (string) $event->context['source_type'];
		}

		$sessionStarted = $event->sessionStartedAt();
		if (($event->attribution['session_id'] ?? '') === '' || $sessionStarted === null || $now - $sessionStarted > 86400) {
			$warnings[] = 'GA4 session attribution requires session_id and dispatch within 24 hours of session start.';
		}

		$body = [
			'client_id' => (string) ($event->attribution['client_id'] ?? $event->eventId),
			'events' => [[
				'name' => $event->eventName,
				'params' => $params,
			]],
			'consent' => [
				'ad_user_data' => self::consent($event->consent['ad_user_data'] ?? 'unknown'),
				'ad_personalization' => self::consent($event->consent['ad_personalization'] ?? 'unknown'),
			],
		];

		if ($occurred !== false && $occurred <= $now && $now - $occurred <= 72 * 3600) {
			$body['timestamp_micros'] = $occurred * 1000000;
		} else {
			$warnings[] = 'GA4-compatible event timestamps must be within the last 72 hours.';
		}

		if ($this->enhancedMatching && ($event->consent['ad_user_data'] ?? 'unknown') === 'granted') {
			if (isset($event->userData['email_sha256'])) {
				$body['user_data']['sha256_email_address'] = $event->userData['email_sha256'];
			}
			if (isset($event->userData['phone_sha256'])) {
				$body['user_data']['sha256_phone_number'] = $event->userData['phone_sha256'];
			}
		}

		$url = rtrim($this->serverUrl, '/') . '/mp/collect?measurement_id=' . rawurlencode($this->measurementId) . '&api_secret=' . rawurlencode($this->apiSecret);
		$arguments = [
			'headers' => ['Content-Type' => 'application/json'],
			'body' => json_encode($body, JSON_THROW_ON_ERROR),
			'timeout' => 10,
		];

		if ($this->dryRun) {
			return new DeliveryResult(
				false,
				false,
				null,
				null,
				[
					'url' => preg_replace('/api_secret=[^&]+/', 'api_secret=[redacted]', $url),
					'arguments' => $arguments,
				],
				$warnings,
				true
			);
		}

		try {
			// Keep the timestamp and session warnings on a sent event too. Only
			// the GA4 route kept them, so a stale event reached the tagging server
			// with no trace of why it would not attribute.
			$result = ResponseClassifier::classify($this->transport->post($url, $arguments));
			return new DeliveryResult($result->successful, $result->retryable, $result->remoteId, $result->error, [], $warnings);
		} catch (Throwable $error) {
			$result = ResponseClassifier::transportFailure($error);
			return new DeliveryResult(false, $result->retryable, null, $result->error, [], $warnings);
		}
	}

	/** An HTTPS base URL with no query or fragment. Delivery readiness applies the same rule. */
	public static function isValidEndpoint(string $url): bool {
		return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
			&& (string) parse_url($url, PHP_URL_HOST) !== ''
			&& parse_url($url, PHP_URL_QUERY) === null
			&& parse_url($url, PHP_URL_FRAGMENT) === null;
	}

	private function validateConfiguration(): void {
		if ($this->serverUrl === '' || $this->measurementId === '' || $this->apiSecret === '') {
			throw new InvalidArgumentException('Server-side GTM URL, measurement ID, and API secret are required.');
		}
		if (! self::isValidEndpoint($this->serverUrl)) {
			throw new InvalidArgumentException('Server-side GTM URL must be an HTTPS base URL without a query or fragment.');
		}
	}

	private static function consent(mixed $state): string {
		return $state === 'granted' ? 'GRANTED' : 'DENIED';
	}
}
