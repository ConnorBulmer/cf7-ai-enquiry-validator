<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

use CF7AIV\Classification\EvidenceAssertion;
use InvalidArgumentException;

final class ClassificationResult {
	/**
	 * @param list<string> $ambiguityFlags
	 * @param list<EvidenceAssertion> $evidence
	 * @param list<string> $missingEvidence
	 * @param list<string> $exclusionCodes
	 * @param array<string,int> $providerUsage
	 */
	public function __construct(
		public readonly string $tierId,
		public readonly string $reasonCode,
		public readonly float $confidence,
		public readonly string $summary,
		public readonly array $ambiguityFlags = [],
		public readonly string $outcomeId = '',
		public readonly array $evidence = [],
		public readonly array $missingEvidence = [],
		public readonly array $exclusionCodes = [],
		public readonly array $providerUsage = [],
	) {
		if ($confidence < 0.0 || $confidence > 1.0) {
			throw new InvalidArgumentException('Classification confidence must be between 0 and 1.');
		}
	}

	public function isHighConfidence(float $minimum): bool {
		return $this->confidence >= $minimum;
	}

	/** @param array<string,mixed> $usage */
	public function withProviderUsage(array $usage): self {
		$normalized = [];
		foreach (['input_tokens', 'output_tokens', 'reasoning_tokens', 'calls'] as $key) {
			$value = $usage[$key] ?? 0;
			$normalized[$key] = is_numeric($value) ? max(0, (int) $value) : 0;
		}
		return new self(
			$this->tierId,
			$this->reasonCode,
			$this->confidence,
			$this->summary,
			$this->ambiguityFlags,
			$this->outcomeId,
			$this->evidence,
			$this->missingEvidence,
			$this->exclusionCodes,
			$normalized,
		);
	}
}
