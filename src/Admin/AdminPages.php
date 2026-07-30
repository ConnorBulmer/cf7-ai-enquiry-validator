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

		echo '<div class="wrap cf7aiv-admin"><h1>AI Enquiry Validator — Overview</h1>';
		echo '<p>Automation: ' . self::e((string) $metrics['automation_mode']) . '</p><p>Setup status: ' . ($metrics['setup_complete'] ? 'Complete' : 'Incomplete') . '</p>';
		echo '<h2>Counts by state, tier, and reason</h2><p>These operational distributions are not quality or accuracy measures.</p><pre>' . self::e((string) json_encode([$metrics['by_state'], $metrics['by_tier'], $metrics['by_reason']])) . '</pre>';
		echo '<h2>Human-reviewed quality</h2><p>' . $reviewed . ' reviewed.</p><p>Exact agreement: ' . self::e($exactText) . '.</p><p>Ads precision: ' . self::e($adsText) . '.</p>';
		echo '<p>' . $falsePositives . ' false positive' . ($falsePositives === 1 ? '' : 's') . '.</p><h3>Reviewed confusion matrix and tier counts</h3><pre>' . self::e((string) json_encode([$quality['confusion_matrix'] ?? [], $quality['per_tier'] ?? []])) . '</pre>';
		echo '<h2>Queue age</h2><p>' . self::e($metrics['queue_age_seconds'] === null ? 'No pending queue' : (string) $metrics['queue_age_seconds'] . ' seconds') . '</p>';
		echo '<h2>Attribution readiness</h2><p>' . (int) $metrics['attribution_ready'] . ' / ' . (int) $metrics['attribution_total'] . '</p>';
		echo '<h2>API errors</h2><p>' . (int) $metrics['api_errors'] . ' errors.</p><h2>Paused destinations</h2><pre>' . self::e((string) json_encode($metrics['paused_destinations'])) . '</pre>' . self::nav() . '</div>';
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

	private static function nav(): string {
		return '<p><a href="admin.php?page=cf7aiv-review">Review Queue</a> · <a href="admin.php?page=cf7aiv-policy">Policy Lab</a> · <a href="admin.php?page=cf7aiv-integrations">Integrations</a> · <a href="admin.php?page=cf7aiv-health">Tracking Health &amp; Logs</a></p>';
	}
}
