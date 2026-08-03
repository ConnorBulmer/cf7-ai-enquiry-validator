<?php

declare(strict_types=1);

namespace CF7AIV\Integration;

use CF7AIV\Destinations\MicrosoftUetDestination;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\HttpTransportInterface;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Infrastructure\WordPressHttpTransport;
use InvalidArgumentException;

final class MicrosoftUetTestService {
	public const EVENT_NAME = 'cf7aiv_transport_test';
	public const CONFIRMATION = 'SEND_ZERO_VALUE_MICROSOFT_TEST';

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
		$this->eventIdGenerator = $eventIdGenerator ?? static fn (): string => 'cf7aiv-ms-test-' . bin2hex(random_bytes(12));
	}

	/** @return array<string,mixed> */
	public function transport(string $confirmation): array {
		if (! hash_equals(self::CONFIRMATION, $confirmation)) {
			throw new InvalidArgumentException('Live zero-value Microsoft testing requires --confirm=' . self::CONFIRMATION . '.');
		}
		[$tagId, $token] = $this->configuration();
		$event = $this->event();
		$result = (new MicrosoftUetDestination($this->transport, $tagId, $token, false, false))->send($event);
		return $this->receipt($event, $result);
	}

	/** @return array{string,string} */
	private function configuration(): array {
		$settings = Settings::withDefaults((array) ($this->settingsResolver)());
		$microsoft = (array) ($settings['destinations']['microsoft_uet'] ?? []);
		$tagId = trim((string) ($microsoft['tag_id'] ?? ''));
		$token = trim((string) ($this->secretResolver)('microsoft_uet_token'));
		if ($tagId === '' || $token === '') {
			throw new InvalidArgumentException('A Microsoft UET tag ID and authentication key are required.');
		}
		return [$tagId, $token];
	}

	private function event(): ConversionEvent {
		$now = ($this->clock)();
		$eventId = trim((string) ($this->eventIdGenerator)());
		if ($eventId === '') {
			throw new InvalidArgumentException('The controlled Microsoft test event ID cannot be empty.');
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
			['microsoft_anonymous_id' => substr(hash('sha256', $eventId . '|cf7aiv-microsoft-transport'), 0, 32)],
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
	private function receipt(ConversionEvent $event, DeliveryResult $result): array {
		return [
			'mode' => 'transport',
			'safety_profile' => 'zero_value_synthetic_consent_denied',
			'event_name' => self::EVENT_NAME,
			'event_id' => $event->eventId,
			'successful' => $result->successful,
			'live_request_sent' => true,
			'retryable' => $result->retryable,
			'error' => $result->error,
			'warnings' => $result->warnings,
		];
	}
}
