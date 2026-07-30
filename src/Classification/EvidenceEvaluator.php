<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Domain\ClassificationResult;

final class EvidenceEvaluator {
	/** @param array<string, mixed> $policy */
	public static function evaluate(ClassificationResult $result, array $policy): EvidenceDecision {
		if (($policy['schema'] ?? '') !== PolicyValidator::SCHEMA_V2) {
			return new EvidenceDecision('needs_review', ['legacy_policy_without_grounded_rules']);
		}
		if (in_array($result->outcomeId, ['legitimate_non_conversion', 'excluded', 'spam', 'insufficient_information'], true)) {
			return new EvidenceDecision('not_eligible', [$result->outcomeId]);
		}
		if ($result->ambiguityFlags !== []) {
			return new EvidenceDecision('needs_review', ['ambiguous_evidence']);
		}

		$tier = null;
		foreach (($policy['tier_definitions'] ?? []) as $candidate) {
			if (is_array($candidate) && ($candidate['id'] ?? '') === $result->tierId) {
				$tier = $candidate;
				break;
			}
		}
		if ($tier === null) {
			return new EvidenceDecision('needs_review', ['tier_mismatch']);
		}
		if (($tier['outcome_id'] ?? '') !== $result->outcomeId) {
			return new EvidenceDecision('needs_review', ['outcome_mismatch']);
		}

		$hardExclusions = array_values(array_intersect($result->exclusionCodes, $tier['exclusion_codes'] ?? []));
		if ($hardExclusions !== []) {
			return new EvidenceDecision('not_eligible', $hardExclusions);
		}
		if ($result->exclusionCodes !== []) {
			return new EvidenceDecision('needs_review', ['exclusion_mismatch']);
		}

		$groundedCodes = [];
		foreach ($result->evidence as $assertion) {
			if ($assertion instanceof EvidenceAssertion && $assertion->grounded) {
				$groundedCodes[$assertion->code] = true;
			}
		}
		if (array_intersect(array_keys($groundedCodes), $result->missingEvidence) !== []) {
			return new EvidenceDecision('needs_review', ['conflicting_evidence_state']);
		}
		foreach (($tier['required_evidence'] ?? []) as $group) {
			if (! is_array($group) || array_intersect(array_keys($groundedCodes), $group) === []) {
				return new EvidenceDecision('needs_review', ['required_evidence_missing']);
			}
		}
		$supportingCodes = array_intersect(array_keys($groundedCodes), $tier['supporting_evidence'] ?? []);
		if (count($supportingCodes) < (int) ($tier['minimum_supporting_evidence'] ?? 0)) {
			return new EvidenceDecision('needs_review', ['supporting_evidence_missing']);
		}
		if (! in_array($result->outcomeId, ['primary_opportunity', 'secondary_opportunity'], true)) {
			return new EvidenceDecision('needs_review', ['non_commercial_outcome']);
		}

		return new EvidenceDecision('strong_match', []);
	}
}
