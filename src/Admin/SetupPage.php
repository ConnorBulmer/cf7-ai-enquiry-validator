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
		$selectedPack = (string) ($settings['setup']['selected_pack'] ?? array_key_first($packs));
		if (! isset($packs[$selectedPack])) {
			$selectedPack = (string) array_key_first($packs);
		}
		$selectedPackDefinition = is_array($packs[$selectedPack] ?? null) ? $packs[$selectedPack] : [];
		$selectedPackLabel = (string) ($selectedPackDefinition['pack']['label'] ?? $selectedPack);
		$draftSummary = (array) ($settings['setup']['draft_summary'] ?? []);
		$stepLabels = [
			'context' => 'Choose context',
			'qualification' => 'Define qualification',
			'fields' => 'Choose AI fields',
			'review' => 'Review draft',
			'finish' => 'Test and finish',
		];

		echo '<div class="wrap cf7aiv-admin cf7aiv-setup-page">';
		echo '<div class="cf7aiv-page-header"><h1>AI Enquiry Validator Setup</h1><p>Work through the guided policy setup. Setup is skippable, and Testing or Live always leaves automation in shadow mode until it is enabled separately in Integrations.</p></div>';
		echo '<ol class="cf7aiv-setup-progress" aria-label="Setup progress">';
		$stepNumber = 1;
		foreach ($stepLabels as $key => $label) {
			echo '<li><button type="button" class="cf7aiv-setup-progress__item" data-setup-step="' . self::e($key) . '" aria-controls="cf7aiv-setup-step-' . self::e($key) . '"' . ($key === 'context' ? ' aria-current="step"' : '') . '><span class="cf7aiv-setup-progress__number">' . $stepNumber . '</span><span>' . self::e($label) . '</span></button></li>';
			$stepNumber++;
		}
		echo '</ol>';

		if (! empty($_GET['suggestion_error'])) {
			echo '<div class="notice notice-error cf7aiv-setup-error" role="alert"><p>The site scan could not complete. Review the selected context and try again.</p></div>';
		}

		echo '<section id="cf7aiv-setup-step-context" class="card cf7aiv-setup-step is-active" data-step="context"><h2 tabindex="-1">1. Choose context</h2><p>Select the closest starter pack and confirm the current safety stage before answering qualification questions.</p>';
		echo '<div class="cf7aiv-setup-summary-panel"><h3>Current lifecycle stage</h3><ol class="cf7aiv-stage-list" aria-label="Setup lifecycle">';
		foreach (['setup' => 'Setup', 'testing' => 'Testing', 'live' => 'Live'] as $key => $label) {
			echo '<li class="cf7aiv-status-badge' . ($stage === $key ? ' is-current' : '') . '"' . ($stage === $key ? ' aria-current="step"' : '') . '>' . self::e($label) . '</li>';
		}
		echo '</ol><p>Automation is currently kept in shadow mode. Lifecycle changes do not enable delivery.</p></div>';
		echo '<div class="cf7aiv-setup-field-group"><label for="cf7aiv-guided-pack">Starter pack</label><select id="cf7aiv-guided-pack" name="guided[pack_key]" form="cf7aiv-guided-policy-form" data-setup-pack required>';
		foreach ($packs as $key => $pack) {
			echo '<option value="' . self::e((string) $key) . '"' . ($selectedPack === (string) $key ? ' selected' : '') . '>' . self::e((string) ($pack['pack']['label'] ?? $key)) . '</option>';
		}
		echo '</select><p class="description">The pack supplies a validated starting policy. You can change the draft rules before publishing.</p></div>';
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '" class="cf7aiv-setup-scan-form"><input type="hidden" name="action" value="cf7aiv_setup_scan"><input type="hidden" name="pack_key" value="' . self::e($selectedPack) . '" data-setup-scan-pack>';
		wp_nonce_field('cf7aiv_setup_scan');
		echo '<p class="cf7aiv-setup-supporting-action">Need help shaping the draft? <button type="submit" class="button button-secondary">Analyse public site and forms</button></p><p class="description">Only public page copy and safe form structure are sent. Submission values are never scanned.</p></form>';

		echo '<div class="cf7aiv-setup-summary-panel"><h3>Optional site and form suggestions</h3><p>Every suggestion retains its public page, form, or starter-pack provenance. Nothing is accepted automatically.</p>';
		$suggestions = self::pendingSuggestions();
		if ($suggestions === []) {
			echo '<p class="cf7aiv-empty-state">No pending suggestions.</p>';
		}
		foreach ($suggestions as $suggestion) {
			$id = (int) ($suggestion['id'] ?? 0);
			echo '<article class="cf7aiv-suggestion"><h4>' . self::e((string) ($suggestion['suggestion_type'] ?? 'Suggestion')) . '</h4>';
			echo '<p><strong>Provenance:</strong> ' . self::e(implode(', ', self::jsonList($suggestion['provenance_json'] ?? '[]'))) . '</p>';
			echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_setup_suggestion"><input type="hidden" name="suggestion_id" value="' . $id . '">';
			wp_nonce_field('cf7aiv_setup_suggestion');
			echo '<div class="cf7aiv-setup-prose-fields"><label for="cf7aiv-suggestion-' . $id . '">Suggested value</label><textarea id="cf7aiv-suggestion-' . $id . '" name="edited_value" rows="3">' . self::e(self::jsonValue($suggestion['value_json'] ?? '')) . '</textarea></div>';
			echo '<p class="cf7aiv-actions"><button class="button button-secondary" name="suggestion_action" value="accept" type="submit">Accept or save edit</button> <button class="button button-secondary" name="suggestion_action" value="reject" type="submit">Reject</button></p></form></article>';
		}
		echo '</div>';
		echo '<p class="cf7aiv-setup-step-actions"><button type="button" class="button button-primary" data-setup-next="qualification">Continue to qualification</button> <button type="button" class="button button-secondary" data-setup-back="context" disabled>Back</button></p></section>';

		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '" id="cf7aiv-guided-policy-form" class="cf7aiv-setup-guided-form"><input type="hidden" name="action" value="cf7aiv_save_guided_policy"><input type="hidden" name="return_page" value="setup"><input type="hidden" name="guided[policy_key]" value="global">';
		wp_nonce_field('cf7aiv_guided_policy');
		echo '<section id="cf7aiv-setup-step-qualification" class="card cf7aiv-setup-step" data-step="qualification"><h2 tabindex="-1">2. Define qualification</h2><p>Describe the legitimate business context and the signals that distinguish a primary opportunity.</p><div class="cf7aiv-setup-prose-fields">';
		echo '<div class="cf7aiv-setup-field-group"><label for="cf7aiv-business-description">Describe the business</label><textarea id="cf7aiv-business-description" name="guided[business_description]" rows="4" required></textarea></div>';
		echo '<div class="cf7aiv-setup-field-group"><label for="cf7aiv-services">Which services or products should count? One per line.</label><textarea id="cf7aiv-services" name="guided[services]" rows="4"></textarea></div>';
		echo '<div class="cf7aiv-setup-field-group"><label for="cf7aiv-audiences">Who are the intended audiences? One per line.</label><textarea id="cf7aiv-audiences" name="guided[audiences]" rows="4"></textarea></div>';
		echo '<div class="cf7aiv-setup-field-group"><label for="cf7aiv-primary">What makes an enquiry a primary opportunity?</label><textarea id="cf7aiv-primary" name="guided[primary_definition]" rows="4"></textarea></div>';
		echo '<fieldset class="cf7aiv-setup-choice-group"><legend>Exclusions</legend><p class="description">Keep supplier, recruitment, existing-customer, spam, and other out-of-scope enquiries from becoming opportunities.</p>' . self::guidedCheckboxes($packs, 'exclusion_catalog', 'guided[exclusion_codes][]') . '</fieldset></div>';
		echo '<p class="cf7aiv-setup-step-actions"><button type="button" class="button button-primary" data-setup-next="fields">Continue to AI fields</button> <button type="button" class="button button-secondary" data-setup-back="context">Back to context</button></p></section>';

		echo '<section id="cf7aiv-setup-step-fields" class="card cf7aiv-setup-step" data-step="fields"><h2 tabindex="-1">3. Choose AI fields</h2><p>Select only the non-identifying fields needed to apply the draft policy. The whitelist is the boundary for provider input.</p><fieldset class="cf7aiv-setup-choice-group"><legend>Field whitelist</legend><p class="description">These fields may be sent to the configured AI provider for classification.</p>' . self::guidedCheckboxes($packs, 'ai_whitelist', 'guided[ai_whitelist][]') . '</fieldset>';
		echo '<div class="cf7aiv-setup-callout" role="note"><h3>Privacy boundary</h3><p>Identity, attribution, consent, secrets, files, and fields beginning with <code>cf7aiv_</code> stay excluded. Free text may still contain personal or sensitive information, so review provider suitability before saving.</p></div>';
		echo '<p class="cf7aiv-setup-step-actions"><button type="button" class="button button-primary" data-setup-next="review">Review draft</button> <button type="button" class="button button-secondary" data-setup-back="qualification">Back to qualification</button></p></section>';

		$packBusiness = (array) ($selectedPackDefinition['business'] ?? []);
		$primaryDefinition = self::primaryDefinition($selectedPackDefinition);
		$packExclusions = self::stringList($selectedPackDefinition['exclusion_catalog'] ?? []);
		$packFields = self::stringList($selectedPackDefinition['ai_whitelist'] ?? []);
		echo '<section id="cf7aiv-setup-step-review" class="card cf7aiv-setup-step" data-step="review"><h2 tabindex="-1">4. Review draft</h2><p>Check the read-only policy summary before saving. The save creates a draft only; publishing remains an explicit Policy Lab action.</p><dl class="cf7aiv-setup-summary-panel cf7aiv-setup-summary-list">';
		echo '<div><dt>Starter pack</dt><dd>' . self::e($selectedPackLabel) . '</dd></div>';
		echo '<div><dt>Business context</dt><dd>' . self::e((string) ($packBusiness['description'] ?? 'Your business description will be validated from the answer above.')) . '</dd></div>';
		echo '<div><dt>Starter services</dt><dd>' . self::e(self::displayList($packBusiness['services'] ?? [], 'Defined from your answers.')) . '</dd></div>';
		echo '<div><dt>Intended audiences</dt><dd>' . self::e(self::displayList($packBusiness['audiences'] ?? [], 'Defined from your answers.')) . '</dd></div>';
		echo '<div><dt>Primary opportunity</dt><dd>' . self::e($primaryDefinition !== '' ? $primaryDefinition : 'Defined from your answer above.') . '</dd></div>';
		echo '<div><dt>Exclusions</dt><dd>' . self::e(self::displayList($packExclusions, 'No catalog exclusions selected.')) . '</dd></div>';
		echo '<div><dt>AI whitelist</dt><dd>' . self::e(self::displayList($packFields, 'No fields selected.')) . '</dd></div>';
		echo '<div><dt>Draft status</dt><dd>' . self::e((string) ($draftSummary['status'] ?? 'Not saved')) . '</dd></div></dl>';
		echo '<div class="cf7aiv-setup-field-group cf7aiv-setup-review-version"><label for="cf7aiv-guided-version">Draft version</label><input id="cf7aiv-guided-version" name="guided[version]" value="' . self::e(gmdate('Ymd-His')) . '" required><p class="description">Use the generated version unless you need a recognizable internal draft label.</p></div>';
		echo '<p class="cf7aiv-setup-step-actions"><button type="submit" class="button button-primary">Save guided draft</button> <button type="button" class="button button-secondary" data-setup-back="fields">Back to AI fields</button></p></section></form>';

		echo '<section id="cf7aiv-setup-step-finish" class="card cf7aiv-setup-step" data-step="finish"><h2 tabindex="-1">5. Test and finish</h2><p>Choose the next safe action. Setup, Testing, and Live describe lifecycle readiness; automation and destinations remain separately controlled.</p>';
		echo '<div class="cf7aiv-setup-summary-panel"><h3>Next actions</h3><ul><li><a href="admin.php?page=cf7aiv-policy">Review the draft in Policy Lab</a></li><li><a href="admin.php?page=cf7aiv-integrations">Configure providers and integrations</a></li><li><a href="admin.php?page=cf7aiv-review">Open the Review Queue</a></li><li><a href="admin.php?page=cf7aiv-health">Check Tracking Health and Logs</a></li></ul></div>';
		echo '<div class="cf7aiv-setup-callout" role="note"><h3>Safety summary</h3><p>Saving a draft does not publish policy, enable automation, send provider tests, or dispatch conversions. Review evidence, consent, attribution, and destination controls before any separate high-risk action.</p></div>';
		$transition = $stage === 'testing' ? 'live' : 'testing';
		$transitionLabel = $stage === 'testing' ? 'Enter Live' : ($stage === 'live' ? 'Return to Testing' : 'Enter Testing');
		if ($stage === 'testing') {
			echo '<p>A published v2 policy is required. This lifecycle transition does not enable delivery; automation remains shadow.</p>';
		}
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_lifecycle_transition"><input type="hidden" name="transition" value="' . self::e($transition) . '">';
		wp_nonce_field('cf7aiv_lifecycle_transition');
		echo '<p class="cf7aiv-actions"><button type="submit" class="button button-primary cf7aiv-safety-action">' . self::e($transitionLabel) . '</button></p></form>';
		if (self::draftExists()) {
			$sample = max(1, min(500, (int) ($settings['calibration']['default_sample_size'] ?? 25)));
			$snapshot = CalibrationPolicySnapshot::active();
			$whitelist = $snapshot['fields'];
			echo '<div class="cf7aiv-setup-summary-panel"><h3>Historical calibration (optional)</h3><p>This is separate from setup and does not authorize delivery.</p>';
			echo '<p><strong>Fields that may be sent to AI under the currently published policies:</strong> ' . self::e($whitelist === [] ? 'none' : implode(', ', $whitelist)) . '.</p>';
			echo '<p>Free text may still contain personal or sensitive information. Review provider suitability and the selected source, forms, dates, and sample size before opting in.</p>';
			echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_backfill">';
			wp_nonce_field('cf7aiv_backfill');
			echo '<input type="hidden" name="whitelist_fingerprint" value="' . self::e($snapshot['fingerprint']) . '">';
			echo '<div class="cf7aiv-setup-short-fields"><div><label for="cf7aiv-source">Stored source key</label><input id="cf7aiv-source" name="source" required></div>';
			echo '<div><label for="cf7aiv-form-ids">Form IDs (optional, comma-separated)</label><input id="cf7aiv-form-ids" name="form_ids" inputmode="numeric"></div>';
			echo '<div><label for="cf7aiv-date-from">From</label><input id="cf7aiv-date-from" type="date" name="date_from" required></div>';
			echo '<div><label for="cf7aiv-date-to">To</label><input id="cf7aiv-date-to" type="date" name="date_to" required></div>';
			echo '<div><label for="cf7aiv-maximum">Sample size</label><input id="cf7aiv-maximum" type="number" name="maximum" min="1" max="500" value="' . $sample . '" required></div></div>';
			echo '<label class="cf7aiv-setup-choice"><input type="checkbox" name="explicit_opt_in" value="1" required> I explicitly authorize this calibration-only historical sample.</label><p class="cf7aiv-actions"><button type="submit" class="button button-secondary">Schedule historical calibration</button></p></form></div>';
		}
		echo '<p class="cf7aiv-setup-step-actions"><button type="button" class="button button-secondary" data-setup-back="review">Back to review</button></p></section></div>';
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

	/** @param array<string,mixed> $pack */
	private static function primaryDefinition(array $pack): string {
		foreach ((array) ($pack['tier_definitions'] ?? []) as $tier) {
			if (is_array($tier) && ($tier['outcome_id'] ?? '') === 'primary_opportunity') {
				return (string) ($tier['description'] ?? '');
			}
		}
		return '';
	}

	/** @return list<string> */
	private static function stringList(mixed $values): array {
		$out = [];
		foreach ((array) $values as $value) {
			if (is_scalar($value) && trim((string) $value) !== '') {
				$out[] = trim((string) $value);
			}
		}
		return array_values(array_unique($out));
	}

	private static function displayList(mixed $values, string $empty): string {
		$list = self::stringList($values);
		return $list === [] ? $empty : implode(', ', $list);
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
			$html .= '<label class="cf7aiv-setup-choice"><input type="checkbox" name="' . self::e($name) . '" value="' . self::e($value) . '" checked> <span>' . self::e(str_replace('_', ' ', $value)) . '</span></label>';
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
