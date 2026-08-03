<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

final class AdminPages {
	public static function overview(): void {
		self::capability();
		$metrics = (new DashboardService())->metrics();
		$quality = (array) ($metrics['quality'] ?? []);
		$reviewed = (int) ($quality['reviewed'] ?? 0);
		$exact = (int) ($quality['exact_agreement_count'] ?? 0);
		$adsTrue = (int) ($quality['ads_true_positives'] ?? 0);
		$adsPredicted = (int) ($quality['ads_predicted_positives'] ?? 0);
		$exactText = isset($quality['exact_agreement'])
			? number_format((float) $quality['exact_agreement'] * 100, 1) . "% ({$exact} of {$reviewed} reviewed)"
			: 'Unavailable (0 reviewed)';
		$adsText = isset($quality['ads_precision'])
			? number_format((float) $quality['ads_precision'] * 100, 1) . "% ({$adsTrue} of {$adsPredicted} predicted Ads-eligible positives)"
			: 'Unavailable (0 predicted Ads-eligible positives)';
		$falsePositives = (int) ($quality['false_positives'] ?? 0);
		$automationMode = (string) ($metrics['automation_mode'] ?? 'shadow');
		$queueAge = is_numeric($metrics['queue_age_seconds'] ?? null) ? (int) $metrics['queue_age_seconds'] : null;
		$distributionPanels = '';
		foreach (['by_state' => ['Counts by state', 'No classified states yet.'], 'by_tier' => ['Counts by tier', 'No classified tiers yet.'], 'by_reason' => ['Counts by reason', 'No classification reasons yet.']] as $key => [$title, $empty]) {
			$rows = (array) ($metrics[$key] ?? []);
			$body = $rows === [] ? AdminUi::emptyState($empty) : AdminUi::labelCountTable($title, $rows);
			$distributionPanels .= AdminUi::panel($title, $body, 'cf7aiv-distribution-panel');
		}
		$qualityRows = [
			['label' => 'Reviewed enquiries', 'value' => (string) $reviewed],
			['label' => 'Exact agreement', 'value' => $exactText],
			['label' => 'Ads precision', 'value' => $adsText],
			['label' => 'False positives', 'value' => (string) $falsePositives],
		];
		$perTierRows = [];
		foreach ((array) ($quality['per_tier'] ?? []) as $tier => $counts) {
			$counts = (array) $counts;
			$perTierRows[] = ['label' => (string) $tier . ' (predicted)', 'total' => (int) ($counts['predicted'] ?? 0)];
			$perTierRows[] = ['label' => (string) $tier . ' (reviewed)', 'total' => (int) ($counts['reviewed'] ?? 0)];
		}
		$qualityBody = $reviewed === 0
			? AdminUi::emptyState('No human-reviewed enquiries yet.') . AdminUi::keyValueTable('Human-reviewed quality', $qualityRows)
			: AdminUi::keyValueTable('Human-reviewed quality', $qualityRows)
				. self::confusionMatrix((array) ($quality['confusion_matrix'] ?? []))
				. ($perTierRows === [] ? AdminUi::emptyState('No reviewed tiers yet.') : AdminUi::labelCountTable('Reviewed per-tier counts', $perTierRows));
		$pausedRows = [];
		foreach ((array) ($metrics['paused_destinations'] ?? []) as $key => $value) {
			$destination = is_int($key) && is_scalar($value) ? (string) $value : (string) $key;
			$pausedRows[] = ['label' => $destination, 'value' => 'Paused'];
		}
		$pausedBody = $pausedRows === []
			? AdminUi::emptyState('No destinations are paused.')
			: AdminUi::keyValueTable('Paused destinations', $pausedRows);

		echo '<div class="wrap cf7aiv-admin">';
		echo '<div class="cf7aiv-page-header"><h1>AI Enquiry Validator — Overview</h1><p>Monitor operational classifications, human-reviewed quality, and destination readiness.</p><p>'
			. AdminUi::badge('Automation: ' . $automationMode, $automationMode === 'live' ? 'positive' : 'warning')
			. ' ' . AdminUi::badge('Setup: ' . (! empty($metrics['setup_complete']) ? 'Complete' : 'Incomplete'), ! empty($metrics['setup_complete']) ? 'positive' : 'warning')
			. '</p></div>';
		echo '<div class="cf7aiv-metric-grid">'
			. AdminUi::metric('Queue age', AdminUi::duration($queueAge), 'Oldest pending or review item.')
			. AdminUi::metric('Attribution readiness', (int) ($metrics['attribution_ready'] ?? 0) . ' / ' . (int) ($metrics['attribution_total'] ?? 0), 'Items ready for attribution.')
			. AdminUi::metric('API errors', (string) (int) ($metrics['api_errors'] ?? 0), 'Failed delivery attempts.')
			. AdminUi::metric('Reviewed count', (string) $reviewed, 'Human-reviewed enquiries.')
			. '</div>';
		echo '<div class="cf7aiv-panel-grid">' . $distributionPanels . AdminUi::panel('Human-reviewed quality', $qualityBody, 'cf7aiv-quality-panel') . AdminUi::panel('Paused destinations', $pausedBody, 'cf7aiv-paused-destinations-panel') . '</div>';
		echo self::nav() . '</div>';
	}

