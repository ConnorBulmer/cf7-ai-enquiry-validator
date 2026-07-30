<?php

declare(strict_types=1);

namespace CF7AIV\Integration;

use CF7AIV\Destinations\GA4Destination;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\HttpTransportInterface;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Infrastructure\WordPressHttpTransport;
use InvalidArgumentException;

final class GA4TestService {
	public const EVENT_NAME = 'cf7aiv_transport_test';
	public const CONFIRMATION = 'SEND_ZERO_VALUE_TEST';

	/** @var callable():array<string,mixed> */
	private $settingsResolver;
	/** @var callable(string):string */
	private $secretResolver;
	/** @var callable():int */
	private $clock;
	/** @var callable():string */
	private $eventIdGenerator;

	public function __construct(
		private readonly HttpTransportInterface $transport = new WordPressHttpTransport(),
		?callable $settingsResolver = null,
		?callable $secretResolver = null,
		?callable $clock = null,
		?callable $eventIdGenerator = null,
	) {
		$this->settingsResolver = $settingsResolver ?? static fn (): array => function_exists('get_option')
			? Settings::withDefaults((array) get_option(Settings::OPTION, Settings::defaults()))
			: Settings::defaults();
		$this->secretResolver = $secretResolver ?? static fn (string $name): string => Crypto::secret($name);
		$this->clock = $clock ?? static fn (): int => time();
		$this->eventIdGenerator = $eventIdGenerator ?? static fn (): string => 'cf7aiv-test-' . bin2hex(random_bytes(12));
	}

	/** @return array<string,mixed> */
	public function validate(): array {
		[$measurementId, $apiSecret] = $this->configuration();
		$event = $this->event();
		$result = (new GA4Destination($this->transport, $measurementId, $apiSecret, true, false, false, ($this->clock)()))->send($event);
		return $this->receipt('validate', $event, $result, $result->successful, false);
	}

	/** @return array<string,mixed> */
	public function transport(string $confirmation): array {
		if (! hash_equals(self::CONFIRMATION, $confirmation)) {
			throw new InvalidArgumentException('Live zero-value GA4 testing requires --confirm=' . self::CONFIRMATION . '.');
		}
		[$measurementId, $apiSecret] = $this->configuration();
		$event = $this->event();
		$now = ($this->clock)();
		$preflight = (new GA4Destination($this->transport, $measurementId, $apiSecret, true, false, false, $now))->send($event);
		if (! $preflight->successful) {
			return $this->receipt('transport', $event, $preflight, false, false);
		}
		$live = (new GA4Destination($this->transport, $measurementId, $apiSecret, false, false, false, $now))->send($event);
		return $this->receipt('transport', $event, $live, true, true);
	}

	/** @return array{string,string} */
	private function configuration(): array {
		$settings = Settings::withDefaults((array) ($this->settingsResolver)());
		$ga4 = (array) ($settings['destinations']['ga4'] ?? []);
		$measurementId = trim((string) ($ga4['measurement_id'] ?? ''));
		$apiSecret = trim((string) ($this->secretResolver)('ga4_api_secret'));
		if ($measurementId === '' || $apiSecret === '') {
			throw new InvalidArgumentException('A GA4 Measurement ID and API secret are required.');
		}
		return [$measurementId, $apiSecret];
	}

	private function event(): ConversionEvent {
		$now = ($this->clock)();
		$eventId = trim((string) ($this->eventIdGenerator)());
		if ($eventId === '') {
			throw new InvalidArgumentException('The controlled GA4 test event ID cannot be empty.');
		}
		$occurredAt = gmdate(DATE_ATOM, $now);
		return new ConversionEvent(
			$eventId,
			'transport_test',
			self::EVENT_NAME,
			0.0,
			'GBP',
			$occurredAt,
			['test_mode' => 'controlled_transport'],
			[
				'client_id' => '555.' . $now,
				'session_id' => (string) $now,
				'session_started_at' => $occurredAt,
			],
			[
				'analytics_storage' => 'denied',
				'ad_storage' => 'denied',
				'ad_user_data' => 'denied',
				'ad_personalization' => 'denied',
			],
			$occurredAt,
			$occurredAt,
			hash('sha256', $eventId . '|' . self::EVENT_NAME),
		);
	}

	/** @return array<string,mixed> */
	private function receipt(string $mode, ConversionEvent $event, DeliveryResult $result, bool $preflightSuccessful, bool $liveRequestSent): array {
		return [
			'mode' => $mode,
			'safety_profile' => 'zero_value_synthetic_consent_denied',
			'event_name' => self::EVENT_NAME,
			'event_id' => $event->eventId,
			'successful' => $result->successful,
			'preflight_successful' => $preflightSuccessful,
			'live_request_sent' => $liveRequestSent,
			'retryable' => $result->retryable,
			'error' => $result->error,
			'warnings' => $result->warnings,
		];
	}
}
