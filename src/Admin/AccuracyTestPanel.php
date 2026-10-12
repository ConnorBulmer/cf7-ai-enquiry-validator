<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Infrastructure\Settings;

/** Policy Lab panel: start an accuracy test and read its result. */
final class AccuracyTestPanel {
	/** @param array<string,mixed> $settings */
	public static function render(array $settings): string {
		$providers = (array) (Settings::withDefaults($settings)['providers'] ?? []);
		$selected = (string) ($providers['selected'] ?? 'deepseek');
		$html = '<section class="card cf7aiv-accuracy-test"><h2>Test against reviewed enquiries</h2>'
			. '<p>Re-run enquiries you have already reviewed against a policy and AI setup, and compare the result with what production predicted for the same enquiries. Use it before publishing a draft or changing model or reasoning level.</p>';
		$run = AccuracyTest::current();
		if ($run !== null) { $html .= self::status($run); }

		$policies = PolicyLabels::savedPolicies();
		if ($policies === []) {
			return $html . AdminUi::emptyState('Save a draft or publish a policy first; the test runs a saved policy.') . '</section>';
		}
		ob_start();
		wp_nonce_field('cf7aiv_accuracy_test');
		$nonce = (string) ob_get_clean();
		$html .= '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_accuracy_test">' . $nonce . '<div class="cf7aiv-form-grid">';
		$html .= '<label for="cf7aiv-accuracy-policy">Policy to test</label><select id="cf7aiv-accuracy-policy" name="accuracy[policy_id]">';
		foreach ($policies as $policy) {
			$html .= '<option value="' . (int) $policy['id'] . '">' . self::e((string) $policy['policy_key'] . ' ' . (string) $policy['version'] . ' (' . (string) $policy['status'] . ')') . '</option>';
		}
		// Each provider option carries its saved model, so switching provider
		// switches the model too instead of sending one provider's model name to
		// the other.
		$html .= '</select><label for="cf7aiv-accuracy-provider">Provider</label><select id="cf7aiv-accuracy-provider" name="accuracy[provider]" data-cf7aiv-accuracy-provider>';
		foreach (['deepseek' => 'DeepSeek', 'openai' => 'OpenAI'] as $key => $label) {
			$html .= '<option value="' . $key . '" data-model="' . self::e((string) ($providers[$key . '_model'] ?? '')) . '"' . ($selected === $key ? ' selected' : '') . '>' . $label . '</option>';
		}
		$html .= '</select><label for="cf7aiv-accuracy-model">Model</label><input id="cf7aiv-accuracy-model" name="accuracy[model]" data-cf7aiv-accuracy-model value="' . self::e((string) ($providers[$selected . '_model'] ?? '')) . '" required>';
		$current = (string) ($providers[$selected . '_reasoning_effort'] ?? 'off');
		$html .= '<label for="cf7aiv-accuracy-reasoning">Reasoning</label><select id="cf7aiv-accuracy-reasoning" name="accuracy[reasoning]">';
		foreach (['off' => 'Off', 'standard' => 'Standard', 'deep' => 'Deep'] as $key => $label) {
			$html .= '<option value="' . $key . '"' . ($current === $key ? ' selected' : '') . '>' . $label . '</option>';
		}
		$html .= '</select><label for="cf7aiv-accuracy-size">Enquiries</label><select id="cf7aiv-accuracy-size" name="accuracy[size]">';
		foreach (AccuracyTest::SIZES as $size) { $html .= '<option value="' . $size . '">Up to ' . $size . ' most recently reviewed</option>'; }
		$html .= '</select></div>';
		$html .= '<label><input type="checkbox" name="accuracy[confirm]" value="1" required> Send these reviewed enquiries to the AI provider again. Only fields the AI was already given when each was first classified are sent. This uses tokens, one call per enquiry.</label>';
		$html .= '<p class="cf7aiv-actions"><button type="submit" class="button button-primary">Run accuracy test</button></p></form>';
		return $html . '<p class="description">Results compare tier and outcome identifiers, so a draft that renames tiers will score as disagreeing with earlier reviews. Starting a new test replaces the previous one.</p></section>';
	}

