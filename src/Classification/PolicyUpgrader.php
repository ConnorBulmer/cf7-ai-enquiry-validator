<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use InvalidArgumentException;

final class PolicyUpgrader {
	private const OUTCOME_MAP = [
		'high_value_care' => 'primary_opportunity',
		'care_information' => 'secondary_opportunity',
		'general_legitimate' => 'legitimate_non_conversion',
		'supplier' => 'excluded',
		'recruitment' => 'excluded',
		'existing_customer' => 'excluded',
		'out_of_scope' => 'excluded',
		'duplicate' => 'excluded',
		'spam' => 'spam',
		'insufficient_information' => 'insufficient_information',
	];

	/** @param array<string, mixed> $v1 @return array<string, mixed> */
	public static function toV2(array $v1, string $packKey = 'custom_business'): array {
		$v1 = PolicyValidator::importV1($v1);
		$registry = new PolicyPackRegistry();
		$template = $registry->get($packKey);
		if ($template === null) {
			throw new InvalidArgumentException("Unknown policy pack {$packKey}.");
		}
		$care = $registry->get('care_supported_living');
		if ($care === null) {
			throw new InvalidArgumentException('Care policy pack is unavailable.');
		}
		$carePrimary = null;
		foreach ($care['tier_definitions'] as $tier) {
			if ($tier['outcome_id'] === 'primary_opportunity') { $carePrimary = $tier; break; }
		}
		if (! is_array($carePrimary)) {
			throw new InvalidArgumentException('Care policy pack has no primary evidence rules.');
		}

		$tiers = [];
		foreach ($v1['tier_definitions'] as $legacyTier) {
			$outcome = self::OUTCOME_MAP[$legacyTier['id']] ?? 'legitimate_non_conversion';
			$isPrimary = $outcome === 'primary_opportunity';
			$tiers[] = [
				'id' => $legacyTier['id'],
				'label' => self::label($legacyTier['id']),
				'outcome_id' => $outcome,
				'description' => $legacyTier['description'],
				'ads_eligible' => $isPrimary && $legacyTier['ads_eligible'],
				'required_evidence' => $isPrimary ? $carePrimary['required_evidence'] : [],
				'supporting_evidence' => $isPrimary ? $carePrimary['supporting_evidence'] : [],
				'minimum_supporting_evidence' => $isPrimary ? $carePrimary['minimum_supporting_evidence'] : 0,
				'exclusion_codes' => [],
				'ambiguity_codes' => [],
			];
		}

		$upgraded = $template;
		$upgraded['business'] = [
			'description' => $v1['business'],
			'services' => [],
			'audiences' => array_values($v1['audiences'] ?? []),
		];
		$upgraded['tier_definitions'] = $tiers;
		$upgraded['terminology'] = $v1['terminology'] ?? [];
		$upgraded['examples'] = $v1['examples'] ?? [];
		$upgraded['field_mapping'] = $v1['field_mapping'] ?? [];
		$upgraded['ai_whitelist'] = $v1['ai_whitelist'];
		$upgraded['migration'] = ['source_schema' => PolicyValidator::SCHEMA_V1];
		return PolicyValidator::importV2($upgraded);
	}

	private static function label(string $id): string {
		return ucfirst(str_replace('_', ' ', $id));
	}
}
