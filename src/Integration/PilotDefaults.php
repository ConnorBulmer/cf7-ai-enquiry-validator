<?php

declare(strict_types=1);

namespace CF7AIV\Integration;

use CF7AIV\Classification\PolicyPackRegistry;
use CF7AIV\Infrastructure\Settings;

final class PilotDefaults {
	/** @return array<string, mixed> */
	public static function policyDraft(): array {
		$definition = (new PolicyPackRegistry())->get('care_supported_living');
		if ($definition === null) { throw new \RuntimeException('The care and supported-living policy pack is unavailable.'); }
		return [
			'policy_key' => 'global',
			'version' => 'care-supported-living-1.0.0',
			'status' => 'draft',
			'publish' => false,
			'definition' => $definition,
		];
	}

	/** @return array<string, mixed>|null */
	public static function profileForTitle(string $title): ?array {
		$title = strtolower(trim($title));
		$profiles = [
			'care enquiry' => ['profile' => 'care_enquiry', 'recommended_tier' => 'residential_care'],
			'brochure request' => ['profile' => 'brochure_request', 'recommended_tier' => 'care_information'],
			'jobs' => ['profile' => 'jobs', 'recommended_tier' => 'excluded'],
			'general enquiry' => ['profile' => 'general_enquiry', 'recommended_tier' => 'general_legitimate'],
		];
		foreach ($profiles as $needle => $profile) {
			if (str_contains($title, $needle)) {
				return $profile + ['policy_key' => 'global', 'status' => 'draft', 'publish' => false];
			}
		}
		return null;
	}

	/** @param list<array<string, mixed>> $forms @return list<array<string, mixed>> */
	public static function profilesForForms(array $forms): array {
		$result = [];
		foreach ($forms as $form) {
			$profile = self::profileForTitle((string) ($form['title'] ?? ''));
			if ($profile === null) { continue; }
			$result[] = ['form_id' => (int) ($form['id'] ?? 0), 'title' => (string) ($form['title'] ?? '')] + $profile;
		}
		return $result;
	}

	/** @return list<array<string, mixed>> */
	public static function deliveryMappingDrafts(): array {
		$result = [];
		foreach (['ga4', 'microsoft_uet', 'webhook'] as $destination) {
			$eventName = $destination === 'ga4' ? 'qualify_lead' : 'qualified_lead';
			$result[] = [
				'key' => 'care_home_lead_' . $destination,
				'version' => 'pilot-v1',
				'tier_id' => 'residential_care',
				'destination_key' => $destination,
				'event_name' => $eventName,
				'value' => 0.0,
				'currency' => 'GBP',
				'status' => 'draft',
				'enabled' => false,
			];
		}
		return $result;
	}

	/** @return array<string, mixed> */
	public static function safeSettings(): array {
		$settings = Settings::defaults();
		$settings['automation']['mode'] = 'shadow';
		foreach (['ga4', 'microsoft_uet', 'webhook'] as $destination) {
			$settings['destinations'][$destination]['enabled'] = false;
			$settings['destinations'][$destination]['dry_run'] = true;
		}
		return $settings;
	}
}
