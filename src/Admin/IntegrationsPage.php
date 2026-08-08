<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Contracts\VerificationCapableProviderInterface;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Providers\DeepSeekProvider;
use CF7AIV\Providers\OpenAIProvider;

final class IntegrationsPage {
	public static function render(): void {
		if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {
			throw new \RuntimeException('Administrator capability is required.');
		}

		$settings = Settings::withDefaults(function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : []);
		$providers = (array) ($settings['providers'] ?? []);
		$destinations = (array) ($settings['destinations'] ?? []);
		$privacy = (array) ($settings['privacy'] ?? []);
		$consent = (array) ($settings['consent'] ?? []);
		$advanced = ! empty($settings['ui']['advanced_mode']);
		$selectedProvider = in_array((string) ($providers['selected'] ?? 'deepseek'), ['deepseek', 'openai'], true) ? (string) $providers['selected'] : 'deepseek';
		$deepSeekModel = (string) ($providers['deepseek_model'] ?? 'deepseek-v4-flash');
		$openAiModel = (string) ($providers['openai_model'] ?? 'gpt-5.4-mini');
		$selectedModel = $selectedProvider === 'openai' ? $openAiModel : $deepSeekModel;
		$verificationSupported = self::verificationSupported($selectedProvider);
		$activeTab = self::activeTab($_GET['tab'] ?? null);
		$saveStatus = self::queryStatus($_GET['saved'] ?? null, $_GET['failed'] ?? null);
		$testStatus = is_scalar($_GET['microsoft_test'] ?? null) ? (string) $_GET['microsoft_test'] : '';
		$stage = (string) ($settings['setup']['stage'] ?? 'setup');
		$automationMode = (string) ($settings['automation']['mode'] ?? 'shadow');
		$destinationSafety = self::destinationSafety($destinations);

		echo '<div class="wrap cf7aiv-admin"><div class="cf7aiv-admin cf7aiv-integrations-page" data-active-tab="' . self::e($activeTab) . '">';
		echo '<div class="cf7aiv-page-header"><h1>Integrations</h1><p>Credentials are encrypted and never displayed again. Each field shows whether one is stored; leaving a field blank keeps it.</p></div>';
		echo '<div class="cf7aiv-integration-context" aria-label="Integration status">';
		echo self::statusBadge('Lifecycle: ' . ucfirst($stage), $stage === 'live' ? 'current' : 'review');
		echo self::statusBadge('Automation: ' . ucfirst($automationMode), $automationMode === 'live' ? 'warning' : '');
		echo self::statusBadge('AI provider: ' . ucfirst($selectedProvider), '');
		echo self::statusBadge($destinationSafety['label'], $destinationSafety['tone']);
		echo '</div>';

		if ($saveStatus === 'saved') {
			echo '<div class="notice notice-success" role="status" aria-live="polite"><p>Integration settings saved.</p></div>';
		} elseif ($saveStatus === 'failed') {
			echo '<div class="notice notice-error" role="alert" aria-live="assertive"><p>Integration settings could not be saved. Review the fields and try again.</p></div>';
		}
		/*
		 * A destination that cannot go live is a page-level fact, not a detail
		 * inside one tab. The reason previously rendered only inside the Google
		 * panel, so landing on another tab hid it entirely.
		 */
		$ga4Config = (array) ($destinations['ga4'] ?? []);
		if (! empty($ga4Config['enabled']) && ! Ga4Validation::isPassed() && Ga4Validation::error() !== '') {
			echo '<div class="notice notice-error cf7aiv-ga4-blocked" role="alert"><p><strong>GA4 delivery is blocked.</strong> Debug validation has not passed, so this destination stays in dry-run. GA4 said: <code>'
				. self::e(Ga4Validation::error()) . '</code></p></div>';
		}
		$testNotices = [
			'success' => 'Microsoft accepted the controlled zero-value transport event.',
			'retryable' => 'The Microsoft transport test encountered a temporary error; review health logs before retrying.',
			'failed' => 'The Microsoft transport test was rejected; verify the tag ID and authentication key.',
			'refused' => 'The Microsoft transport test was not sent because its exact confirmation phrase was missing.',
		];
		if (isset($testNotices[$testStatus])) {
			echo '<div class="notice"><p>' . self::e($testNotices[$testStatus]) . '</p></div>';
		}

		self::tabNavigation($activeTab);
		echo '<form id="cf7aiv-integrations-form" class="cf7aiv-integration-settings-form" method="post" action="' . self::e(admin_url('admin-post.php')) . '" data-settings-form>';
		echo '<input type="hidden" name="action" value="cf7aiv_save_settings">';
		wp_nonce_field('cf7aiv_settings');
		echo '<input type="hidden" name="return_tab" value="' . self::e($activeTab) . '" data-integration-return-tab>';
		self::saveBar($saveStatus, true);
		echo '<div class="cf7aiv-integration-tab-panels">';

		self::panelStart('provider', $activeTab);
		echo '<section class="card cf7aiv-integration-panel"><h2>AI provider</h2>' . self::inlineError($saveStatus, $activeTab, 'provider') . '<p class="description">Selected provider: ' . self::e($selectedProvider) . '; model: ' . self::e($selectedModel) . '.</p><div class="cf7aiv-form-grid"><label>Provider <select name="cf7aiv[providers][selected]" data-provider-selector>' . self::option('deepseek', $selectedProvider) . self::option('openai', $selectedProvider) . '</select></label></div>';
		self::providerFields('deepseek', $selectedProvider === 'deepseek', $deepSeekModel, (string) ($providers['deepseek_reasoning_effort'] ?? 'off'), (array) $providers);
		self::providerFields('openai', $selectedProvider === 'openai', $openAiModel, (string) ($providers['openai_reasoning_effort'] ?? 'unsupported'), (array) $providers);
		echo '<div class="cf7aiv-form-grid"><label>Timeout <input type="number" min="3" max="300" name="cf7aiv[providers][timeout]" value="' . (int) ($providers['timeout'] ?? 15) . '"></label></div>';
		echo '<p class="description">Higher reasoning effort can increase token use and cost. Optional automatic-candidate verification is a separate second provider call and does not override policy, consent, attribution, mapping, lifecycle, or delivery gates.</p>';
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
		echo '</div></fieldset>';
		if ($advanced) {
			$usage = self::usageTotals();
			echo '<details class="card cf7aiv-advanced"><summary>Provider usage diagnostics</summary><p>Token totals are provider diagnostics. No currency estimate is inferred.</p><table class="widefat striped"><tbody>';
			foreach (['calls' => 'Calls', 'input_tokens' => 'Input tokens', 'output_tokens' => 'Output tokens', 'reasoning_tokens' => 'Reasoning tokens'] as $key => $label) {
				echo '<tr><th scope="row">' . self::e($label) . '</th><td>' . (int) $usage[$key] . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
		echo '</section>';
		self::panelEnd();

		self::panelStart('google', $activeTab);
		echo '<section class="card cf7aiv-integration-panel"><h2>Google conversion route</h2>' . self::inlineError($saveStatus, $activeTab, 'google') . '<p>Use direct GA4 for the simplest setup. Existing server-side GTM is available in Advanced mode for sites that already operate a tagging server.</p>';
		self::destinationFields('ga4', 'GA4 Measurement Protocol — Recommended for most users', (array) ($destinations['ga4'] ?? []), ['measurement_id' => 'Measurement ID'], 'ga4_api_secret');
		if ($advanced) {
			self::destinationFields('server_gtm', 'Existing server-side GTM', (array) ($destinations['server_gtm'] ?? []), ['endpoint_url' => 'Tagging-server HTTPS base URL', 'measurement_id' => 'Measurement ID'], 'server_gtm_api_secret');
			echo '<p class="description">This route assumes an existing tagging server with its GA4 client, Conversion Linker, native Google Ads Conversion Tracking tag, Conversion ID and label, and a trigger limited to the mapped qualified event. Map the stable transaction ID for deduplication and use destination key <code>server_gtm</code>. The plugin does not provision Google Cloud and does not use the Google Ads API.</p>';
		}
		echo '</section>';
		self::panelEnd();

		self::panelStart('microsoft', $activeTab);
		echo '<section class="card cf7aiv-integration-panel"><h2>Microsoft UET</h2>' . self::inlineError($saveStatus, $activeTab, 'microsoft');
		self::destinationFields('microsoft_uet', 'Microsoft UET', (array) ($destinations['microsoft_uet'] ?? []), ['tag_id' => 'Tag ID'], 'microsoft_uet_token');
		echo '</section>';
		self::panelEnd();

		self::panelStart('webhook', $activeTab);
		echo '<section class="card cf7aiv-integration-panel"><h2>Webhook</h2>' . self::inlineError($saveStatus, $activeTab, 'webhook');
		self::destinationFields('webhook', 'Webhook', (array) ($destinations['webhook'] ?? []), ['url' => 'HTTPS URL'], 'webhook_secret', true);
		echo '</section>';
		self::panelEnd();

		self::panelStart('consent', $activeTab);
		echo '<section class="card cf7aiv-integration-panel"><h2>Consent &amp; privacy</h2>' . self::inlineError($saveStatus, $activeTab, 'consent') . '<p class="description">Ordinary retention, uninstall, interface, and enhanced-matching settings are saved together. Consent policy changes use a separate guarded action below.</p><h3>Interface mode</h3><div class="cf7aiv-form-grid"><input type="hidden" name="cf7aiv[ui][advanced_mode]" value="0">';
		echo self::checkbox('Advanced mode', 'cf7aiv[ui][advanced_mode]', $advanced) . '</div><p class="description">Advanced mode reveals additional technical policy and provider diagnostics. Hiding it does not discard saved settings.</p>';
		echo '<fieldset><legend>Privacy and retention</legend><div class="cf7aiv-form-grid"><label>Retention days <input type="number" min="1" max="3650" name="cf7aiv[privacy][retention_days]" value="' . (int) ($privacy['retention_days'] ?? 90) . '"></label>';
		echo self::checkbox('Delete plugin data on uninstall', 'cf7aiv[privacy][delete_on_uninstall]', ! empty($privacy['delete_on_uninstall']));
		echo self::checkbox('Enhanced matching', 'cf7aiv[privacy][enhanced_matching]', ! empty($privacy['enhanced_matching'])) . '</div></fieldset></section>';
		self::panelEnd();

		echo '</div>';
		self::saveBar($saveStatus, false);
		echo '</form>';

		self::renderGoogleHighRisk($activeTab);
		self::renderMicrosoftTest($activeTab);
		self::renderConsentPolicy($activeTab, $consent);
		echo '</div></div>';
	}

	private static function tabNavigation(string $activeTab): void {
		$labels = ['provider' => 'AI provider', 'google' => 'Google', 'microsoft' => 'Microsoft', 'webhook' => 'Webhook', 'consent' => 'Consent & privacy'];
		echo '<nav class="cf7aiv-integration-tabs" aria-label="Integration settings"><ul role="tablist">';
		foreach ($labels as $tab => $label) {
			$id = 'cf7aiv-integration-tab-' . $tab;
			$href = admin_url('admin.php?page=cf7aiv-integrations&tab=' . rawurlencode($tab));
			$isActive = $tab === $activeTab;
			echo '<li role="presentation"><a id="' . self::e($id) . '" role="tab" class="button ' . ($isActive ? 'is-active' : '') . '" href="' . self::e($href) . '" data-tab="' . self::e($tab) . '" aria-controls="cf7aiv-integration-panel-' . self::e($tab) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '">' . self::e($label) . '</a></li>';
		}
		echo '</ul></nav>';
	}

	private static function panelStart(string $tab, string $activeTab, string $suffix = ''): void {
		$id = 'cf7aiv-integration-panel-' . $tab . ($suffix === '' ? '' : '-' . $suffix);
		$isActive = $tab === $activeTab;
		echo '<div id="' . self::e($id) . '" class="cf7aiv-integration-tab-panel" data-tab="' . self::e($tab) . '" role="tabpanel" aria-labelledby="cf7aiv-integration-tab-' . self::e($tab) . '" aria-hidden="' . ($isActive ? 'false' : 'true') . '"' . ($isActive ? '' : ' hidden') . '>';
	}

	private static function panelEnd(): void {
		echo '</div>';
	}

	private static function providerFields(string $provider, bool $active, string $model, string $effort, array $providers): void {
		$label = $provider === 'openai' ? 'OpenAI' : 'DeepSeek';
		$baseKey = $provider . '_base_url';
		$modelKey = $provider . '_model';
		echo '<fieldset class="cf7aiv-provider-fields" data-provider="' . self::e($provider) . '" data-provider-panel="true" aria-hidden="' . ($active ? 'false' : 'true') . '"' . ($active ? '' : ' disabled hidden') . '><legend>' . self::e($label) . ' provider settings</legend><div class="cf7aiv-form-grid">';
		echo self::text($label . ' model', 'cf7aiv[providers][' . $modelKey . ']', $model);
		echo self::text($label . ' base URL', 'cf7aiv[providers][' . $baseKey . ']', (string) ($providers[$baseKey] ?? ''));
		echo self::secret($label . ' API key', 'cf7aiv[providers][' . $provider . '_api_key]', $provider . '_api_key');
		echo '</div>' . self::reasoningControl($provider, $model, $effort) . '</fieldset>';
	}

	private static function renderGoogleHighRisk(string $activeTab): void {
		self::panelStart('google', $activeTab, 'high-risk');
		echo '<section class="card cf7aiv-integration-panel cf7aiv-high-risk"><h2>High-risk actions</h2><p>These actions remain separate from Save changes and require their own confirmation, nonce, and server-side safety checks.</p>';
		echo '<fieldset><legend>Automation lifecycle</legend>';
		self::transitionForm('cf7aiv_automation_transition', 'cf7aiv_automation_transition', 'Enable live automation', 'live', 'google');
		self::transitionForm('cf7aiv_automation_transition', 'cf7aiv_automation_transition', 'Return automation to shadow', 'shadow', 'google');
		echo '<p>Live automation is an explicit transition. Classification and delivery safeguards still apply.</p></fieldset>';

		echo '<fieldset><legend>GA4 delivery lifecycle</legend><h3>GA4 validation and live transition</h3>';
		/*
		 * Validation writes debug_validated and then said nothing. The button
		 * could be pressed and there was no way to learn whether it had worked,
		 * which also made the next step opaque: live delivery silently refuses
		 * unless this has passed, so an operator could enable live, see no error,
		 * and still be in dry-run.
		 */
		$ga4 = (array) ($destinations['ga4'] ?? []);
		$validated = Ga4Validation::isPassed();
		$pending = ! $validated && Ga4Validation::isPending();
		echo '<p class="cf7aiv-ga4-validation-state">Debug validation: '
			. ($validated
				? AdminUi::badge('Passed', 'positive')
				: ($pending ? AdminUi::badge('Checking', 'neutral') : AdminUi::badge('Not validated', 'warning')))
			. ' <span class="description">'
			. self::e($validated
				? 'The Measurement ID and API secret were accepted by GA4. Live delivery can be enabled.'
				: ($pending
					? 'The check is queued and runs in the background. Reload this page in a moment for the result; it is also recorded in Tracking Health and Logs.'
					: 'Live delivery stays in dry-run until this passes. Validation sends one zero-value, consent-denied event to the GA4 debug endpoint, which validates without recording it.'))
			. '</span></p>';
		// GA4's debug endpoint exists to say what is wrong, so its answer is shown
		// rather than reduced to "failed". Read once, so a fixed problem stops
		// being reported as if it were current.
		$ga4Reason = Ga4Validation::error();
		if (! $validated && $ga4Reason !== '') {
			echo '<p class="cf7aiv-ga4-validation-reason notice notice-error"><strong>GA4 said:</strong> <code>' . self::e($ga4Reason) . '</code></p>';
		}
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_validate_ga4">';
		wp_nonce_field('cf7aiv_validate_ga4');
		echo '<input type="hidden" name="return_tab" value="google"><p class="cf7aiv-actions"><button class="button button-secondary" type="submit">Validate GA4 debug endpoint</button></p></form>';
		self::transitionForm('cf7aiv_ga_transition', 'cf7aiv_ga_transition', 'Enable GA4 live delivery', 'live', 'google');
		self::transitionForm('cf7aiv_ga_transition', 'cf7aiv_ga_transition', 'Disable GA4 live / return to dry-run', 'shadow', 'google');
		echo '</fieldset>';

		echo '<fieldset><legend>Destination mapping lifecycle</legend><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_save_mapping">';
		wp_nonce_field('cf7aiv_mapping');
		echo '<input type="hidden" name="return_tab" value="google"><div class="cf7aiv-form-grid"><label for="cf7aiv-mapping-key">Mapping key</label><input id="cf7aiv-mapping-key" name="mapping[key]">';
		echo '<label for="cf7aiv-mapping-version">Mapping version</label><input id="cf7aiv-mapping-version" name="mapping[version]">';
		echo '<label for="cf7aiv-mapping-tier">Tier</label><input id="cf7aiv-mapping-tier" name="mapping[tier_id]">';
		echo '<label for="cf7aiv-mapping-destination">Destination</label><input id="cf7aiv-mapping-destination" name="mapping[destination_key]">';
		echo '<label for="cf7aiv-mapping-event">Event name</label><input id="cf7aiv-mapping-event" name="mapping[event_name]">';
		echo '<label for="cf7aiv-mapping-value">Value</label><input id="cf7aiv-mapping-value" name="mapping[value]" type="number" step="0.01">';
		echo '<label for="cf7aiv-mapping-currency">Currency</label><input id="cf7aiv-mapping-currency" name="mapping[currency]" value="GBP"></div><p class="cf7aiv-actions"><button class="button button-secondary" type="submit">Save draft mapping</button></p></form>';
		self::mappingTransition('cf7aiv_mapping_publish', 'cf7aiv_mapping_publish', 'Publish mapping', 'google');
		self::mappingTransition('cf7aiv_mapping_enable', 'cf7aiv_mapping_enable', 'Enable published mapping', 'google');
		self::mappingTransition('cf7aiv_mapping_disable', 'cf7aiv_mapping_disable', 'Disable mapping', 'google');
		echo '</fieldset></section>';
		self::panelEnd();
	}

	private static function renderMicrosoftTest(string $activeTab): void {
		self::panelStart('microsoft', $activeTab, 'test');
		echo '<section class="card cf7aiv-integration-panel cf7aiv-high-risk"><h2>Microsoft UET transport test</h2><p>Sends one zero-value, consent-denied diagnostic event without changing Microsoft settings or mappings. It includes no click ID or enhanced identity and uses only a one-off synthetic anonymous ID required by CAPI. Type <code>SEND_ZERO_VALUE_MICROSOFT_TEST</code> to confirm.</p><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_test_microsoft">';
		wp_nonce_field('cf7aiv_test_microsoft');
		echo '<input type="hidden" name="return_tab" value="microsoft"><div class="cf7aiv-form-grid"><label for="cf7aiv-microsoft-confirmation">Confirmation</label><input id="cf7aiv-microsoft-confirmation" name="confirmation" autocomplete="off" value=""></div><p class="cf7aiv-actions"><button class="button cf7aiv-safety-action" type="submit">Send controlled Microsoft test</button></p></form></section>';
		self::panelEnd();
	}

	private static function renderConsentPolicy(string $activeTab, array $consent): void {
		$mode = (string) ($consent['mode'] ?? 'respect');
		self::panelStart('consent', $activeTab, 'policy');
		echo '<section class="card cf7aiv-integration-panel cf7aiv-high-risk"><h2>Consent policy</h2><p>Consent policy changes are audited separately because they can alter the legal basis used for external delivery.</p><form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="cf7aiv_save_consent">';
		wp_nonce_field('cf7aiv_save_consent');
		echo '<input type="hidden" name="return_tab" value="consent"><div class="cf7aiv-form-grid"><label for="cf7aiv-consent-mode">Consent mode</label><select id="cf7aiv-consent-mode" name="mode">' . self::option('respect', $mode) . self::option('always_actual', $mode) . self::option('legal_basis_override', $mode) . '</select><label>Override reason <input name="reason" value=""></label></div><p class="cf7aiv-actions"><button class="button button-secondary" type="submit">Save consent policy and audit</button></p></form></section>';
		self::panelEnd();
	}

	private static function saveBar(string $status, bool $top): void {
		$state = $status === 'failed' ? 'failed' : 'saved';
		$statusText = $state === 'failed' ? 'Save failed' : ($status === 'saved' ? 'Saved just now' : 'Saved');
		$classes = 'cf7aiv-save-bar' . ($top ? ' cf7aiv-save-bar-top' : '');
		echo '<div class="' . $classes . '" data-dirty="' . ($state === 'failed' ? 'true' : 'false') . '" data-save-state="' . self::e($state) . '"><p class="cf7aiv-save-status" role="status" aria-live="polite" data-save-status>' . self::e($statusText) . '</p><div class="cf7aiv-actions"><button type="submit" class="button button-primary" name="cf7aiv-save" value="1">Save changes</button><button type="button" class="button button-secondary" name="cf7aiv-discard" value="1">Discard changes</button></div></div>';
	}

	private static function inlineError(string $status, string $activeTab, string $group): string {
		if ($status !== 'failed' || $activeTab !== $group) {
			return '';
		}
		$labels = [
			'provider' => 'AI provider',
			'google' => 'Google integration',
			'microsoft' => 'Microsoft UET',
			'webhook' => 'Webhook',
			'consent' => 'Consent and privacy',
		];
		return '<p class="cf7aiv-inline-error" role="alert">Review the ' . self::e($labels[$group] ?? 'settings') . ' settings and try again.</p>';
	}

	private static function destinationFields(string $key, string $label, array $config, array $fields, string $secret, bool $insecure = false): void {
		echo '<fieldset><legend>' . self::e($label) . '</legend><div class="cf7aiv-form-grid">';
		foreach ($fields as $name => $fieldLabel) {
			echo self::text($fieldLabel, "cf7aiv[destinations][{$key}][{$name}]", (string) ($config[$name] ?? ''));
		}
		echo self::secret($label . ' secret', "cf7aiv[secrets][{$secret}]", $secret);
		echo self::checkbox('Enabled', "cf7aiv[destinations][{$key}][enabled]", ! empty($config['enabled']));
		/*
		 * GA4 leaves dry-run through the validated lifecycle transition below,
		 * never through this box, because live delivery is gated on debug
		 * validation having passed. The box used to accept the change and revert
		 * it on save with no explanation, which reads as a broken setting rather
		 * than a deliberate safeguard. It is now shown as governed elsewhere.
		 */
		if ($key === 'ga4') {
			echo self::checkbox(
				'Dry run',
				"cf7aiv[destinations][{$key}][dry_run]",
				! empty($config['dry_run']),
				'1',
				true,
				'Dry run is released by "Enable GA4 live delivery" below, which requires debug validation to have passed. It cannot be changed here.'
			);
		} else {
			echo self::checkbox('Dry run', "cf7aiv[destinations][{$key}][dry_run]", ! empty($config['dry_run']));
		}
		if ($insecure) {
			echo self::checkbox('Allow insecure URL (development only)', "cf7aiv[destinations][{$key}][allow_insecure]", ! empty($config['allow_insecure']));
		}
		echo '</div></fieldset>';
	}

	private static function transitionForm(string $action, string $nonce, string $label, string $transition, string $returnTab): void {
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . self::e($action) . '"><input type="hidden" name="transition" value="' . self::e($transition) . '"><input type="hidden" name="return_tab" value="' . self::e($returnTab) . '">';
		wp_nonce_field($nonce);
		echo '<p class="cf7aiv-actions"><button class="button cf7aiv-safety-action" type="submit">' . self::e($label) . '</button></p></form>';
	}

	private static function mappingTransition(string $action, string $nonce, string $label, string $returnTab): void {
		$keyId = $action . '-key';
		$versionId = $action . '-version';
		echo '<form method="post" action="' . self::e(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . self::e($action) . '">';
		wp_nonce_field($nonce);
		echo '<input type="hidden" name="return_tab" value="' . self::e($returnTab) . '"><div class="cf7aiv-form-grid"><label for="' . self::e($keyId) . '">Mapping key</label><input id="' . self::e($keyId) . '" name="mapping_key"><label for="' . self::e($versionId) . '">Mapping version</label><input id="' . self::e($versionId) . '" name="version"></div><p class="cf7aiv-actions"><button class="button cf7aiv-safety-action" type="submit">' . self::e($label) . '</button></p></form>';
	}

	private static function text(string $label, string $name, string $value): string {
		return '<label>' . self::e($label) . ' <input name="' . self::e($name) . '" value="' . self::e($value) . '"></label>';
	}

	/**
	 * A credential field that says whether a credential is stored.
	 *
	 * The value is never rendered and never will be. But the field used to render
	 * identically whether a key was saved or not, so a fully configured site
	 * looked exactly like an empty one. Administrators read that as a failed save,
	 * re-entered the key, and then feared that saving with the field blank would
	 * erase it - which it does not, because a blank secret is skipped rather than
	 * stored. Nothing on the screen said so.
	 *
	 * So the state is shown instead of the value: a badge, a masked placeholder
	 * that makes a configured field look configured, and an explicit sentence
	 * about what blank means. The dots are a placeholder attribute, never a value
	 * - as a value they would be submitted and saved as the new key.
	 *
	 * Removal is offered here too. Because blank means "keep", there was no way
	 * to take a stored credential out again short of editing the database.
	 */
	private static function secret(string $label, string $name, string $secretKey = ''): string {
		$stored = $secretKey !== '' && self::secretIsStored($secretKey);
		$fieldId = 'cf7aiv-secret-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
		$describedBy = $fieldId . '-help';

		$html = '<div class="cf7aiv-secret-field">';
		$html .= '<label for="' . self::e($fieldId) . '">' . self::e($label) . ' ';
		$html .= $stored
			? AdminUi::badge('Stored', 'positive')
			: AdminUi::badge('Not set', 'neutral');
		$html .= '</label>';
		$html .= '<input type="password" autocomplete="new-password" id="' . self::e($fieldId) . '"'
			. ' name="' . self::e($name) . '" value=""'
			. ' aria-describedby="' . self::e($describedBy) . '"'
			. ($stored ? ' placeholder="' . str_repeat('&bull;', 24) . '"' : '')
			. '>';
		$html .= '<p class="cf7aiv-secret-help" id="' . self::e($describedBy) . '">'
			. ($stored
				? 'A credential is stored and is never displayed. Leave this blank to keep it, or type a new one to replace it.'
				: 'No credential is stored yet. It is encrypted when saved and is never displayed again.')
			. '</p>';
		if ($stored && $secretKey !== '') {
			$html .= self::checkbox('Remove the stored credential', "cf7aiv[secrets_clear][{$secretKey}]", false);
		}
		return $html . '</div>';
	}

	/**
	 * Whether a credential exists, without ever reading its value into the page.
	 *
	 * A constant counts as stored: Crypto::secret() prefers one over the database,
	 * so reporting "not set" while a constant is in force would be a lie.
	 */
	private static function secretIsStored(string $secretKey): bool {
		if (defined('CF7AIV_' . strtoupper($secretKey))) { return true; }
		$stored = function_exists('get_option') ? (array) get_option('cf7aiv_secrets', []) : [];
		return isset($stored[$secretKey]) && trim((string) $stored[$secretKey]) !== '';
	}

	/**
	 * A checkbox whose cleared state actually reaches the server.
	 *
	 * An unchecked box is not submitted at all, and the sanitizer only overwrites
	 * a flag when its key is present - it starts from Settings::defaults() and
	 * keeps the default otherwise. Any flag defaulting to true was therefore
	 * impossible to turn off: it silently reverted on save, which is exactly what
	 * happened to the GA4 dry-run flag. The companion hidden field is the
	 * standard fix and is already used for advanced_mode; it was simply never
	 * applied to the destination flags.
	 *
	 * A disabled control is not submitted either, so when one is disabled the
	 * hidden field carries the current value rather than a cleared one. Without
	 * that, disabling the GA4 dry-run box would submit "off" and take the
	 * destination out of dry-run - the precise opposite of the intent.
	 */
	private static function checkbox(string $label, string $name, bool $checked, string $value = '1', bool $disabled = false, string $note = ''): string {
		$hidden = $disabled ? ($checked ? $value : '0') : '0';
		$html = '<label><input type="hidden" name="' . self::e($name) . '" value="' . self::e($hidden) . '">';
		$html .= '<input type="checkbox" name="' . self::e($name) . '" value="' . self::e($value) . '" ' . ($checked ? 'checked' : '') . ($disabled ? ' disabled' : '') . '> ' . self::e($label) . '</label>';
		if ($note !== '') {
			$html .= '<p class="description cf7aiv-checkbox-note">' . self::e($note) . '</p>';
		}
		return $html;
	}

	private static function option(string $value, string $selected): string {
		return '<option value="' . self::e($value) . '" ' . ($value === $selected ? 'selected' : '') . '>' . self::e($value) . '</option>';
	}

	private static function reasoningControl(string $provider, string $model, string $effort): string {
		$isOpenAi = $provider === 'openai';
		$options = $isOpenAi ? OpenAIProvider::reasoningOptions($model) : DeepSeekProvider::reasoningOptions($model);
		$selected = in_array($effort, $options, true) ? $effort : (in_array('unsupported', $options, true) ? 'unsupported' : ($options[0] ?? 'unsupported'));
		$description = $isOpenAi ? OpenAIProvider::reasoningDescription($model, $selected) : DeepSeekProvider::reasoningDescription($model, $selected);
		$disabled = $options === ['unsupported'];
		$name = "cf7aiv[providers][{$provider}_reasoning_effort]";
		$html = '<fieldset data-provider="' . self::e($provider) . '" class="cf7aiv-reasoning-control"><legend>Reasoning effort</legend><label>' . self::e(ucfirst($provider)) . ' reasoning <select' . ($disabled ? '' : ' name="' . self::e($name) . '"') . ($disabled ? ' disabled' : '') . '>';
		foreach ($options as $option) {
			$label = $option === 'unsupported' ? 'Unavailable' : ucfirst($option);
			$html .= '<option value="' . self::e($option) . '" ' . ($option === $selected ? 'selected' : '') . '>' . self::e($label) . '</option>';
		}
		$html .= '</select></label><p class="description">' . self::e($description) . '</p></fieldset>';
		if ($disabled) {
			$html .= '<input type="hidden" name="' . self::e($name) . '" value="unsupported">';
		}
		return $html;
	}

	private static function verificationSupported(string $selected): bool {
		try {
			$providers = function_exists('apply_filters') ? (array) apply_filters('cf7aiv_ai_providers', []) : [];
			if (isset($providers[$selected])) {
				return $providers[$selected] instanceof VerificationCapableProviderInterface;
			}
			return $selected === 'deepseek' && is_a(DeepSeekProvider::class, VerificationCapableProviderInterface::class, true);
		} catch (\Throwable) {
			return false;
		}
	}

	private static function activeTab(mixed $value): string {
		$value = is_scalar($value) ? (string) $value : '';
		return in_array($value, ['provider', 'google', 'microsoft', 'webhook', 'consent'], true) ? $value : 'provider';
	}

	private static function queryStatus(mixed $saved, mixed $failed): string {
		if (is_scalar($failed) && (string) $failed === '1') {
			return 'failed';
		}
		return is_scalar($saved) && (string) $saved === '1' ? 'saved' : '';
	}

	private static function statusBadge(string $label, string $tone): string {
		$class = 'cf7aiv-status-badge' . ($tone === '' ? '' : ' is-' . self::e($tone));
		return '<span class="' . $class . '">' . self::e($label) . '</span>';
	}

	/** @param array<string,mixed> $destinations @return array{label:string,tone:string} */
	private static function destinationSafety(array $destinations): array {
		foreach (['ga4', 'server_gtm', 'microsoft_uet', 'webhook'] as $key) {
			$config = (array) ($destinations[$key] ?? []);
			if (! empty($config['enabled']) && empty($config['dry_run'])) {
				return ['label' => 'Live delivery enabled', 'tone' => 'warning'];
			}
		}
		return ['label' => 'All enabled destinations in dry-run', 'tone' => 'positive'];
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

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}
}
