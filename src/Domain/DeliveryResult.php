<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

final class DeliveryResult {
	public function __construct(
		public readonly bool $successful,
		public readonly bool $retryable = false,
		public readonly ?string $remoteId = null,
		public readonly ?string $error = null,
		public readonly array $payload = [],
		public readonly array $warnings = [],
		public readonly bool $dryRun = false,
	) {}
}
