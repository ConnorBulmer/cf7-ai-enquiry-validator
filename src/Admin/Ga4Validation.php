<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

/**
 * The GA4 debug validation outcome, stored where a page load cannot overwrite it.
 *
 * This lived in the main settings array, which Plugin::ensureSettings() rewrites
 * in full on plugins_loaded. A background job writing its result into that array
 * loses the race against any request that read the array moments earlier: the
 * job wrote "passed", the next page load wrote back the copy it had already
 * read, and the screen reported "Not validated" while the activity log clearly
 * recorded a pass. Observed exactly that way on a live site.
 *
 * A result produced asynchronously does not belong in a blob that synchronous
 * requests rewrite wholesale. It gets its own option, which nothing else writes.
 *
 * The write is read back and confirmed, because a silently lost write is what
 * caused this in the first place and it should never again be invisible.
 */
final class Ga4Validation {
	public const OPTION = 'cf7aiv_ga4_validation';

	public const IDLE = 'idle';
	public const QUEUED = 'queued';
	public const PASSED = 'passed';
	public const FAILED = 'failed';

	public static function queued(): void {
		self::write(['state' => self::QUEUED, 'error' => '', 'at' => gmdate(DATE_ATOM)]);
	}

	public static function passed(): void {
		self::write(['state' => self::PASSED, 'error' => '', 'at' => gmdate(DATE_ATOM)]);
	}

	public static function failed(string $error): void {
		self::write(['state' => self::FAILED, 'error' => substr($error, 0, 400), 'at' => gmdate(DATE_ATOM)]);
	}

	/**
	 * Forget a previous result when the configuration it applied to changes.
	 *
	 * A pass against one Measurement ID and secret says nothing about the next
	 * pair, so changing either must send the destination back to unvalidated.
	 */
	public static function invalidate(): void {
		self::write(['state' => self::IDLE, 'error' => '', 'at' => gmdate(DATE_ATOM)]);
	}

	public static function isPassed(): bool {
		return self::state() === self::PASSED;
	}

	public static function isPending(): bool {
		return self::state() === self::QUEUED;
	}

	public static function state(): string {
		$stored = self::read();
		$state = (string) ($stored['state'] ?? self::IDLE);
		return in_array($state, [self::IDLE, self::QUEUED, self::PASSED, self::FAILED], true) ? $state : self::IDLE;
	}

	public static function error(): string {
		return (string) (self::read()['error'] ?? '');
	}

	public static function checkedAt(): string {
		return (string) (self::read()['at'] ?? '');
	}

	/** @return array<string,mixed> */
	private static function read(): array {
		if (! function_exists('get_option')) { return []; }
		$stored = get_option(self::OPTION, []);
		return is_array($stored) ? $stored : [];
	}

	/** @param array<string,string> $value */
	private static function write(array $value): void {
		if (! function_exists('update_option')) { return; }
		update_option(self::OPTION, $value, false);

		// Confirm it landed. The whole reason this class exists is a write that
		// reported success and was not there afterwards.
		if (self::read()['state'] !== $value['state']) {
			EventLog::record('ga4_validation', 'error', 'The validation result could not be stored.', ['state' => $value['state']]);
		}
	}
}
