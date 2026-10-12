<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

final class Queue {
	public const GROUP = 'cf7aiv';

	/**
	 * Is this exact job - hook, arguments and group - already waiting to run?
	 *
	 * Action Scheduler's own $unique flag cannot answer this. Its uniqueness
	 * predicate is:
	 *
	 *   WHERE status IN ('pending','in-progress') AND hook = %s AND group_id = %d
	 *
	 * which ignores arguments entirely, so passing $unique = true means only one
	 * action per hook may be pending for the whole site. Every job enqueued while
	 * another is waiting is dropped, silently, with a 0 return.
	 *
	 * Every hook here is per item - cf7aiv_classify_item, cf7aiv_evaluate_delivery,
	 * cf7aiv_deliver_item, cf7aiv_backfill_batch - so that behaviour discarded
	 * enquiries under any concurrency at all. Observed on a live staging site: four
	 * enquiries submitted ten seconds apart, one classified, three left in
	 * 'captured' for ever with classification_attempts = 0 and no error recorded.
	 *
	 * as_has_scheduled_action() does compare arguments, so it gives the
	 * per-item deduplication that was intended.
	 *
	 * @param array<int|string, mixed> $arguments
	 */
	private static function alreadyQueued(string $hook, array $arguments, string $group): bool {
		return function_exists('as_has_scheduled_action')
			&& as_has_scheduled_action($hook, $arguments, $group) === true;
	}

	/** @param array<int|string, mixed> $arguments */
	public static function enqueue(string $hook, array $arguments = [], string $group = self::GROUP): int|bool {
		if (function_exists('as_enqueue_async_action')) {
			if (self::alreadyQueued($hook, $arguments, $group)) { return true; }
			return self::normalize(as_enqueue_async_action($hook, $arguments, $group, false));
		}
		if (function_exists('wp_schedule_single_event')) {
			return self::normalize(wp_schedule_single_event(time() + 1, $hook, $arguments, true));
		}
		return false;
	}

	/** @param array<int|string, mixed> $arguments */
	public static function schedule(string $hook, int $timestamp, array $arguments = [], string $group = self::GROUP): int|bool {
		if (function_exists('as_schedule_single_action')) {
			if (self::alreadyQueued($hook, $arguments, $group)) { return true; }
			return self::normalize(as_schedule_single_action($timestamp, $hook, $arguments, $group, false));
		}
		if (function_exists('wp_schedule_single_event')) {
			return self::normalize(wp_schedule_single_event($timestamp, $hook, $arguments, true));
		}
		return false;
	}

	/** @param array<int|string, mixed> $arguments */
	public static function unschedule(string $hook, array $arguments = [], string $group = self::GROUP): bool {
		if (function_exists('as_unschedule_action')) {
			$result = as_unschedule_action($hook, $arguments, $group);
			return is_int($result) && $result > 0;
		}
		if (function_exists('wp_clear_scheduled_hook')) {
			$result = wp_clear_scheduled_hook($hook, $arguments);
			return $result === true || (is_int($result) && $result > 0);
		}
		return false;
	}

	/** Did an enqueue or schedule call leave the job waiting to run? */
	public static function accepted(mixed $result): bool {
		return $result === true || (is_int($result) && $result > 0);
	}

	private static function normalize(mixed $result): int|bool {
		if ((function_exists('is_wp_error') && is_wp_error($result)) || (class_exists('WP_Error', false) && $result instanceof \WP_Error)) {
			// WP-Cron refuses an identical event already due within ten minutes.
			// The job is waiting to run, which is what the caller asked for, so
			// this matches Action Scheduler's already-queued answer.
			return method_exists($result, 'get_error_code') && $result->get_error_code() === 'duplicate_event';
		}
		// Action Scheduler reports a failed enqueue as 0, not false. Left as 0, a
		// caller testing for false treats a job that was never stored as queued.
		if ($result === 0) { return false; }
		return is_int($result) || is_bool($result) ? $result : false;
	}
}
