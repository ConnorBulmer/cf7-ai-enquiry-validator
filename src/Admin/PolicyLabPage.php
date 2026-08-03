<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\PolicyPackRegistry;
use CF7AIV\Classification\StarterPolicy;
use CF7AIV\Infrastructure\Settings;

final class PolicyLabPage {
	public static function render(): void {
		self::capability();
		$settings = (array) get_option(Settings::OPTION, Settings::defaults());
		$advanced = ! empty($settings['ui']['advanced_mode']);
		$packs = (new PolicyPackRegistry())->all();
		global $wpdb;
		$suggestions = isset($wpdb->prefix) && method_exists($wpdb, 'get_var')
			? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cf7aiv_review_suggestions WHERE status='draft'")
			: 0;

		echo '<div class="wrap cf7aiv-admin"><div class="cf7aiv-page-header"><h1>Policy Lab</h1><p>Global and per-form policies save as drafts until explicitly published. Reviewer corrections remain draft suggestions.</p></div>';
		echo '<section class="card"><h2>Guided policy editor</h2><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_save_guided_policy"><input type="hidden" name="return_page" value="policy">';
		wp_nonce_field('cf7aiv_guided_policy');
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="cf7aiv-policy-pack">Starter pack</label></th><td><select id="cf7aiv-policy-pack" name="guided[pack_key]">';
		$selectedPack = (string) ($settings['setup']['selected_pack'] ?? array_key_first($packs));
		foreach ($packs as $key => $pack) {
			echo '<option value="' . self::e((string) $key) . '"' . ($selectedPack === (string) $key ? ' selected' : '') . '>' . self::e((string) ($pack['pack']['label'] ?? $key)) . '</option>';
		}
		echo '</select><input type="hidden" name="guided[policy_key]" value="global"></td></tr>';
		echo '<tr><th scope="row"><label for="cf7aiv-simple-version">Draft reference</label></th><td><input id="cf7aiv-simple-version" name="guided[version]" value="' . self::e(gmdate('Ymd-His')) . '" required></td></tr>';
		echo '<tr><th scope="row"><label for="cf7aiv-simple-business">Business</label></th><td><textarea id="cf7aiv-simple-business" name="guided[business_description]" rows="3" class="large-text" required></textarea></td></tr>';
		echo '<tr><th scope="row"><label for="cf7aiv-simple-services">Services</label></th><td><textarea id="cf7aiv-simple-services" name="guided[services]" rows="3" class="large-text"></textarea><p class="description">One service or product per line.</p></td></tr>';
		echo '<tr><th scope="row"><label for="cf7aiv-simple-audiences">Audiences</label></th><td><textarea id="cf7aiv-simple-audiences" name="guided[audiences]" rows="3" class="large-text"></textarea><p class="description">One intended audience per line.</p></td></tr>';
		echo '<tr><th scope="row"><label for="cf7aiv-simple-tier">Tiers</label></th><td><textarea id="cf7aiv-simple-tier" name="guided[primary_definition]" rows="3" class="large-text"></textarea><p class="description">Describe the primary opportunity; the starter pack retains the remaining tier structure and ambiguity rules.</p></td></tr>';
		echo '<tr><th scope="row">Exclusions</th><td><fieldset><legend class="screen-reader-text">Exclusions</legend>' . self::guidedCheckboxes($packs, 'exclusion_catalog', 'guided[exclusion_codes][]') . '</fieldset></td></tr>';
		echo '<tr><th scope="row">Field whitelist</th><td><fieldset><legend class="screen-reader-text">Field whitelist</legend>' . self::guidedCheckboxes($packs, 'ai_whitelist', 'guided[ai_whitelist][]') . '</fieldset><p class="description">Terminology and non-identifying Examples remain inherited from the validated starter pack unless edited in Advanced mode.</p></td></tr>';
		echo '</tbody></table><p class="cf7aiv-actions"><button type="submit" class="button button-primary">Save guided draft</button></p></form></section>';

		echo '<section class="card"><h2>Calibration summary</h2><p>' . $suggestions . ' draft correction suggestions. Quality percentages are shown only for human-reviewed samples on Overview.</p></section>';
		$summary = (array) ($settings['setup']['draft_summary'] ?? []);
		if (($summary['status'] ?? '') === 'draft' && (string) ($summary['policy_key'] ?? '') !== '' && (string) ($summary['version'] ?? '') !== '') {
			echo '<section class="card"><h2>Publish reviewed draft</h2><p>Publication is explicit and does not enable automation.</p><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_policy_publish"><input type="hidden" name="policy_key" value="' . self::e((string) $summary['policy_key']) . '"><input type="hidden" name="version" value="' . self::e((string) $summary['version']) . '">';
			wp_nonce_field('cf7aiv_policy_publish');
			echo '<p class="cf7aiv-actions"><button type="submit" class="button cf7aiv-safety-action">Publish reviewed draft</button></p></form></section>';
		}

		if ($advanced) {
			$starter = PolicyLab::export(StarterPolicy::definition());
			echo '<section class="card cf7aiv-advanced"><h2>Advanced policy controls</h2><h3>Version and Evidence rules</h3><p>Evidence rules and version metadata are diagnostic policy controls.</p>';
			echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_policy_import">';
			wp_nonce_field('cf7aiv_policy');
			echo '<div class="cf7aiv-form-grid"><label for="cf7aiv-policy-key">Policy key</label><input id="cf7aiv-policy-key" name="policy_key" value="global">';
			echo '<label for="cf7aiv-policy-version">Version</label><input id="cf7aiv-policy-version" name="version" value="' . self::e(gmdate('Ymd-His')) . '">';
			echo '<label for="cf7aiv-policy-json">Policy JSON</label><textarea id="cf7aiv-policy-json" name="policy_json" rows="24" class="large-text code">' . self::e($starter) . '</textarea></div><p class="cf7aiv-actions"><button type="submit" class="button button-secondary">Save draft</button></p></form>';
			echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_policy_publish">';
			wp_nonce_field('cf7aiv_policy_publish');
			echo '<div class="cf7aiv-form-grid"><label for="cf7aiv-publish-key">Policy key</label><input id="cf7aiv-publish-key" name="policy_key" value="global"><label for="cf7aiv-publish-version">Draft version</label><input id="cf7aiv-publish-version" name="version" required></div><p class="cf7aiv-actions"><button type="submit" class="button cf7aiv-safety-action">Publish selected draft</button></p></form>';
			echo '<h3>Assistant brief</h3><textarea rows="8" class="large-text code" readonly>' . self::e(PolicyLab::assistantBrief(StarterPolicy::definition())) . '</textarea></section>';
		}
		echo '</div>';
	}

	private static function capability(): void {
		if (! current_user_can('manage_options')) {
			throw new \RuntimeException('Administrator capability is required.');
		}
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

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}
}
