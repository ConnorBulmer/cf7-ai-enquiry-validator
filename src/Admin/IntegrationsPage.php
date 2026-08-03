<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Contracts\VerificationCapableProviderInterface;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Providers\DeepSeekProvider;

final class IntegrationsPage {
	public static function render(): void {
		if (! function_exists('current_user_can') || ! current_user_can('manage_options')) { throw new \RuntimeException('Administrator capability is required.'); }
		$settings = function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : Settings::defaults();
		$providers = (array) ($settings['providers'] ?? []);
		$destinations = (array) ($settings['destinations'] ?? []);
		$privacy = (array) ($settings['privacy'] ?? []);
		$advanced = ! empty($settings['ui']['advanced_mode']);
		$selectedProvider = (string) ($providers['selected'] ?? 'deepseek');
		$verificationSupported = self::verificationSupported($selectedProvider);
		echo '<div class="wrap cf7aiv-admin"><div class="cf7aiv-page-header"><h1>Integrations</h1><p>All credentials are write-only. Blank secret fields retain the currently stored encrypted value.</p></div>';
		$testStatus = is_scalar($_GET['microsoft_test'] ?? null) ? (string) $_GET['microsoft_test'] : '';
		$testNotices = [
			'success' => 'Microsoft accepted the controlled zero-value transport event.',
			'retryable' => 'The Microsoft transport test encountered a temporary error; review health logs before retrying.',
			'failed' => 'The Microsoft transport test was rejected; verify the tag ID and authentication key.',
			'refused' => 'The Microsoft transport test was not sent because its exact confirmation phrase was missing.',
		];
		if (isset($testNotices[$testStatus])) { echo '<div class="notice"><p>' . self::e($testNotices[$testStatus]) . '</p></div>'; }
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_save_settings">';
		wp_nonce_field('cf7aiv_settings');
		echo '<section class="card cf7aiv-integration-panel"><h2>Interface mode</h2><div class="cf7aiv-form-grid"><input type="hidden" name="cf7aiv[ui][advanced_mode]" value="0">';
		echo self::checkbox('Advanced mode', 'cf7aiv[ui][advanced_mode]', $advanced) . '</div><p>Advanced mode reveals additional technical policy and provider diagnostics. Hiding it does not discard saved settings.</p></section>';
		echo '<section class="card cf7aiv-integration-panel"><h2>AI provider</h2><div class="cf7aiv-form-grid"><label>Provider <select name="cf7aiv[providers][selected]">' . self::option('deepseek', (string) ($providers['selected'] ?? 'deepseek')) . self::option('openai', (string) ($providers['selected'] ?? 'deepseek')) . '</select></label>';
		echo self::text('DeepSeek model', 'cf7aiv[providers][deepseek_model]', (string) ($providers['deepseek_model'] ?? ''));
		echo self::text('DeepSeek base URL', 'cf7aiv[providers][deepseek_base_url]', (string) ($providers['deepseek_base_url'] ?? ''));
		echo self::secret('DeepSeek API key', 'cf7aiv[providers][deepseek_api_key]');
		echo self::text('OpenAI model', 'cf7aiv[providers][openai_model]', (string) ($providers['openai_model'] ?? ''));
		echo self::text('OpenAI base URL', 'cf7aiv[providers][openai_base_url]', (string) ($providers['openai_base_url'] ?? ''));
		echo self::secret('OpenAI API key', 'cf7aiv[providers][openai_api_key]');
		echo '<label>Timeout <input type="number" min="3" max="60" name="cf7aiv[providers][timeout]" value="' . (int) ($providers['timeout'] ?? 15) . '"></label></div>';
		echo '<fieldset><legend>Optional verification</legend><div class="cf7aiv-form-grid">';
		if ($verificationSupported) {
			echo '<input type="hidden" name="cf7aiv[automation][verification_mode]" value="' . self::e(Settings::VERIFICATION_OFF) . '">';
			echo self::checkbox(
				'Verify automatic conversion candidates with an additional deeper AI call. This can improve caution but uses more tokens and may increase cost.',
				'cf7aiv[automation][verification_mode]',
				Settings::verificationMode($settings) === Settings::VERIFICATION_AUTOMATIC_CANDIDATES,
				Settings::VERIFICATION_AUTOMATIC_CANDIDATES
			);
		} else {
			echo '<p>Not supported by the selected provider</p>';
		}
		echo '</div></fieldset></section>';
		echo '<section class="card cf7aiv-integration-panel"><h2>Google conversion route</h2><p>Use direct GA4 for the simplest setup. Existing server-side GTM is available in Advanced mode for sites that already operate a tagging server.</p>';
		self::destinationFields('ga4', 'GA4 Measurement Protocol — Recommended for most users', (array) ($destinations['ga4'] ?? []), ['measurement_id' => 'Measurement ID'], 'ga4_api_secret');
		if ($advanced) {
			self::destinationFields('server_gtm', 'Existing server-side GTM', (array) ($destinations['server_gtm'] ?? []), ['endpoint_url' => 'Tagging-server HTTPS base URL', 'measurement_id' => 'Measurement ID'], 'server_gtm_api_secret');
			echo '<p class="description">This route assumes an existing tagging server with its GA4 client, Conversion Linker, native Google Ads Conversion Tracking tag, Conversion ID and label, and a trigger limited to the mapped qualified event. Map the stable transaction ID for deduplication and use destination key <code>server_gtm</code>. The plugin does not provision Google Cloud and does not use the Google Ads API.</p>';
		}
		echo '</section>';
		echo '<section class="card cf7aiv-integration-panel"><h2>Microsoft UET</h2>';
		self::destinationFields('microsoft_uet', 'Microsoft UET', (array) ($destinations['microsoft_uet'] ?? []), ['tag_id' => 'Tag ID'], 'microsoft_uet_token');
		echo '</section>';
		echo '<section class="card cf7aiv-integration-panel"><h2>Webhook</h2>';
		self::destinationFields('webhook', 'Webhook', (array) ($destinations['webhook'] ?? []), ['url' => 'HTTPS URL'], 'webhook_secret', true);
		echo '</section>';
		echo '<section class="card cf7aiv-integration-panel"><h2>Retention and uninstall</h2><div class="cf7aiv-form-grid"><label>Retention days <input type="number" min="1" max="3650" name="cf7aiv[privacy][retention_days]" value="' . (int) ($privacy['retention_days'] ?? 90) . '"></label>';
		echo self::checkbox('Delete plugin data on uninstall', 'cf7aiv[privacy][delete_on_uninstall]', ! empty($privacy['delete_on_uninstall']));
		echo self::checkbox('Enhanced matching', 'cf7aiv[privacy][enhanced_matching]', ! empty($privacy['enhanced_matching'])) . '</div><p class="cf7aiv-actions"><button class="button button-primary" type="submit">Save integration settings</button></p></section></form>';

		echo '<section class="card cf7aiv-integration-panel"><h2>Microsoft UET transport test</h2><p>Sends one zero-value, consent-denied diagnostic event without changing Microsoft settings or mappings. It includes no click ID or enhanced identity and uses only a one-off synthetic anonymous ID required by CAPI. Type <code>SEND_ZERO_VALUE_MICROSOFT_TEST</code> to confirm.</p><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_test_microsoft">';
		wp_nonce_field('cf7aiv_test_microsoft');
		echo '<div class="cf7aiv-form-grid"><label for="cf7aiv-microsoft-confirmation">Confirmation</label><input id="cf7aiv-microsoft-confirmation" name="confirmation" autocomplete="off" value=""></div><p class="cf7aiv-actions"><button class="button cf7aiv-safety-action" type="submit">Send controlled Microsoft test</button></p></form></section>';

		echo '<section class="card cf7aiv-integration-panel"><fieldset><legend>Automation lifecycle</legend>';
		self::transitionForm('cf7aiv_automation_transition', 'cf7aiv_automation_transition', 'Enable live automation', 'live');
		self::transitionForm('cf7aiv_automation_transition', 'cf7aiv_automation_transition', 'Return automation to shadow', 'shadow');
		echo '<p>Live automation is an explicit transition. Classification and delivery safeguards still apply.</p>';
		echo '</fieldset></section>';

		echo '<section class="card cf7aiv-integration-panel"><h2>Consent policy</h2><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_save_consent">';
		wp_nonce_field('cf7aiv_save_consent');
		echo '<div class="cf7aiv-form-grid"><label for="cf7aiv-consent-mode">Consent mode</label><select id="cf7aiv-consent-mode" name="mode"><option value="respect">respect</option><option value="always_actual">always_actual</option><option value="legal_basis_override">legal_basis_override</option></select><label>Override reason <input name="reason" value=""></label></div><p class="cf7aiv-actions"><button class="button button-secondary" type="submit">Save consent policy and audit</button></p></form></section>';

		echo '<section class="card cf7aiv-integration-panel"><fieldset><legend>GA4 delivery lifecycle</legend><h2>GA4 validation and live transition</h2><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_validate_ga4">'; wp_nonce_field('cf7aiv_validate_ga4'); echo '<p class="cf7aiv-actions"><button class="button button-secondary" type="submit">Validate GA4 debug endpoint</button></p></form>';
		self::transitionForm('cf7aiv_ga_transition', 'cf7aiv_ga_transition', 'Enable GA4 live delivery', 'live');
		self::transitionForm('cf7aiv_ga_transition', 'cf7aiv_ga_transition', 'Disable GA4 live / return to dry-run', 'shadow');
		echo '</fieldset></section>';

		echo '<section class="card cf7aiv-integration-panel"><h2>Destination mappings</h2><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_save_mapping">'; wp_nonce_field('cf7aiv_mapping');
		echo '<div class="cf7aiv-form-grid"><label for="cf7aiv-mapping-key">Mapping key</label><input id="cf7aiv-mapping-key" name="mapping[key]">';
		echo '<label for="cf7aiv-mapping-version">Mapping version</label><input id="cf7aiv-mapping-version" name="mapping[version]">';
		echo '<label for="cf7aiv-mapping-tier">Tier</label><input id="cf7aiv-mapping-tier" name="mapping[tier_id]">';
		echo '<label for="cf7aiv-mapping-destination">Destination</label><input id="cf7aiv-mapping-destination" name="mapping[destination_key]">';
		echo '<label for="cf7aiv-mapping-event">Event name</label><input id="cf7aiv-mapping-event" name="mapping[event_name]">';
		echo '<label for="cf7aiv-mapping-value">Value</label><input id="cf7aiv-mapping-value" name="mapping[value]" type="number" step="0.01">';
		echo '<label for="cf7aiv-mapping-currency">Currency</label><input id="cf7aiv-mapping-currency" name="mapping[currency]" value="GBP"></div><p class="cf7aiv-actions"><button class="button button-secondary" type="submit">Save draft mapping</button></p></form>';
		echo '<fieldset><legend>Destination mapping lifecycle</legend>';
		self::mappingTransition('cf7aiv_mapping_publish', 'cf7aiv_mapping_publish', 'Publish mapping');
		self::mappingTransition('cf7aiv_mapping_enable', 'cf7aiv_mapping_enable', 'Enable published mapping');
		self::mappingTransition('cf7aiv_mapping_disable', 'cf7aiv_mapping_disable', 'Disable mapping');
		echo '</fieldset></section>';
		if ($advanced) {
			$usage = self::usageTotals();
			echo '<details class="card cf7aiv-advanced"><summary>Provider usage diagnostics</summary><p>Token totals are provider diagnostics. No currency estimate is inferred.</p><table class="widefat striped"><tbody>';
			foreach (['calls' => 'Calls', 'input_tokens' => 'Input tokens', 'output_tokens' => 'Output tokens', 'reasoning_tokens' => 'Reasoning tokens'] as $key => $label) {
				echo '<tr><th scope="row">' . self::e($label) . '</th><td>' . (int) $usage[$key] . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
		echo '</div>';
	}

	/** @param array<string,mixed> $config @param array<string,string> $fields */
	private static function destinationFields(string $key, string $label, array $config, array $fields, string $secret, bool $insecure = false): void {
		echo '<fieldset><legend>' . self::e($label) . '</legend><div class="cf7aiv-form-grid">';
		foreach ($fields as $name => $fieldLabel) { echo self::text($fieldLabel, "cf7aiv[destinations][{$key}][{$name}]", (string) ($config[$name] ?? '')); }
		echo self::secret($label . ' secret', "cf7aiv[secrets][{$secret}]");
		echo self::checkbox('Enabled', "cf7aiv[destinations][{$key}][enabled]", ! empty($config['enabled']));
		echo self::checkbox('Dry run', "cf7aiv[destinations][{$key}][dry_run]", ! empty($config['dry_run']));
		if ($insecure) { echo self::checkbox('Allow insecure URL (development only)', "cf7aiv[destinations][{$key}][allow_insecure]", ! empty($config['allow_insecure'])); }
		echo '</div></fieldset>';
	}

	private static function transitionForm(string $action, string $nonce, string $label, string $transition): void { echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . self::e($action) . '"><input type="hidden" name="transition" value="' . self::e($transition) . '">'; wp_nonce_field($nonce); echo '<p class="cf7aiv-actions"><button class="button cf7aiv-safety-action" type="submit">' . self::e($label) . '</button></p></form>'; }
	private static function mappingTransition(string $action, string $nonce, string $label): void {
		$keyId = $action . '-key';
		$versionId = $action . '-version';
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . self::e($action) . '">';
		wp_nonce_field($nonce);
		echo '<div class="cf7aiv-form-grid"><label for="' . self::e($keyId) . '">Mapping key</label><input id="' . self::e($keyId) . '" name="mapping_key">';
		echo '<label for="' . self::e($versionId) . '">Mapping version</label><input id="' . self::e($versionId) . '" name="version"></div>';
		echo '<p class="cf7aiv-actions"><button class="button cf7aiv-safety-action" type="submit">' . self::e($label) . '</button></p></form>';
	}
	private static function text(string $label, string $name, string $value): string { return '<label>' . self::e($label) . ' <input name="' . self::e($name) . '" value="' . self::e($value) . '"></label>'; }
	private static function secret(string $label, string $name): string { return '<label>' . self::e($label) . ' <input type="password" autocomplete="new-password" name="' . self::e($name) . '" value=""></label>'; }
	private static function checkbox(string $label, string $name, bool $checked, string $value = '1'): string { return '<label><input type="checkbox" name="' . self::e($name) . '" value="' . self::e($value) . '" ' . ($checked ? 'checked' : '') . '> ' . self::e($label) . '</label>'; }
	private static function option(string $value, string $selected): string { return '<option value="' . self::e($value) . '" ' . ($value === $selected ? 'selected' : '') . '>' . self::e($value) . '</option>'; }
	private static function verificationSupported(string $selected): bool {
		$providers = function_exists('apply_filters') ? (array) apply_filters('cf7aiv_ai_providers', []) : [];
		if (isset($providers[$selected])) {
			return $providers[$selected] instanceof VerificationCapableProviderInterface;
		}
		return $selected === 'deepseek' && is_a(DeepSeekProvider::class, VerificationCapableProviderInterface::class, true);
	}

	/** @return array{calls:int,input_tokens:int,output_tokens:int,reasoning_tokens:int} */
	private static function usageTotals(): array {
		$totals = ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) {
			return $totals;
		}
		$rows = $wpdb->get_results("SELECT provider_usage_json FROM {$wpdb->prefix}cf7aiv_items WHERE provider_usage_json IS NOT NULL", ARRAY_A);
		foreach (is_array($rows) ? $rows : [] as $row) {
			$usage = json_decode((string) ($row['provider_usage_json'] ?? ''), true);
			if (! is_array($usage)) {
				continue;
			}
			foreach (array_keys($totals) as $key) {
				$totals[$key] += max(0, (int) ($usage[$key] ?? 0));
			}
		}
		return $totals;
	}
	private static function e(string $value): string { return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES); }
}
