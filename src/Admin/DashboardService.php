<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Infrastructure\Settings;

final class DashboardService {
	/** @return array<string, mixed> */
	public function metrics(): array {
		global $wpdb;
		$settings = function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : Settings::defaults();
		$result = [
			'by_state' => [],
			'by_tier' => [],
			'by_reason' => [],
			'reviewed' => 0,
			'corrected' => 0,
			'precision' => null,
			'quality' => CalibrationMetrics::fromRows([]),
			'queue_age_seconds' => null,
			'attribution_ready' => 0,
			'attribution_total' => 0,
			'api_errors' => 0,
			'api_cost' => 'Unavailable',
			'paused_destinations' => function_exists('get_option') ? (array) get_option('cf7aiv_paused_destinations', []) : [],
			'automation_mode' => $settings['automation']['mode'] ?? 'shadow',
			'setup_complete' => ! empty($settings['setup_complete']),
		];
		if (! isset($wpdb->prefix)) {
			return $result;
		}

		$items = $wpdb->prefix . 'cf7aiv_items';
		$deliveries = $wpdb->prefix . 'cf7aiv_deliveries';
		foreach (['state' => 'by_state', 'tier_id' => 'by_tier', 'reason_code' => 'by_reason'] as $column => $key) {
			$rows = $wpdb->get_results("SELECT {$column} AS label,COUNT(*) AS total FROM {$items} GROUP BY {$column}", ARRAY_A);
			$result[$key] = is_array($rows) ? $rows : [];
		}

		$qualityRows = $wpdb->get_results(
			"SELECT item.predicted_tier_id,item.predicted_outcome_id,item.reviewed_tier_id,item.reviewed_outcome_id,item.reviewed_at,policy.definition_json AS policy_snapshot
			FROM {$items} item
			LEFT JOIN {$wpdb->prefix}cf7aiv_policies policy ON policy.id=item.policy_id
			WHERE item.reviewed_at IS NOT NULL",
			ARRAY_A
		);
		$result['quality'] = CalibrationMetrics::fromRows(is_array($qualityRows) ? $qualityRows : []);
		$result['reviewed'] = (int) $result['quality']['reviewed'];
		$result['corrected'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$items} WHERE review_state='corrected'");

		$oldest = $wpdb->get_var("SELECT MIN(created_at) FROM {$items} WHERE review_state IN ('pending','review')");
		$oldestTime = is_string($oldest) ? strtotime($oldest) : false;
		$result['queue_age_seconds'] = $oldestTime === false ? null : max(0, time() - $oldestTime);
		$result['attribution_ready'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$items} WHERE attribution_ready=1");
		$result['attribution_total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$items}");
		$result['api_errors'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$deliveries} WHERE state='failed'");
		return $result;
	}
}
