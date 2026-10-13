<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

final class CalibrationMetrics {
	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array{
	 *   reviewed:int,
	 *   exact_agreement:?float,
	 *   exact_agreement_count:int,
	 *   ads_precision:?float,
	 *   ads_true_positives:int,
	 *   ads_predicted_positives:int,
	 *   false_positives:int,
	 *   missed_leads:int,
	 *   ads_recall:?float,
	 *   confusion_matrix:array<string,array<string,int>>,
	 *   per_tier:array<string,array{predicted:int,reviewed:int}>
	 * }
	 */
	public static function fromRows(array $rows): array {
		$reviewed = 0;
		$exact = 0;
		$adsPredicted = 0;
		$adsTrue = 0;
		$falsePositives = 0;
		$missedLeads = 0;
		$adsReviewed = 0;
		$confusion = [];
		$perTier = [];

		foreach ($rows as $row) {
			if (! is_array($row) || ($row['reviewed_at'] ?? null) === null || (string) ($row['reviewed_at'] ?? '') === '') {
				continue;
			}
			$predictedTier = (string) ($row['predicted_tier_id'] ?? '');
			$predictedOutcome = (string) ($row['predicted_outcome_id'] ?? '');
			$reviewedTier = (string) ($row['reviewed_tier_id'] ?? '');
			$reviewedOutcome = (string) ($row['reviewed_outcome_id'] ?? '');
			if ($predictedTier === '' || $predictedOutcome === '' || $reviewedTier === '' || $reviewedOutcome === '') {
				continue;
			}

			$reviewed++;
			$confusion[$predictedOutcome][$reviewedOutcome] = ($confusion[$predictedOutcome][$reviewedOutcome] ?? 0) + 1;
			$perTier[$predictedTier] ??= ['predicted' => 0, 'reviewed' => 0];
			$perTier[$predictedTier]['predicted']++;
			$perTier[$reviewedTier] ??= ['predicted' => 0, 'reviewed' => 0];
			$perTier[$reviewedTier]['reviewed']++;

			if ($predictedTier === $reviewedTier && $predictedOutcome === $reviewedOutcome) {
				$exact++;
			}

			$policy = self::policySnapshot($row);
			$predictedAds = self::isAdsPositive($predictedTier, $predictedOutcome, $policy);
			$reviewedAds = self::isAdsPositive($reviewedTier, $reviewedOutcome, $policy);
			if ($predictedAds) {
				$adsPredicted++;
				if ($reviewedAds) {
					$adsTrue++;
				} else {
					$falsePositives++;
				}
			}
			// A real lead the AI turned away is lost to bidding as surely as a
			// false one is wrongly counted, and nothing measured it.
			if ($reviewedAds) {
				$adsReviewed++;
				if (! $predictedAds) { $missedLeads++; }
			}
		}

		ksort($confusion);
		foreach ($confusion as &$truthCounts) {
			ksort($truthCounts);
		}
		unset($truthCounts);
		ksort($perTier);

		return [
			'reviewed' => $reviewed,
			'exact_agreement' => $reviewed > 0 ? $exact / $reviewed : null,
			'exact_agreement_count' => $exact,
			'ads_precision' => $adsPredicted > 0 ? $adsTrue / $adsPredicted : null,
			'ads_true_positives' => $adsTrue,
			'ads_predicted_positives' => $adsPredicted,
			'false_positives' => $falsePositives,
			'missed_leads' => $missedLeads,
			'ads_recall' => $adsReviewed > 0 ? ($adsReviewed - $missedLeads) / $adsReviewed : null,
			'confusion_matrix' => $confusion,
			'per_tier' => $perTier,
		];
	}

	/**
	 * The same figures, split by the provider, model and reasoning level that
	 * produced each prediction, so a change of setup can be judged.
	 *
	 * @param list<array<string, mixed>> $rows Rows carrying provider_usage_json.
	 * @return array<string, array<string, mixed>> Keyed by a readable setup label.
	 */
	public static function byProfile(array $rows): array {
		$groups = [];
		foreach ($rows as $row) {
			if (! is_array($row)) { continue; }
			$groups[self::profileLabel($row['provider_usage_json'] ?? null)][] = $row;
		}
		ksort($groups);
		return array_map([self::class, 'fromRows'], $groups);
	}

	public static function profileLabel(mixed $usageJson): string {
		$usage = is_string($usageJson) ? json_decode($usageJson, true) : $usageJson;
		$profile = is_array($usage) && is_array($usage['profile'] ?? null) ? $usage['profile'] : [];
		if (($profile['provider'] ?? '') === '') { return 'Not recorded'; }
		$provider = $profile['provider'] === 'openai' ? 'OpenAI' : ($profile['provider'] === 'deepseek' ? 'DeepSeek' : (string) $profile['provider']);
		$reasoning = (string) ($profile['reasoning'] ?? '');
		$reasoningLabel = match ($reasoning) { 'off' => 'reasoning off', 'standard' => 'standard reasoning', 'deep' => 'deep reasoning', '', 'unsupported' => 'no reasoning control', default => $reasoning };
		return $provider . ' · ' . (string) ($profile['model'] ?? '') . ' · ' . $reasoningLabel;
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private static function policySnapshot(array $row): array {
		foreach (['policy_snapshot', 'policy', 'definition', 'policy_snapshot_json', 'definition_json'] as $key) {
			$value = $row[$key] ?? null;
			if (is_string($value) && $value !== '') {
				$value = json_decode($value, true);
			}
			if (! is_array($value)) {
				continue;
			}
			if (is_array($value['definition'] ?? null)) {
				return $value['definition'];
			}
			return $value;
		}
		return [];
	}

	/** @param array<string, mixed> $policy */
	private static function isAdsPositive(string $tierId, string $outcomeId, array $policy): bool {
		if ($outcomeId !== 'primary_opportunity') {
			return false;
		}
		foreach ((array) ($policy['tier_definitions'] ?? []) as $key => $tier) {
			if (! is_array($tier)) {
				continue;
			}
			$id = (string) ($tier['id'] ?? (is_string($key) ? $key : ''));
			if ($id === $tierId) {
				return ! empty($tier['ads_eligible']) && (string) ($tier['outcome_id'] ?? '') === 'primary_opportunity';
			}
		}
		return false;
	}
}
