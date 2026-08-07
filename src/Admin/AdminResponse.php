<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

/**
 * The single place an admin action ends a request early.
 *
 * Both deliberate stops - an unauthorized request and a refused one - used to
 * call wp_die themselves. That was fine while nothing watched for exceptions,
 * but AdminHooks::guarded now does, and wp_die is not guaranteed to end the
 * request: WordPress routes it through the wp_die_handler filter, and its own
 * test harness replaces it with one that throws. Where that happens the guard
 * saw a deliberate 403 as an unexpected fault and reported it as a 500.
 *
 * Recording the fact of the response, rather than inferring it from what was
 * thrown, keeps the guard correct whichever way wp_die behaves.
 */
final class AdminResponse {
	private static bool $sent = false;

	/**
	 * Send a status and end the request.
	 *
	 * Returns only when wp_die is unavailable - outside WordPress there is
	 * nothing to render - so callers still throw afterwards to stop the handler
	 * and to keep the refusal assertable in tests.
	 */
	public static function halt(string $message, string $title, int $status): void {
		self::$sent = true;
		if (function_exists('wp_die')) {
			wp_die($message, $title, ['response' => $status]);
		}
	}

	public static function sent(): bool {
		return self::$sent;
	}

	public static function reset(): void {
		self::$sent = false;
	}
}
