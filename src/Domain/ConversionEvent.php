<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

final class ConversionEvent {
	/**
	 * @param array<string, scalar|array<scalar>> $context
	 * @param array<string, scalar> $attribution
	 * @param array<string, scalar> $consent
	 */
	public function __construct(
		public readonly string $eventId,
		public readonly string $tierId,
		public readonly string $eventName,
		public readonly float $value,
		public readonly string $currency,
		public readonly string $occurredAt,
		public readonly array $context,
		public readonly array $attribution,
		public readonly array $consent,
		public readonly string $submissionAt = '',
		public readonly string $classificationAt = '',
		public readonly string $idempotencyKey = '',
		public readonly array $userData = [],
		public readonly array $audit = [],
	) {}

	/** @return array<string, mixed> */
	public function toArray(): array {
		$payload = [
			'event_id' => $this->eventId,
			'tier_id' => $this->tierId,
			'event_name' => $this->eventName,
			'value' => $this->value,
			'currency' => $this->currency,
			'occurred_at' => $this->occurredAt,
			'submission_at' => $this->submissionAt !== '' ? $this->submissionAt : $this->occurredAt,
			'classification_at' => $this->classificationAt,
			'context' => $this->context,
			'attribution' => $this->attribution,
			'consent' => $this->consent,
		];
		if ($this->userData !== []) { $payload['user_data'] = $this->userData; }
		if ($this->audit !== []) { $payload['consent_override'] = $this->audit; }
		return $payload;
	}
}
