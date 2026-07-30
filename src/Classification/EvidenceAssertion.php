<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class EvidenceAssertion {
	public function __construct(
		public readonly string $dimension,
		public readonly string $code,
		public readonly string $fieldKey,
		public readonly string $excerpt,
		public readonly bool $grounded,
	) {}
}
