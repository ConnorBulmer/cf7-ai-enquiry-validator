<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use InvalidArgumentException;

final class EvidenceDecision {
	/** @param list<string> $reasons */
	public function __construct(
		public readonly string $state,
		public readonly array $reasons,
	) {
		if (! in_array($state, ['strong_match', 'needs_review', 'not_eligible'], true)) {
			throw new InvalidArgumentException('Evidence decision state is invalid.');
		}
	}
}
