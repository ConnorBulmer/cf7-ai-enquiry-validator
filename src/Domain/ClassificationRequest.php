<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

use CF7AIV\Classification\PolicyValidator;

final class ClassificationRequest {
	/** @param array<string, scalar|array<scalar>> $context */
	public function __construct(
		public readonly string $itemId,
		public readonly string $policyVersion,
		public readonly string $redactedText,
		public readonly array $context = [],
		public readonly string $systemPrompt = '',
		/** @var list<string> */
		public readonly array $allowedTierIds = [],
		/** @var list<string> */
		public readonly array $allowedOutcomeIds = [],
		/** @var array<string,string> evidence code => dimension */
		public readonly array $allowedEvidence = [],
		public readonly string $policySchema = PolicyValidator::SCHEMA_V1,
		/** @var list<string> */
		public readonly array $allowedExclusionCodes = [],
		/** @var list<string> */
		public readonly array $allowedAmbiguityFlags = [],
	) {}
}
