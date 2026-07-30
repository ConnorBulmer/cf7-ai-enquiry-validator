<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class FieldNames {
	/** @return array<string, string> */
	public static function map(): array {
		return [
			'cf7aiv_submission_id' => 'submission_id',
			'cf7aiv_gclid' => 'gclid', 'cf7aiv_gbraid' => 'gbraid',
			'cf7aiv_wbraid' => 'wbraid', 'cf7aiv_msclkid' => 'msclkid',
			'cf7aiv_ga_client_id' => 'ga_client_id', 'cf7aiv_ga_session_id' => 'ga_session_id',
			'cf7aiv_utm_source' => 'utm_source', 'cf7aiv_utm_medium' => 'utm_medium',
			'cf7aiv_utm_campaign' => 'utm_campaign', 'cf7aiv_utm_term' => 'utm_term',
			'cf7aiv_utm_content' => 'utm_content', 'cf7aiv_utm_id' => 'utm_id',
			'cf7aiv_first_landing_page' => 'first_landing_page', 'cf7aiv_current_page' => 'current_page',
			'cf7aiv_referrer' => 'referrer', 'cf7aiv_ad_storage' => 'ad_storage',
			'cf7aiv_analytics_storage' => 'analytics_storage', 'cf7aiv_ad_user_data' => 'ad_user_data',
			'cf7aiv_ad_personalization' => 'ad_personalization',
		];
	}

	/** @return list<string> */
	public static function all(): array { return array_keys(self::map()); }

	public static function injectMissing(string $html): string {
		$fields = '';
		foreach (self::all() as $name) {
			if (preg_match('/\bname\s*=\s*(["\'])' . preg_quote($name, '/') . '\1/i', $html) === 1) {
				continue;
			}
			$fields .= '<input type="hidden" name="' . $name . '" value="">';
		}
		return $html . $fields;
	}
}
