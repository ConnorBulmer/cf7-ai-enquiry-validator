<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

final class ConsentSnapshot {
	/** @param array<string, string> $states */
	public function __construct(
		public readonly array $states,
		public readonly string $source,
		public readonly string $resolvedAt,
	) {}

	public function permitsAdvertising(): bool {
		return ($this->states['ad_storage'] ?? 'denied') === 'granted'
			&& ($this->states['ad_user_data'] ?? 'denied') === 'granted';
	}
}
