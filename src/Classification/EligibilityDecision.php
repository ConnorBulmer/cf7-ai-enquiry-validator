<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class EligibilityDecision {
	/** @param list<string> $reasons */
	public function __construct(public readonly bool $eligible, public readonly array $reasons) {}
}
