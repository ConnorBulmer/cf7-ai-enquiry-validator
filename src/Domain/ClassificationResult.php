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
	 * @param array<string,string> $providerProfile Which provider, model and reasoning level answered.
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
		public readonly array $providerProfile = [],
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
			$this->providerProfile,
		);
	}

	/** Add a flag the system raised itself, such as a likely duplicate, for reviewers to see. */
	public function withAmbiguityFlag(string $flag): self {
		if (in_array($flag, $this->ambiguityFlags, true)) { return $this; }
		return new self(
			$this->tierId,
			$this->reasonCode,
			$this->confidence,
			$this->summary,
			array_merge($this->ambiguityFlags, [$flag]),
			$this->outcomeId,
			$this->evidence,
			$this->missingEvidence,
			$this->exclusionCodes,
			$this->providerUsage,
			$this->providerProfile,
		);
	}

	/**
	 * Record which provider, model and reasoning level produced this result.
	 *
	 * Without it the accuracy figures could not be split by setup, so there was
	 * no way to tell whether a change of model or reasoning level helped.
	 *
	 * @param array<string,mixed> $profile
	 */
	public function withProviderProfile(array $profile): self {
		$clean = [];
		foreach (['provider', 'model', 'reasoning'] as $key) {
			$value = $profile[$key] ?? '';
			if (is_scalar($value) && trim((string) $value) !== '') { $clean[$key] = substr(trim((string) $value), 0, 100); }
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
			$this->providerUsage,
			$clean,
		);
	}
}
