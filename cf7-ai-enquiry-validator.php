<?php
/**
 * Plugin Name: CF7 AI Enquiry Validator
 * Description: Asynchronously classifies stored Contact Form 7 enquiries and dispatches only approved, attributable conversions.
 * Version: 0.2.11
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: CF7 AI Enquiry Validator contributors
 * License: GPL-3.0-or-later
 * Text Domain: cf7-ai-enquiry-validator
 * Domain Path: /languages
 */

declare(strict_types=1);

namespace CF7AIV;

defined('ABSPATH') || exit;

const VERSION = '0.2.11';
const FILE = __FILE__;
const DIR = __DIR__ . '/';
define(__NAMESPACE__ . '\\URL', function_exists('plugin_dir_url') ? plugin_dir_url(__FILE__) : '');

spl_autoload_register(
	static function (string $class): void {
		$prefix = __NAMESPACE__ . '\\';
		if (! str_starts_with($class, $prefix)) {
			return;
		}
		$relative = substr($class, strlen($prefix));
		$file = DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
		if (is_readable($file)) {
			require_once $file;
		}
	}
);

// Load our GPL Action Scheduler copy only if another compatible copy has not already exposed its API.
if (function_exists('add_action') && ! function_exists('as_enqueue_async_action')) {
	$action_scheduler = DIR . 'vendor/action-scheduler/action-scheduler.php';
	if (is_readable($action_scheduler)) {
		require_once $action_scheduler;
	}
}

if (function_exists('add_action')) {
	Plugin::instance()->register();
}

if (function_exists('register_activation_hook')) {
	register_activation_hook(__FILE__, [Plugin::class, 'activate']);
}
