<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use Throwable;

/**
 * A bounded, sanitized operational log an administrator can actually read.
 *
 * The plugin already recorded why things failed - in `last_error`, in exception
 * messages, in delivery gate reasons - and then either displayed none of it or
 * wrote it to the PHP error log. On managed hosting the PHP error log is
 * frequently unreachable, which leaves an operator with a plugin that has
 * diagnosed its own problem and no way to hand over the answer.
 *
 * This is deliberately not a debug log. It records decisions and failures that
 * change what the plugin does, at a volume a person can read, and it is capped
 * so it cannot grow without bound or need a schema migration.
 *
 * Everything written here is assumed to be displayed. Values are scrubbed for
 * credentials and personal data on the way in rather than on the way out, so a
 * future reader of the option cannot be surprised by its contents.
 */
final class EventLog {
	public const OPTION = 'cf7aiv_event_log';

	/** Enough to cover a working session without turning the option into a table. */
	private const LIMIT = 200;

	private const LEVELS = ['info', 'warning', 'error'];

	/** @param array<string,mixed> $context */
	public static function record(string $channel, string $level, string $message, array $context = []): void {
		if (! function_exists('get_option') || ! function_exists('update_option')) { return; }
		$entry = [
			'at' => gmdate(DATE_ATOM),
			'channel' => self::code($channel),
			'level' => in_array($level, self::LEVELS, true) ? $level : 'info',
			'message' => self::clean($message, 500),
			'context' => self::context($context),
		];
		try {
			$log = (array) get_option(self::OPTION, []);
			$log[] = $entry;
			if (count($log) > self::LIMIT) { $log = array_slice($log, -self::LIMIT); }
			update_option(self::OPTION, $log, false);
		} catch (Throwable) {
			// A log that breaks the thing it is observing is worse than no log.
		}
	}

	/** Record a caught failure with its class, reusing the shared scrubbing. */
	public static function recordFailure(string $channel, Throwable $error, array $context = []): void {
		self::record($channel, 'error', DiagnosticsService::describeFailure($error), $context);
	}

	/** @return list<array<string,mixed>> Newest first, because that is what is being looked for. */
	public static function recent(int $limit = 100): array {
		if (! function_exists('get_option')) { return []; }
		$log = (array) get_option(self::OPTION, []);
		$rows = [];
		foreach ($log as $entry) {
			if (is_array($entry)) { $rows[] = $entry; }
		}
		return array_slice(array_reverse($rows), 0, max(1, $limit));
	}

	public static function clear(): void {
		if (function_exists('update_option')) { update_option(self::OPTION, [], false); }
	}

	/**
	 * Attach the log to the lifecycle actions the plugin already broadcasts.
	 *
	 * These were emitted for extension authors and for CF7AIV_DEBUG error-log
	 * lines, which meant the information existed but only reached someone who
	 * could read the server's log. Listening here puts the same facts on a screen.
	 */
	public static function register(): void {
		if (! function_exists('add_action')) { return; }
		add_action('cf7aiv_admin_action_failed', static function (mixed $action, mixed $error = null): void {
			$message = $error instanceof Throwable ? DiagnosticsService::describeFailure($error) : 'Administration action failed.';
			self::record('admin', 'error', $message, ['action' => is_scalar($action) ? (string) $action : '']);
		}, 10, 2);
		add_action('cf7aiv_admin_action_refused', static function (mixed $action, mixed $reason = null): void {
			self::record('admin', 'warning', is_scalar($reason) ? (string) $reason : 'Administration action refused.', ['action' => is_scalar($action) ? (string) $action : '']);
		}, 10, 2);
		add_action('cf7aiv_delivery_gate', static function (mixed $itemId, mixed $destination, mixed $reason): void {
			self::record('delivery', 'info', 'Delivery gate: ' . (is_scalar($reason) ? (string) $reason : 'unknown'), [
				'item' => is_scalar($itemId) ? (string) $itemId : '',
				'destination' => is_scalar($destination) ? (string) $destination : '',
			]);
		}, 10, 3);
	}

	/** @param array<string,mixed> $context @return array<string,string> */
	private static function context(array $context): array {
		$out = [];
		foreach ($context as $key => $value) {
			if (! is_scalar($value) && $value !== null) { continue; }
			$name = self::code((string) $key);
			if ($name === '') { continue; }
			$out[$name] = self::clean((string) $value, 120);
			if (count($out) >= 8) { break; }
		}
		return $out;
	}

	private static function code(string $value): string {
		return substr((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($value))), 0, 40);
	}

	/**
	 * Scrub on the way in, so the stored option is safe to read anywhere.
	 *
	 * AdminSafe::log already removes credential assignments and runs the enquiry
	 * redactor over the result, which is the same treatment delivery errors get.
	 */
	private static function clean(string $value, int $length): string {
		$value = AdminSafe::log($value);
		$value = (string) preg_replace('/\s+/', ' ', $value);
		return substr(trim($value), 0, $length);
	}
}
