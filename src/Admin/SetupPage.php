<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\PolicyPackRegistry;
use CF7AIV\Infrastructure\Settings;

final class SetupPage {
	public static function render(): void {
		self::capability();
		$settings = (array) get_option(Settings::OPTION, Settings::defaults());
		$stage = (string) ($settings['setup']['stage'] ?? 'setup');
		$packs = (new PolicyPackRegistry())->all();

		echo '<div class="wrap cf7aiv-admin"><h1>AI Enquiry Validator Setup</h1>';
		echo '<ol class="cf7aiv-stage-list" aria-label="Setup lifecycle">';
		foreach (['setup' => 'Setup', 'testing' => 'Testing', 'live' => 'Live'] as $key => $label) {
			echo '<li class="cf7aiv-status-badge' . ($stage === $key ? ' is-current' : '') . '"' . ($stage === $key ? ' aria-current="step"' : '') . '>' . self::e($label) . '</li>';
		}
		echo '</ol><p>Setup is skippable. Entering Testing or Live leaves automation in shadow mode until it is enabled separately in Integrations.</p>';

		echo '<section class="card"><h2>1. Pack selection</h2><p>Choose the closest starting point. You can change every draft rule before publishing.</p>';
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_setup_scan">';
		wp_nonce_field('cf7aiv_setup_scan');
		echo '<label for="cf7aiv-pack">Starter pack</label><select id="cf7aiv-pack" name="pack_key">';
		foreach ($packs as $key => $pack) {
			echo '<option value="' . self::e((string) $key) . '">' . self::e((string) ($pack['pack']['label'] ?? $key)) . '</option>';
		}
		echo '</select>';
		echo '<h2>2. Optional: Analyse public site and forms</h2><p>Only public page copy and safe form structure are sent. Submission values are never scanned.</p><button type="submit" class="button">Analyse public site and forms</button></form></section>';

		echo '<section class="card"><h2>3. Pending suggestions</h2><p>Every suggestion retains its public page, form, or starter-pack provenance. Nothing is accepted automatically.</p>';
		$suggestions = self::pendingSuggestions();
		if ($suggestions === []) {
			echo '<p>No pending suggestions.</p>';
		}
		foreach ($suggestions as $suggestion) {
			echo '<article class="cf7aiv-suggestion"><h3>' . self::e((string) ($suggestion['suggestion_type'] ?? 'Suggestion')) . '</h3>';
			echo '<p><strong>Provenance:</strong> ' . self::e(implode(', ', self::jsonList($suggestion['provenance_json'] ?? '[]'))) . '</p>';
			echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_setup_suggestion"><input type="hidden" name="suggestion_id" value="' . (int) ($suggestion['id'] ?? 0) . '">';
			wp_nonce_field('cf7aiv_setup_suggestion');
			echo '<label for="cf7aiv-suggestion-' . (int) ($suggestion['id'] ?? 0) . '">Suggested value</label><textarea id="cf7aiv-suggestion-' . (int) ($suggestion['id'] ?? 0) . '" name="edited_value" rows="3">' . self::e(self::jsonValue($suggestion['value_json'] ?? '')) . '</textarea>';
			echo '<button class="button button-primary" name="suggestion_action" value="accept" type="submit">Accept or save edit</button> <button class="button" name="suggestion_action" value="reject" type="submit">Reject</button></form></article>';
		}
		echo '</section>';

		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_save_guided_policy"><input type="hidden" name="return_page" value="setup">';
		wp_nonce_field('cf7aiv_guided_policy');
		echo '<section class="card"><h2>4. Guided business questions</h2>';
		echo '<label for="cf7aiv-guided-pack">Starter pack</label><select id="cf7aiv-guided-pack" name="guided[pack_key]">';
		$selectedPack = (string) ($settings['setup']['selected_pack'] ?? array_key_first($packs));
		foreach ($packs as $key => $pack) {
			echo '<option value="' . self::e((string) $key) . '"' . ($selectedPack === (string) $key ? ' selected' : '') . '>' . self::e((string) ($pack['pack']['label'] ?? $key)) . '</option>';
		}
		echo '</select><input type="hidden" name="guided[policy_key]" value="global"><label for="cf7aiv-guided-version">Draft version</label><input id="cf7aiv-guided-version" name="guided[version]" value="' . self::e(gmdate('Ymd-His')) . '" required>';
		echo '<label for="cf7aiv-business-description">Describe the business</label><textarea id="cf7aiv-business-description" name="guided[business_description]" rows="3" required></textarea>';
		echo '<label for="cf7aiv-services">Which services or products should count? One per line.</label><textarea id="cf7aiv-services" name="guided[services]" rows="3"></textarea>';
		echo '<label for="cf7aiv-audiences">Who are the intended audiences? One per line.</label><textarea id="cf7aiv-audiences" name="guided[audiences]" rows="3"></textarea>';
		echo '<label for="cf7aiv-primary">What makes an enquiry a primary opportunity?</label><textarea id="cf7aiv-primary" name="guided[primary_definition]" rows="3"></textarea>';
		echo '<fieldset><legend>Exclusions</legend>' . self::guidedCheckboxes($packs, 'exclusion_catalog', 'guided[exclusion_codes][]') . '</fieldset>';
		echo '<fieldset><legend>Field whitelist</legend>' . self::guidedCheckboxes($packs, 'ai_whitelist', 'guided[ai_whitelist][]') . '</fieldset></section>';

		echo '<section class="card"><h2>5. Draft summary</h2><p>Saving validates these generic fields with the selected v2 pack and accepted suggestions. It creates a draft only.</p><button type="submit" class="button">Save guided draft</button></section></form>';
		echo '<section class="card"><h2>Lifecycle</h2><p>Review the selected pack, accepted suggestions, business answers, evidence requirements, and exclusions in Policy Lab before publishing.</p>';
		$transition = $stage === 'testing' ? 'live' : 'testing';
		$transitionLabel = $stage === 'testing' ? 'Enter Live' : ($stage === 'live' ? 'Return to Testing' : 'Enter Testing');
		if ($stage === 'testing') {
			echo '<p>A published v2 policy is required. This lifecycle transition does not enable delivery; automation remains shadow.</p>';
		}
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_lifecycle_transition"><input type="hidden" name="transition" value="' . self::e($transition) . '">';
		wp_nonce_field('cf7aiv_lifecycle_transition');
		echo '<button type="submit" class="button button-primary">' . self::e($transitionLabel) . '</button></form></section>';

		if (self::draftExists()) {
			$sample = max(1, min(500, (int) ($settings['calibration']['default_sample_size'] ?? 25)));
			$snapshot = CalibrationPolicySnapshot::active();
			$whitelist = $snapshot['fields'];
			echo '<section class="card"><h2>6. Historical calibration (optional)</h2><p>This is separate from setup and does not authorize delivery.</p>';
			echo '<p><strong>Fields that may be sent to AI under the currently published policies:</strong> ' . self::e($whitelist === [] ? 'none' : implode(', ', $whitelist)) . '.</p>';
			echo '<p>Free text may still contain personal or sensitive information. Review provider suitability and the selected source, forms, dates, and sample size before opting in.</p>';
			echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_backfill">';
			wp_nonce_field('cf7aiv_backfill');
			echo '<input type="hidden" name="whitelist_fingerprint" value="' . self::e($snapshot['fingerprint']) . '">';
			echo '<label for="cf7aiv-source">Stored source key</label><input id="cf7aiv-source" name="source" required>';
			echo '<label for="cf7aiv-form-ids">Form IDs (optional, comma-separated)</label><input id="cf7aiv-form-ids" name="form_ids" inputmode="numeric">';
			echo '<label for="cf7aiv-date-from">From</label><input id="cf7aiv-date-from" type="date" name="date_from" required>';
			echo '<label for="cf7aiv-date-to">To</label><input id="cf7aiv-date-to" type="date" name="date_to" required>';
			echo '<label for="cf7aiv-maximum">Sample size</label><input id="cf7aiv-maximum" type="number" name="maximum" min="1" max="500" value="' . $sample . '" required>';
			echo '<label><input type="checkbox" name="explicit_opt_in" value="1" required> I explicitly authorize this calibration-only historical sample.</label>';
			echo '<button type="submit" class="button">Schedule historical calibration</button></form></section>';
		}
		echo '</div>';
	}

