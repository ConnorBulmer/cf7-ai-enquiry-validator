<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

final class Settings {
	public const OPTION = 'cf7aiv_settings';
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
				'ga4' => ['enabled' => false, 'live_enabled' => false, 'debug_validated' => false, 'dry_run' => true, 'last_validation_error' => ''],
				'server_gtm' => ['enabled' => false, 'dry_run' => true],
				'microsoft_uet' => ['enabled' => false, 'dry_run' => true],
				'webhook' => ['enabled' => false, 'dry_run' => true, 'allow_insecure' => false],
			],
			'providers' => [
				'selected' => 'deepseek',
				'deepseek_reasoning_effort' => 'off',
				'openai_reasoning_effort' => 'unsupported',
				'deepseek_model' => 'deepseek-v4-flash',
				'deepseek_base_url' => 'https://api.deepseek.com/chat/completions',
				'openai_model' => 'gpt-5.4-mini',
				'openai_base_url' => 'https://api.openai.com/v1/responses',
				'timeout' => 15,
			],
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
}
