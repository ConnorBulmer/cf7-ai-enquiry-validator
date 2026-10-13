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
		$missedText = isset($quality['ads_recall'])
			? (int) ($quality['missed_leads'] ?? 0) . ' (' . number_format((float) $quality['ads_recall'] * 100, 1) . '% of reviewed Ads-eligible leads were found)'
			: 'Unavailable (0 reviewed Ads-eligible leads)';
		$automationMode = (string) ($metrics['automation_mode'] ?? 'shadow');
		$queueAge = is_numeric($metrics['queue_age_seconds'] ?? null) ? (int) $metrics['queue_age_seconds'] : null;
		$distributionPanels = '';
		foreach (['by_state' => ['Counts by state', 'No classified states yet.'], 'by_tier' => ['Counts by tier', 'No classified tiers yet.'], 'by_reason' => ['Counts by reason', 'No classification reasons yet.']] as $key => [$title, $empty]) {
			$rows = (array) ($metrics[$key] ?? []);
			// These three are policy identifiers, so they are humanised here
			// rather than in the table helper, which also renders keys that must
			// stay verbatim.
			foreach ($rows as $index => $row) {
				$rows[$index]['label'] = AdminUi::humanLabel((string) (((array) $row)['label'] ?? ''));
			}
			$body = $rows === [] ? AdminUi::emptyState($empty) : AdminUi::labelCountTable($title, $rows);
			$distributionPanels .= AdminUi::panel($title, $body, 'cf7aiv-distribution-panel');
		}
		$qualityRows = [
			['label' => 'Reviewed enquiries', 'value' => (string) $reviewed],
			['label' => 'Exact agreement', 'value' => $exactText],
			['label' => 'Ads precision', 'value' => $adsText],
			['label' => 'False positives', 'value' => (string) $falsePositives],
			['label' => 'Missed leads', 'value' => $missedText],
		];
		$perTierRows = [];
		foreach ((array) ($quality['per_tier'] ?? []) as $tier => $counts) {
			$counts = (array) $counts;
			// Humanise before the suffix is appended; the combined string is no
			// longer a bare identifier, so labelCountTable would leave it as-is.
			$tierLabel = AdminUi::humanLabel((string) $tier);
			$perTierRows[] = ['label' => $tierLabel . ' (predicted)', 'total' => (int) ($counts['predicted'] ?? 0)];
			$perTierRows[] = ['label' => $tierLabel . ' (reviewed)', 'total' => (int) ($counts['reviewed'] ?? 0)];
		}
		$qualityBody = $reviewed === 0
			? AdminUi::emptyState('No human-reviewed enquiries yet.') . AdminUi::keyValueTable('Human-reviewed quality', $qualityRows)
			: AdminUi::keyValueTable('Human-reviewed quality', $qualityRows)
				. self::profileTable((array) ($metrics['quality_by_profile'] ?? []))
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
			. AdminUi::metric(
				'Attribution readiness',
				(int) ($metrics['attribution_ready'] ?? 0) . ' / ' . (int) ($metrics['attribution_total'] ?? 0),
				'Items ready for attribution.',
				// The obvious next question is which ones are not ready and why.
				// The reasons are already recorded per item, so link to them.
				(int) ($metrics['attribution_ready'] ?? 0) < (int) ($metrics['attribution_total'] ?? 0)
					? ['href' => 'admin.php?page=cf7aiv-review&review_state=', 'text' => 'See which enquiries are not ready']
					: null
			)
			// This counts deliveries a destination rejected, not AI provider errors;
			// it was labelled "API errors", which sent people to the wrong place.
			. AdminUi::metric('Failed deliveries', (string) (int) ($metrics['failed_deliveries'] ?? 0), 'Conversions a destination rejected permanently.')
			. AdminUi::metric('AI usage', self::usage((array) ($metrics['ai_usage'] ?? [])), 'Provider calls and tokens across all classifications. No currency estimate is inferred.')
			. AdminUi::metric('Reviewed count', (string) $reviewed, 'Human-reviewed enquiries.')
			. '</div>';
		echo '<div class="cf7aiv-panel-grid">' . $distributionPanels . AdminUi::panel('Human-reviewed quality', $qualityBody, 'cf7aiv-quality-panel') . AdminUi::panel('Paused destinations', $pausedBody, 'cf7aiv-paused-destinations-panel') . '</div>';
		echo self::nav() . '</div>';
	}

	/**
	 * Accuracy for each provider, model and reasoning level that has reviewed
	 * predictions, so a change of setup can be judged on the same terms.
	 *
	 * @param array<string,array<string,mixed>> $byProfile
	 */
	private static function profileTable(array $byProfile): string {
		if (count($byProfile) < 1) { return ''; }
		$percent = static fn (mixed $value): string => is_numeric($value) ? number_format((float) $value * 100, 1) . '%' : '—';
		$html = '<h3>By AI setup</h3><table class="widefat striped cf7aiv-profile-table">' . AdminUi::caption('Human-reviewed quality by AI setup') . '<thead><tr><th scope="col">AI setup</th><th scope="col">Reviewed</th><th scope="col">Exact agreement</th><th scope="col">Ads precision</th><th scope="col">Missed leads</th></tr></thead><tbody>';
		foreach ($byProfile as $label => $quality) {
			$html .= '<tr><th scope="row">' . self::e((string) $label) . '</th><td>' . (int) ($quality['reviewed'] ?? 0) . '</td><td>' . self::e($percent($quality['exact_agreement'] ?? null)) . '</td><td>' . self::e($percent($quality['ads_precision'] ?? null)) . '</td><td>' . (int) ($quality['missed_leads'] ?? 0) . '</td></tr>';
		}
		return $html . '</tbody></table><p class="description">"Not recorded" covers classifications made before the setup was recorded with each one.</p>';
	}

	/** @param array<string,int> $usage */
	private static function usage(array $usage): string {
		$tokens = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
		return number_format((int) ($usage['calls'] ?? 0)) . ' calls, ' . number_format($tokens) . ' tokens';
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
		$sourceRows = [];
		foreach ((array) ($health['sources'] ?? []) as $sourceKey => $source) {
			$source = (array) $source;
			$sourceRows[] = [
				'label' => (string) $sourceKey,
				'value' => ! empty($source['available'])
					? 'Available — ' . (int) ($source['count'] ?? 0) . ' records'
					: 'Unavailable',
			];
		}
		$checkLabels = [
			'landing_navigation' => 'Landing navigation',
			'form_presence' => 'Form presence',
			'saved_attribution' => 'Saved attribution',
		];
		$checkRows = [];
		foreach ($checkLabels as $key => $label) {
			$checkRows[] = ['label' => $label, 'value' => (($health['checks'][$key] ?? '') === 'read_only' ? 'Read only' : 'Unavailable')];
		}
		$pausedRows = [];
		foreach ((array) ($health['paused_destinations'] ?? []) as $key => $value) {
			$destination = is_int($key) && is_scalar($value) ? (string) $value : (string) $key;
			$pausedRows[] = ['label' => $destination, 'value' => 'Paused'];
		}
		$deliveryTable = '';
		if (is_array($logs) && $logs !== []) {
			$deliveryTable = '<table class="widefat striped">' . AdminUi::caption('Sanitized delivery errors') . '<thead><tr><th scope="col">ID</th><th scope="col">Destination</th><th scope="col">State</th><th scope="col">Last error</th><th scope="col">Updated</th></tr></thead><tbody>';
			foreach ($logs as $log) {
				$log = (array) $log;
				$deliveryTable .= '<tr><td>' . self::e((string) ($log['id'] ?? '')) . '</td><td>' . self::e((string) ($log['destination_key'] ?? '')) . '</td><td>' . self::e((string) ($log['state'] ?? '')) . '</td><td>' . self::e((string) ($log['last_error'] ?? '')) . '</td><td>' . self::e((string) ($log['updated_at'] ?? '')) . '</td></tr>';
			}
			$deliveryTable .= '</tbody></table>';
		}
		$dateTo = gmdate('Y-m-d');
		$dateFrom = gmdate('Y-m-d', strtotime('-1 year'));
		$calibrationForm = '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_backfill">';
		ob_start();
		wp_nonce_field('cf7aiv_backfill');
		$calibrationForm .= (string) ob_get_clean();
		/*
		 * Only offer sources that can actually supply records.
		 *
		 * Every registered source used to be rendered unselected and enabled, so
		 * the browser selected the first one. On an install with Flamingo
		 * registered but empty, that made the default a source the panel beside
		 * it reported as Unavailable; scheduling against it does nothing and
		 * looks broken.
		 */
		$sources = (array) ($health['sources'] ?? []);
		$firstAvailable = '';
		foreach ($sources as $sourceKey => $state) {
			if (! empty(((array) $state)['available'])) { $firstAvailable = (string) $sourceKey; break; }
		}
		$calibrationForm .= '<label for="cf7aiv-health-source">Source</label><select id="cf7aiv-health-source" name="source">';
		if ($firstAvailable === '') {
			$calibrationForm .= '<option value="" selected disabled>No source has records yet</option>';
		}
		foreach ($sources as $sourceKey => $state) {
			$available = ! empty(((array) $state)['available']);
			$calibrationForm .= '<option value="' . self::e((string) $sourceKey) . '"'
				. ($available ? '' : ' disabled')
				. ((string) $sourceKey === $firstAvailable ? ' selected' : '')
				. '>' . self::e((string) $sourceKey . ($available ? '' : ' (unavailable)')) . '</option>';
		}
		$calibrationForm .= '</select><label for="cf7aiv-health-from">From</label><input id="cf7aiv-health-from" type="date" name="date_from" value="' . self::e($dateFrom) . '" required>';
		$calibrationForm .= '<label for="cf7aiv-health-to">To</label><input id="cf7aiv-health-to" type="date" name="date_to" value="' . self::e($dateTo) . '" required>';
		$calibrationForm .= '<label for="cf7aiv-health-maximum">Maximum</label><input id="cf7aiv-health-maximum" type="number" name="maximum" value="25" min="1" max="500" required>';
		// With nothing to sample, the action cannot succeed, so it is not offered.
		$scheduleButton = $firstAvailable === ''
			? '<button type="submit" class="button" disabled>Schedule calibration</button>'
				. '<span class="description"> No submission source has records to sample yet.</span>'
			: '<button type="submit" class="button">Schedule calibration</button>';
		$calibrationForm .= '<label><input type="checkbox" name="explicit_opt_in" value="1" required> I explicitly authorize this calibration-only historical sample.</label><p class="cf7aiv-actions">' . $scheduleButton . '</p></form>';
		$sourceBody = $sourceRows === [] ? AdminUi::emptyState('No submission sources were detected.') : AdminUi::keyValueTable('Submission source health', $sourceRows);
		$pausedBody = $pausedRows === [] ? AdminUi::emptyState('No destinations are paused.') : AdminUi::keyValueTable('Paused destinations', $pausedRows) . self::resumeForms((array) ($health['paused_destinations'] ?? []));
		$deliveryBody = $deliveryTable === '' ? AdminUi::emptyState('No delivery errors were recorded.') : AdminUi::tableRegion('Sanitized delivery errors', $deliveryTable);
		echo '<div class="wrap cf7aiv-admin"><div class="cf7aiv-page-header"><h1>Tracking Health &amp; Logs</h1><p>Health tests are read-only and never create Ads conversions.</p></div>';
		echo '<div class="cf7aiv-panel-grid">' . AdminUi::panel('Submission source health', $sourceBody, 'cf7aiv-health-sources-panel') . AdminUi::panel('Read-only checks', AdminUi::keyValueTable('Read-only checks', $checkRows), 'cf7aiv-health-checks-panel') . AdminUi::panel('Paused destinations', $pausedBody, 'cf7aiv-health-paused-panel') . AdminUi::panel('Historical stratified calibration', $calibrationForm, 'cf7aiv-health-calibration-panel') . '</div>';
		echo AdminUi::panel('Sanitized delivery errors', $deliveryBody, 'cf7aiv-health-delivery-panel');
		echo AdminUi::panel('Plugin activity log', self::eventLog(), 'cf7aiv-health-event-panel') . '</div>';
	}

	/**
	 * Recent operational events, for hosts where the PHP error log is unreachable.
	 *
	 * The plugin broadcasts why it refused, failed or gated something, and until
	 * now those facts went to the PHP error log under CF7AIV_DEBUG - which
	 * managed hosting frequently does not expose. The same information is
	 * recorded here instead, already scrubbed of credentials and personal data,
	 * and capped so it cannot grow without bound.
	 */
	private static function eventLog(): string {
		$entries = EventLog::recent(50);
		if ($entries === []) {
			return AdminUi::emptyState('No plugin activity has been recorded yet.');
		}
		$table = '<table class="widefat striped">' . AdminUi::caption('Plugin activity log')
			. '<thead><tr><th scope="col">When (UTC)</th><th scope="col">Area</th><th scope="col">Level</th><th scope="col">What happened</th></tr></thead><tbody>';
		foreach ($entries as $entry) {
			$level = (string) ($entry['level'] ?? 'info');
			$tone = $level === 'error' ? 'negative' : ($level === 'warning' ? 'warning' : 'neutral');
			$context = [];
			foreach ((array) ($entry['context'] ?? []) as $key => $value) {
				$context[] = $key . '=' . $value;
			}
			$table .= '<tr><td>' . self::e((string) ($entry['at'] ?? '')) . '</td>'
				. '<td>' . self::e(AdminUi::humanLabel((string) ($entry['channel'] ?? ''))) . '</td>'
				. '<td>' . AdminUi::badge(ucfirst($level), $tone) . '</td>'
				. '<td>' . self::e((string) ($entry['message'] ?? ''))
				. ($context === [] ? '' : ' <code>' . self::e(implode(' ', $context)) . '</code>')
				. '</td></tr>';
		}
		return AdminUi::tableRegion('Plugin activity log', $table . '</tbody></table>');
	}

	public static function setup(): void {
		SetupPage::render();
	}

	private static function capability(): void {
		if (! current_user_can('manage_options')) {
			throw new \RuntimeException('Administrator capability is required.');
		}
	}

	/**
	 * Why each destination paused, and a way to resume it.
	 *
	 * The panel only said "Paused": the stored reason was never shown and there
	 * was no way back, so an automatic pause was permanent in practice.
	 *
	 * @param array<int|string,mixed> $paused
	 */
	private static function resumeForms(array $paused): string {
		$html = '';
		foreach ($paused as $key => $value) {
			// Any paused key can be resumed, including a destination registered
			// through the cf7aiv_destinations filter, since the worker pauses those too.
			if (! is_string($key) || $key === '') { continue; }
			$destination = $key;
			// The reason is the destination's own error text, so it is redacted as
			// the delivery log beside it is.
			$reason = is_array($value) && is_scalar($value['reason'] ?? null) ? trim(AdminSafe::log((string) $value['reason'])) : '';
			ob_start();
			wp_nonce_field('cf7aiv_resume_destination');
			$nonce = (string) ob_get_clean();
			$html .= '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '" class="cf7aiv-resume-destination"><input type="hidden" name="action" value="cf7aiv_resume_destination"><input type="hidden" name="destination" value="' . self::e($destination) . '">' . $nonce
				. '<p>' . ($reason !== '' ? '<strong>' . self::e($destination) . ':</strong> ' . self::e($reason) . ' ' : '')
				. '<button type="submit" class="button">Resume ' . self::e($destination) . '</button></p></form>';
		}
		return $html;
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

		$table = '<table class="widefat striped cf7aiv-compact-table">' . AdminUi::caption('Reviewed confusion matrix') . '<thead><tr><th scope="col">Predicted outcome</th>';
		foreach ($outcomes as $outcome) {
			$table .= '<th scope="col">' . self::e(AdminUi::humanLabel($outcome)) . '</th>';
		}
		$table .= '</tr></thead><tbody>';
		foreach ($outcomes as $predicted) {
			$table .= '<tr><th scope="row">' . self::e(AdminUi::humanLabel($predicted)) . '</th>';
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
