<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class StarterPolicy {
	/** @return array<string, mixed> */
	public static function definition(): array {
		$definition = (new PolicyPackRegistry())->get('care_supported_living');
		if ($definition === null) {
			throw new \RuntimeException('The care and supported-living starter policy is unavailable.');
		}
		return $definition;
	}

	/** @return array<string, mixed> */
	public static function legacyDefinition(): array {
		return [
			'schema' => PolicyValidator::SCHEMA_V1,
			'business' => 'A care-home operator assessing enquiries for appropriate follow-up.',
			'audiences' => ['people seeking care for themselves or relatives', 'health and social care professionals'],
			'tier_definitions' => [
				['id' => 'high_value_care', 'description' => 'A specific current care need, placement, respite, assessment, availability or tour enquiry.', 'ads_eligible' => true],
				['id' => 'care_information', 'description' => 'A brochure, fees, funding or general care-information request without a specific current placement need.', 'ads_eligible' => false],
				['id' => 'general_legitimate', 'description' => 'A legitimate enquiry that is not a care sales lead.', 'ads_eligible' => false],
				['id' => 'supplier', 'description' => 'A supplier, partnership or sales pitch.', 'ads_eligible' => false],
				['id' => 'recruitment', 'description' => 'A job, vacancy, volunteering or employment enquiry.', 'ads_eligible' => false],
				['id' => 'existing_customer', 'description' => 'An existing resident, relative, customer or service issue.', 'ads_eligible' => false],
				['id' => 'spam', 'description' => 'Spam, automation or malicious content.', 'ads_eligible' => false],
				['id' => 'out_of_scope', 'description' => 'Content outside the organisation or service scope.', 'ads_eligible' => false],
				['id' => 'duplicate', 'description' => 'An apparent duplicate of another enquiry.', 'ads_eligible' => false],
				['id' => 'insufficient_information', 'description' => 'Not enough non-identifying information to classify reliably.', 'ads_eligible' => false],
			],
			'exclusions' => ['Do not treat supplier, recruitment, existing-customer, spam or duplicate messages as care leads.'],
			'ambiguity_rules' => ['Flag conflicting intent, unclear relationship, or insufficient evidence.'],
			'terminology' => ['respite' => 'short-term care', 'placement' => 'admission to a care home'],
			'examples' => [],
			'field_mapping' => ['message' => 'enquiry message', 'care-type' => 'requested care type', 'home' => 'care home'],
			'ai_whitelist' => ['message', 'your-message', 'enquiry', 'care-type', 'home', 'subject'],
		];
	}
}