	/** @param array<string,mixed> $run */
	private static function status(array $run): string {
		$profile = (array) ($run['profile'] ?? []);
		$policy = (array) ($run['policy'] ?? []);
		$setup = CalibrationMetrics::profileLabel(['profile' => $profile]);
		$tested = AccuracyTest::loadPolicy((int) ($policy['id'] ?? 0));
		$definitions = PolicyLabels::definitions(array_map(static fn (array $item): int => (int) ($item['policy_id'] ?? 0), (array) ($run['items'] ?? [])));
		$report = AccuracyTest::report($run, $definitions, (array) ($tested['definition'] ?? []));
		$state = (string) ($run['state'] ?? '');
		$heading = match ($state) {
			'running' => 'Testing: ' . $report['done'] . ' of ' . $report['total'] . ' enquiries done',
			'complete' => 'Last test: complete',
			'cancelled' => 'Last test: cancelled after ' . $report['done'] . ' of ' . $report['total'],
			default => 'Last test: stopped after ' . $report['done'] . ' of ' . $report['total'],
		};
		$html = '<div class="cf7aiv-accuracy-status" role="status"><h3>' . self::e($heading) . '</h3><p>Policy ' . self::e((string) ($policy['key'] ?? '') . ' ' . (string) ($policy['version'] ?? '')) . ' with ' . self::e($setup) . '.</p>';
		if ((string) ($run['error'] ?? '') !== '') {
			$html .= '<div class="notice notice-error inline"><p>' . self::e((string) $run['error']) . '</p></div>';
		}
		if ($state === 'running') {
			ob_start();
			wp_nonce_field('cf7aiv_accuracy_cancel');
			$html .= '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_accuracy_cancel">' . (string) ob_get_clean() . '<p class="cf7aiv-actions"><button type="submit" class="button">Cancel test</button> <span class="description">Reload this page to see progress.</span></p></form>';
		}
		$baseline = $report['baseline'];
		$test = $report['test'];
		if ((int) ($test['reviewed'] ?? 0) > 0) {
			$percent = static fn (mixed $value): string => is_numeric($value) ? number_format((float) $value * 100, 1) . '%' : '—';
			$rows = [
				'Enquiries compared' => [(string) (int) $baseline['reviewed'], (string) (int) $test['reviewed']],
				'Exact agreement with reviewers' => [$percent($baseline['exact_agreement']), $percent($test['exact_agreement'])],
				'Ads precision' => [$percent($baseline['ads_precision']), $percent($test['ads_precision'])],
				'False positives' => [(string) (int) $baseline['false_positives'], (string) (int) $test['false_positives']],
				'Missed leads' => [(string) (int) $baseline['missed_leads'], (string) (int) $test['missed_leads']],
			];
			$html .= '<table class="widefat striped cf7aiv-accuracy-results">' . AdminUi::caption('Accuracy test results') . '<thead><tr><th scope="col">Measure</th><th scope="col">Production</th><th scope="col">This test</th></tr></thead><tbody>';
			foreach ($rows as $label => [$before, $after]) {
				$html .= '<tr><th scope="row">' . self::e($label) . '</th><td>' . self::e($before) . '</td><td>' . self::e($after) . '</td></tr>';
			}
			$html .= '</tbody></table><p class="description">' . number_format($report['tokens']) . ' tokens used by this test.</p>';
		}
		if ($report['errors'] !== []) {
			$reasons = array_slice(array_values(array_unique($report['errors'])), 0, 3);
			$html .= '<p>' . count($report['errors']) . ' could not be classified and are left out of both columns:</p><ul>';
			foreach ($reasons as $reason) { $html .= '<li><code>' . self::e(AdminSafe::log($reason)) . '</code></li>'; }
			$html .= '</ul>';
		}
		return $html . '</div>';
	}

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}
}
