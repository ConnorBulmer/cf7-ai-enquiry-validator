<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Domain\ClassificationRequest;

/**
 * Build the classification request for one enquiry under one policy.
 *
 * Live classification and the accuracy test both use this, so a test measures
 * the request production would actually send.
 */
final class RequestFactory {
	/**
	 * @param array<string,mixed> $definition A validated policy definition.
	 * @param array<string,mixed> $fields The enquiry's source fields.
	 * @param list<string> $whitelist Fields that may be sent, already narrowed by the caller.
	 */
	public static function build(string $itemId, string $policyVersion, array $definition, string $compiledPrompt, array $fields, array $whitelist): ClassificationRequest {
		$base = ContextBuilder::request($itemId, $policyVersion, $fields, $whitelist, $compiledPrompt);
		$tierIds = array_values(array_map(static fn (array $tier): string => (string) $tier['id'], $definition['tier_definitions']));
		$outcomeIds = [];
		$allowedEvidence = [];
		$allowedExclusionCodes = [];
		$allowedAmbiguityFlags = [];
		$schema = (string) ($definition['schema'] ?? PolicyValidator::SCHEMA_V1);
		if ($schema === PolicyValidator::SCHEMA_V2) {
			$outcomeIds = array_values(array_unique(array_map(static fn (array $tier): string => (string) $tier['outcome_id'], $definition['tier_definitions'])));
			foreach ($definition['evidence_catalog'] as $evidence) {
				$allowedEvidence[(string) $evidence['code']] = (string) $evidence['dimension'];
			}
			$allowedExclusionCodes = array_values($definition['exclusion_catalog']);
			$allowedAmbiguityFlags = array_values($definition['ambiguity_catalog']);
		}
		return new ClassificationRequest(
			$base->itemId,
			$base->policyVersion,
			$base->redactedText,
			$base->context,
			$base->systemPrompt,
			$tierIds,
			$outcomeIds,
			$allowedEvidence,
			$schema,
			$allowedExclusionCodes,
			$allowedAmbiguityFlags,
		);
	}
}
