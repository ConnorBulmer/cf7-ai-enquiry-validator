<?php

declare(strict_types=1);

namespace CF7AIV\Cli;

final class CliHooks {
	public static function register(): void {
		if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
			\WP_CLI::add_command('cf7aiv', new Commands());
		}
	}
}
