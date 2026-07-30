<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;

use InvalidArgumentException;

final class DeliveryMapping {
	public function __construct(
		public readonly string $key,
		public readonly string $version,
		public readonly string $tierId,
		public readonly string $destinationKey,
		public readonly string $eventName,
		public readonly float $value,
		public readonly string $currency,
		public readonly string $status,
		public readonly bool $enabled,
	) {
		if ($key === '' || $version === '' || $tierId === '' || $destinationKey === '' || $eventName === '') { throw new InvalidArgumentException('Delivery mapping identifiers are required.'); }
		if (! preg_match('/^[A-Z]{3}$/', $currency)) { throw new InvalidArgumentException('Delivery mapping currency must use an uppercase ISO-4217 code.'); }
		if (! in_array($status, ['draft', 'published', 'superseded'], true)) { throw new InvalidArgumentException('Delivery mapping status is invalid.'); }
		if (! is_finite($value) || $value < 0) { throw new InvalidArgumentException('Delivery mapping value must be a finite non-negative number.'); }
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self {
		return new self((string) ($data['key'] ?? ''), (string) ($data['version'] ?? ''), (string) ($data['tier_id'] ?? ''), (string) ($data['destination_key'] ?? ''), (string) ($data['event_name'] ?? ''), (float) ($data['value'] ?? 0), (string) ($data['currency'] ?? ''), (string) ($data['status'] ?? 'draft'), ($data['enabled'] ?? false) === true || (int) ($data['enabled'] ?? 0) === 1);
	}

	public function isPublished(): bool { return $this->enabled && $this->status === 'published'; }
}
