<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

final class Queue {
	public const GROUP = 'cf7aiv';

	/** @param array<int|string, mixed> $arguments */
	public static function enqueue(string $hook, array $arguments = [], string $group = self::GROUP): int|bool {
		if (function_exists('as_enqueue_async_action')) {
			return self::normalize(as_enqueue_async_action($hook, $arguments, $group, true));
		}
		if (function_exists('wp_schedule_single_event')) {
			return self::normalize(wp_schedule_single_event(time() + 1, $hook, $arguments, true));
		}
		return false;
	}

	/** @param array<int|string, mixed> $arguments */
	public static function schedule(string $hook, int $timestamp, array $arguments = [], string $group = self::GROUP): int|bool {
		if (function_exists('as_schedule_single_action')) {
			return self::normalize(as_schedule_single_action($timestamp, $hook, $arguments, $group, true));
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

	private static function normalize(mixed $result): int|bool {
		if ((function_exists('is_wp_error') && is_wp_error($result)) || (class_exists('WP_Error', false) && $result instanceof \WP_Error)) {
			return false;
		}
		return is_int($result) || is_bool($result) ? $result : false;
	}
}
