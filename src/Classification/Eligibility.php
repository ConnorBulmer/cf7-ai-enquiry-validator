<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Domain\ClassificationResult;

final class Eligibility {
	/** @param array<string, mixed> $context */
	public static function evaluate(ClassificationResult $result, EvidenceDecision $evidence, array $context): EligibilityDecision {
		$reasons = [];
		if (($context['policy_status'] ?? '') !== 'published') { $reasons[] = 'policy_not_published'; }
		if (($context['automation_mode'] ?? 'shadow') !== 'live') { $reasons[] = 'shadow_mode'; }
		if (! empty($context['calibration'])) { $reasons[] = 'calibration_item'; }
		if (! empty($context['historical'])) { $reasons[] = 'historical_item'; }
		if (empty($context['tier_ads_eligible'])) { $reasons[] = 'tier_not_ads_eligible'; }
		if ($evidence->state !== 'strong_match') { $reasons[] = 'decision_not_strong'; }
		if (empty($context['mapping_published'])) { $reasons[] = 'mapping_not_published'; }
		return new EligibilityDecision($reasons === [], $reasons);
	}
}
