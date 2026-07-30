<?php

declare(strict_types=1);

namespace CF7AIV;

use CF7AIV\Admin\AdminHooks;
use CF7AIV\Classification\ClassificationHooks;
use CF7AIV\Cli\CliHooks;
use CF7AIV\Delivery\DeliveryHooks;
use CF7AIV\Infrastructure\Migration;
use CF7AIV\Infrastructure\Privacy;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Intake\IntakeHooks;

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action('init', [$this, 'loadTextdomain']);
		add_action('plugins_loaded', [$this, 'upgrade'], 20);
		Privacy::register();
		IntakeHooks::register();
		ClassificationHooks::register();
		DeliveryHooks::register();
		AdminHooks::register();
		CliHooks::register();
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain('cf7-ai-enquiry-validator', false, dirname(plugin_basename(FILE)) . '/languages');
	}

	public function upgrade(): void {
		if (get_option('cf7aiv_schema_version') !== Migration::VERSION) {
			Migration::migrate();
		}
		self::ensureSettings();
	}

	public static function activate(): void {
		Migration::migrate();
		self::ensureSettings();
	}

	private static function ensureSettings(): void {
		$stored = get_option(Settings::OPTION, null);
		if ($stored === null) {
			add_option(Settings::OPTION, Settings::defaults(), '', false);
			return;
		}

		$hydrated = Settings::withDefaults((array) $stored);
		if ($hydrated !== $stored) {
			update_option(Settings::OPTION, $hydrated, false);
		}
	}
}
