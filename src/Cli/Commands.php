<?php

declare(strict_types=1);

namespace CF7AIV\Cli;

use CF7AIV\Admin\CalibrationService;
use CF7AIV\Admin\CalibrationPolicySnapshot;
use CF7AIV\Admin\DiagnosticsService;
use CF7AIV\Admin\HealthService;
use CF7AIV\Classification\PolicyRepository;
use CF7AIV\Contracts\AIProviderInterface;
use CF7AIV\Infrastructure\Migration;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Integration\GA4TestService;
use CF7AIV\Integration\MicrosoftUetTestService;
use CF7AIV\Integration\OperationalSafety;
use CF7AIV\Integration\PipelineSelfTest;
use CF7AIV\Integration\PilotDefaults;
use InvalidArgumentException;
use Throwable;

final class Commands {
	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function status(array $args, array $assocArgs): void {
		$settings = $this->settings();
		$sources = function_exists('apply_filters') ? (array) apply_filters('cf7aiv_submission_sources', []) : [];
		$this->json([
			'plugin_version' => defined('CF7AIV\\VERSION') ? \CF7AIV\VERSION : 'unknown',
			'schema_expected' => Migration::VERSION,
			'schema_installed' => function_exists('get_option') ? get_option('cf7aiv_schema_version', null) : null,
			'automation_mode' => $settings['automation']['mode'] ?? 'shadow',
			'destination_dry_run' => array_map(static fn (array $destination): bool => ! empty($destination['dry_run']), (array) ($settings['destinations'] ?? [])),
			'sources' => array_keys($sources),
			'action_scheduler' => function_exists('as_enqueue_async_action'),
		]);
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function migrate(array $args, array $assocArgs): void {
		Migration::migrate();
		$installed = function_exists('get_option') ? (int) get_option('cf7aiv_schema_version', 0) : 0;
		if ($installed !== (int) Migration::VERSION) {
			\WP_CLI::error('Database migration did not reach schema ' . Migration::VERSION . '; resolve the reported database/index issue and retry.');
		}
		\WP_CLI::success('Database migration completed at schema ' . Migration::VERSION . '.');
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function health(array $args, array $assocArgs): void {
		$this->json((new HealthService())->inspect());
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function doctor(array $args, array $assocArgs): void {
		$this->json((new DiagnosticsService())->inspect());
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function diagnose(array $args, array $assocArgs): void {
		$itemId = (int) ($assocArgs['item'] ?? ($args[0] ?? 0));
		$this->json((new DiagnosticsService())->diagnose($itemId));
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function calibrate(array $args, array $assocArgs): void {
		foreach (['source', 'date-from', 'date-to', 'maximum', 'explicit-opt-in'] as $required) {
			if (! array_key_exists($required, $assocArgs)) { \WP_CLI::error("Calibration requires --{$required}."); }
		}
		$sourceKey = (string) $assocArgs['source'];
		$rawForms = $assocArgs['form-ids'] ?? [];
		if (! is_array($rawForms)) { $rawForms = preg_split('/\s*,\s*/', (string) $rawForms, -1, PREG_SPLIT_NO_EMPTY) ?: []; }
		$explicit = in_array($assocArgs['explicit-opt-in'] ?? null, [true, 1, '1', 'true', 'yes', 'on'], true);
		$snapshot = CalibrationPolicySnapshot::active();
		$criteria = [
			'explicit_opt_in' => $explicit,
			'form_ids' => $rawForms,
			'date_from' => (string) $assocArgs['date-from'],
			'date_to' => (string) $assocArgs['date-to'],
			'maximum' => (int) $assocArgs['maximum'],
			'ai_whitelist_snapshot' => $snapshot['fields'],
			'whitelist_fingerprint' => $snapshot['fingerprint'],
		];
		try { $scheduled = (new CalibrationService())->schedule($sourceKey, $criteria, 0); }
		catch (\InvalidArgumentException|\RuntimeException $exception) { \WP_CLI::error($exception->getMessage()); }
		\WP_CLI::success("Calibration-only stratified run scheduled for {$sourceKey} with an exact maximum of {$scheduled} records.");
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function backfill(array $args, array $assocArgs): void { $this->calibrate($args, $assocArgs); }

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function process_queue(array $args, array $assocArgs): void {
		if (! OperationalSafety::isSafe($this->settings()) && empty($assocArgs['allow-live'])) {
			\WP_CLI::error('Queue processing refused: automation must be shadow and every destination dry-run. Pass --allow-live only after an explicit production change review.');
		}
		if (! class_exists('ActionScheduler_QueueRunner')) { \WP_CLI::error('Action Scheduler queue runner is unavailable.'); }
		\ActionScheduler_QueueRunner::instance()->run('CF7AIV WP CLI');
		\WP_CLI::success('Action Scheduler queue pass completed.');
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function dry_run(array $args, array $assocArgs): void {
		$this->json(OperationalSafety::report($this->settings()));
		\WP_CLI::success('Read-only safety report completed; no AI or destination request was sent.');
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function ga4_test(array $args, array $assocArgs): void {
		$mode = trim((string) ($assocArgs['mode'] ?? 'validate'));
		$service = new GA4TestService();
		try {
			$result = match ($mode) {
				'validate' => $service->validate(),
				'transport' => $service->transport((string) ($assocArgs['confirm'] ?? '')),
				default => throw new InvalidArgumentException('GA4 test mode must be validate or transport.'),
			};
		} catch (InvalidArgumentException $error) {
			\WP_CLI::error($error->getMessage());
			return;
		}
		$this->json($result);
		if (empty($result['successful'])) {
			\WP_CLI::error($mode === 'transport' && empty($result['live_request_sent'])
				? 'GA4 strict preflight failed; no live test event was sent.'
				: 'GA4 controlled test failed.');
		}
		\WP_CLI::success($mode === 'transport'
			? 'One dedicated zero-value GA4 transport test was sent after strict validation.'
			: 'GA4 payload passed strict validation; no event was collected.');
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function microsoft_test(array $args, array $assocArgs): void {
		try {
			$result = (new MicrosoftUetTestService())->transport((string) ($assocArgs['confirm'] ?? ''));
		} catch (InvalidArgumentException $error) {
			\WP_CLI::error($error->getMessage());
			return;
		}
		$this->json($result);
		if (empty($result['successful'])) {
			\WP_CLI::error('Microsoft UET controlled transport test failed.');
		}
		\WP_CLI::success('One dedicated zero-value Microsoft UET transport test was sent.');
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function pipeline_test(array $args, array $assocArgs): void {
		$provider = null;
		if (! empty($assocArgs['use-configured-ai'])) {
			$settings = $this->settings();
			$selected = (string) ($settings['providers']['selected'] ?? 'deepseek');
			$providers = function_exists('apply_filters') ? (array) apply_filters('cf7aiv_ai_providers', []) : [];
			$provider = $providers[$selected] ?? null;
			if (! $provider instanceof AIProviderInterface) {
				\WP_CLI::error('The configured AI provider is unavailable.');
				return;
			}
		}
		try {
			$result = (new PipelineSelfTest($provider))->run();
		} catch (Throwable $error) {
			\WP_CLI::error('Isolated pipeline test failed: ' . $error->getMessage());
			return;
		}
		$this->json($result);
		if (empty($result['successful'])) {
			\WP_CLI::error('The isolated pipeline ran but did not satisfy every classification, privacy, eligibility, delivery, and idempotency assertion.');
		}
		\WP_CLI::success('The isolated classification-to-delivery pipeline passed without touching production items, mappings, or destinations.');
	}

	/** @param list<string> $args @param array<string, mixed> $assocArgs */
	public function pilot(array $args, array $assocArgs): void {
		$forms = [];
		if (function_exists('get_posts')) {
			foreach ((array) get_posts(['post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'numberposts' => -1]) as $form) {
				$forms[] = ['id' => (int) ($form->ID ?? 0), 'title' => (string) ($form->post_title ?? '')];
			}
		}
		$payload = [
			'policy' => PilotDefaults::policyDraft(),
			'form_profiles' => PilotDefaults::profilesForForms($forms),
			'delivery_mapping_drafts' => PilotDefaults::deliveryMappingDrafts(),
			'safe_settings' => PilotDefaults::safeSettings(),
		];
		if (! empty($assocArgs['create-policy-draft'])) {
			$draft = $payload['policy'];
			$id = (new PolicyRepository())->saveDraft($draft['policy_key'], $draft['version'], $draft['definition']);
			if ($id < 1) { \WP_CLI::error('Could not create the starter policy draft.'); }
			$payload['created_policy_draft_id'] = $id;
		}
		$this->json($payload);
		\WP_CLI::warning('Pilot helpers create suggestions/drafts only. Publish policy and mappings manually after review.');
	}

	/** @return array<string, mixed> */
	private function settings(): array {
		$settings = function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : [];
		return Settings::withDefaults($settings);
	}

	/** @param array<string, mixed> $value */
	private function json(array $value): void {
		\WP_CLI::log((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}
}
