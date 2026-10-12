<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

use CF7AIV\Providers\DeepSeekProvider;

final class Settings {
	public const OPTION = 'cf7aiv_settings';

	/**
	 * Bumped when stored settings need a one-time change on upgrade.
	 *
	 * 2: moves DeepSeek off retired model IDs, and the provider timeout off the
	 * old 15-second default.
	 */
	public const REVISION = 2;

	/**
	 * Seconds a single provider call may run.
	 *
	 * Every provider call runs in a background job, so the ceiling is sized for
	 * the slowest reasoning reply rather than for a browser. It must stay at or
	 * below Action Scheduler's failure period (300 seconds by default), which
	 * marks a job as failed while it is still running.
	 */
	public const MAX_PROVIDER_TIMEOUT = 300;

	/**
	 * 15 seconds was the default before 0.2.33. It suits a short JSON answer at a
	 * quiet hour, but a v2 classification quoting its evidence can run past it at
	 * peak load, and a timed-out call is retried from the start.
	 */
	public const DEFAULT_PROVIDER_TIMEOUT = 60;
	private const LEGACY_DEFAULT_PROVIDER_TIMEOUT = 15;

	/**
	 * DeepSeek-V4.1-Flash. Kept here rather than read from the provider so that
	 * building the defaults, which happens on every request, does not load the
	 * provider classes.
	 */
	public const DEFAULT_DEEPSEEK_MODEL = 'deepseek-flash';
	public const VERIFICATION_OFF = 'off';
	public const VERIFICATION_AUTOMATIC_CANDIDATES = 'automatic_candidates';
	public const VERIFICATION_STATES = ['off', 'not_required', 'passed', 'disagreed', 'failed', 'unsupported'];

	/** @param array<string,mixed> $settings */
	public static function verificationMode(array $settings): string {
		$mode = (string) ($settings['automation']['verification_mode'] ?? self::VERIFICATION_OFF);
		return $mode === self::VERIFICATION_AUTOMATIC_CANDIDATES ? $mode : self::VERIFICATION_OFF;
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return [
			'setup_complete' => false,
			'setup' => ['stage' => 'setup'],
			'ui' => ['advanced_mode' => false],
			'automation' => [
				'mode' => 'shadow',
				'verification_mode' => self::VERIFICATION_OFF,
			],
			'calibration' => ['default_sample_size' => 25],
			'privacy' => [
				'enhanced_matching' => false,
				'delete_on_uninstall' => false,
				'retention_days' => 90,
			],
			'attribution' => ['retention_days' => 90],
			'consent' => ['mode' => 'respect'],
			'delivery' => ['pause_threshold' => 3],
			'digest' => ['enabled' => false, 'email' => ''],
			'destinations' => [
				'ga4' => ['enabled' => false, 'live_enabled' => false, 'debug_validated' => false, 'dry_run' => true, 'last_validation_error' => '', 'validation_state' => 'idle'],
				'server_gtm' => ['enabled' => false, 'dry_run' => true],
				'microsoft_uet' => ['enabled' => false, 'dry_run' => true],
				'webhook' => ['enabled' => false, 'dry_run' => true, 'allow_insecure' => false],
			],
			'providers' => [
				'selected' => 'deepseek',
				'deepseek_reasoning_effort' => 'off',
				'openai_reasoning_effort' => 'unsupported',
				'deepseek_model' => self::DEFAULT_DEEPSEEK_MODEL,
				'deepseek_base_url' => 'https://api.deepseek.com/chat/completions',
				'openai_model' => 'gpt-5.4-mini',
				'openai_base_url' => 'https://api.openai.com/v1/responses',
				'timeout' => self::DEFAULT_PROVIDER_TIMEOUT,
			],
			'settings_revision' => self::REVISION,
		];
	}

	/**
	 * Add defaults introduced by newer plugin versions without replacing saved
	 * values or extension-owned settings.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function withDefaults(array $settings): array {
		return array_replace_recursive(self::defaults(), $settings);
	}

	/**
	 * Apply one-time changes to settings saved by an older version.
	 *
	 * Runs on the stored option before defaults are merged in, because the
	 * defaults already carry the current revision.
	 *
	 * Only values an administrator never chose are moved. A retired model ID no
	 * longer selects the model it names, and a timeout of exactly 15 is the old
	 * default. Anything else they set is left alone.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function upgrade(array $settings): array {
		$revision = (int) ($settings['settings_revision'] ?? 1);
		if ($revision >= self::REVISION) { return $settings; }
		if (is_array($settings['providers'] ?? null)) {
			$model = strtolower(trim((string) ($settings['providers']['deepseek_model'] ?? '')));
			if (DeepSeekProvider::isRetiredModel($model)) {
				$settings['providers']['deepseek_model'] = self::DEFAULT_DEEPSEEK_MODEL;
				// The reasoner model always thought, whatever the effort setting
				// said. Keep that, rather than quietly switching reasoning off.
				if ($model === 'deepseek-reasoner' && (string) ($settings['providers']['deepseek_reasoning_effort'] ?? 'off') === 'off') {
					$settings['providers']['deepseek_reasoning_effort'] = 'standard';
				}
			}
			if ((int) ($settings['providers']['timeout'] ?? 0) === self::LEGACY_DEFAULT_PROVIDER_TIMEOUT) {
				$settings['providers']['timeout'] = self::DEFAULT_PROVIDER_TIMEOUT;
			}
		}
		$settings['settings_revision'] = self::REVISION;
		return $settings;
	}
}