	public static function reviewQueue(): void {
		ReviewQueuePage::render();
	}

	public static function policyLab(): void {
		PolicyLabPage::render();
	}

	public static function integrations(): void {
		IntegrationsPage::render();
	}

	public static function health(): void {
		self::capability();
		$health = (new HealthService())->inspect();
		global $wpdb;
		$logs = isset($wpdb->prefix)
			? $wpdb->get_results("SELECT id,destination_key,state,last_error,updated_at FROM {$wpdb->prefix}cf7aiv_deliveries ORDER BY updated_at DESC LIMIT 100", ARRAY_A)
			: [];
		foreach (is_array($logs) ? $logs : [] as &$log) {
			$log['last_error'] = AdminSafe::log((string) ($log['last_error'] ?? ''));
		}
		unset($log);
		$dateTo = gmdate('Y-m-d');
		$dateFrom = gmdate('Y-m-d', strtotime('-1 year'));
		echo '<div class="wrap cf7aiv-admin"><h1>Tracking Health &amp; Logs</h1><p>Health tests are read-only and never create Ads conversions.</p><pre>' . self::e((string) json_encode($health)) . '</pre>';
		echo '<h2>Historical stratified calibration</h2><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_backfill">';
		wp_nonce_field('cf7aiv_backfill');
		echo '<label for="cf7aiv-health-source">Source</label><select id="cf7aiv-health-source" name="source">';
		foreach (array_keys((array) ($health['sources'] ?? [])) as $sourceKey) {
			echo '<option value="' . self::e((string) $sourceKey) . '">' . self::e((string) $sourceKey) . '</option>';
		}
		echo '</select><label for="cf7aiv-health-from">From</label><input id="cf7aiv-health-from" type="date" name="date_from" value="' . self::e($dateFrom) . '" required>';
		echo '<label for="cf7aiv-health-to">To</label><input id="cf7aiv-health-to" type="date" name="date_to" value="' . self::e($dateTo) . '" required>';
		echo '<label for="cf7aiv-health-maximum">Maximum</label><input id="cf7aiv-health-maximum" type="number" name="maximum" value="25" min="1" max="500" required>';
		echo '<label><input type="checkbox" name="explicit_opt_in" value="1" required> I explicitly authorize this calibration-only historical sample.</label><button type="submit" class="button">Schedule calibration</button></form>';
		echo '<h2>Sanitized delivery errors</h2><pre>' . self::e((string) json_encode($logs)) . '</pre></div>';
	}

	public static function setup(): void {
		SetupPage::render();
	}

	private static function capability(): void {
		if (! current_user_can('manage_options')) {
			throw new \RuntimeException('Administrator capability is required.');
		}
	}

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}

	/** @param array<string, array<string, int>> $matrix */
	private static function confusionMatrix(array $matrix): string {
		$outcomeMap = [];
		foreach ($matrix as $predicted => $reviewed) {
			$outcomeMap[(string) $predicted] = true;
			foreach ((array) $reviewed as $outcome => $_count) {
				$outcomeMap[(string) $outcome] = true;
			}
		}
		$outcomes = array_keys($outcomeMap);
		sort($outcomes, SORT_STRING);
		if ($outcomes === []) {
			return AdminUi::emptyState('No reviewed outcome data yet.');
		}

		$table = '<table class="widefat striped cf7aiv-compact-table"><caption>Reviewed confusion matrix</caption><thead><tr><th scope="col">Predicted outcome</th>';
		foreach ($outcomes as $outcome) {
			$table .= '<th scope="col">' . self::e($outcome) . '</th>';
		}
		$table .= '</tr></thead><tbody>';
		foreach ($outcomes as $predicted) {
			$table .= '<tr><th scope="row">' . self::e($predicted) . '</th>';
			foreach ($outcomes as $reviewed) {
				$table .= '<td>' . self::e((string) (int) ($matrix[$predicted][$reviewed] ?? 0)) . '</td>';
			}
			$table .= '</tr>';
		}
		return AdminUi::tableRegion('Reviewed confusion matrix', $table . '</tbody></table>');
	}

	private static function nav(): string {
		return '<div class="cf7aiv-page-actions"><a href="admin.php?page=cf7aiv-review">Review Queue</a> &middot; <a href="admin.php?page=cf7aiv-policy">Policy Lab</a> &middot; <a href="admin.php?page=cf7aiv-integrations">Integrations</a> &middot; <a href="admin.php?page=cf7aiv-health">Tracking Health &amp; Logs</a></div>';
	}
}