	/** @return list<array<string, mixed>> */
	private static function pendingSuggestions(): array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) {
			return [];
		}
		$rows = $wpdb->get_results("SELECT id,suggestion_type,value_json,provenance_json FROM {$wpdb->prefix}cf7aiv_setup_suggestions WHERE status='pending' ORDER BY id ASC LIMIT 100", ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	private static function draftExists(): bool {
		global $wpdb;
		return isset($wpdb->prefix) && method_exists($wpdb, 'get_var')
			&& (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_policies WHERE status='draft'") > 0;
	}

	/** @param array<string,array<string,mixed>> $packs */
	private static function guidedCheckboxes(array $packs, string $key, string $name): string {
		$values = [];
		foreach ($packs as $pack) {
			foreach ((array) ($pack[$key] ?? []) as $value) {
				if (is_string($value)) {
					$values[$value] = true;
				}
			}
		}
		ksort($values);
		$html = '';
		foreach (array_keys($values) as $value) {
			$html .= '<label><input type="checkbox" name="' . self::e($name) . '" value="' . self::e($value) . '" checked> ' . self::e(str_replace('_', ' ', $value)) . '</label>';
		}
		return $html;
	}

	/** @return list<string> */
	private static function jsonList(mixed $json): array {
		$value = is_string($json) ? json_decode($json, true) : $json;
		return is_array($value) ? array_values(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value))) : [];
	}

	private static function jsonValue(mixed $json): string {
		if (! is_string($json)) {
			return is_scalar($json) ? (string) $json : '';
		}
		$value = json_decode($json, true);
		if (is_string($value)) {
			return $value;
		}
		return is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $json;
	}

	private static function capability(): void {
		if (! current_user_can('manage_options')) {
			throw new \RuntimeException('Administrator capability is required.');
		}
	}

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}
}
