<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

final class CalibrationPlanner {
	/** @param list<int> $formIds @return list<array{form_id:?int,date_from:string,date_to:string,limit:int}> */
	public static function plan(array $formIds, string $dateFrom, string $dateTo, int $maximum = 25): array {
		$from = self::date($dateFrom);
		$to = self::date($dateTo);
		if ($from > $to) { throw new \InvalidArgumentException('Calibration date_from must not be after date_to.'); }
		if ($maximum < 1) { return []; }

		$formIds = array_values(array_unique(array_filter(array_map('intval', $formIds), static fn (int $id): bool => $id > 0)));
		sort($formIds, SORT_NUMERIC);
		$forms = $formIds === [] ? [null] : $formIds;
		$buckets = self::buckets($from, $to);
		$stratumCount = count($forms) * count($buckets);
		$base = intdiv($maximum, $stratumCount);
		$remainder = $maximum % $stratumCount;
		$plan = [];

		foreach ($forms as $formId) {
			foreach ($buckets as [$bucketFrom, $bucketTo]) {
				$limit = $base + ($remainder > 0 ? 1 : 0);
				if ($remainder > 0) { $remainder--; }
				if ($limit === 0) { continue; }
				$plan[] = [
					'form_id' => $formId,
					'date_from' => $bucketFrom->format('Y-m-d'),
					'date_to' => $bucketTo->format('Y-m-d'),
					'limit' => $limit,
				];
			}
		}

		return $plan;
	}

	private static function date(string $value): \DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
		$errors = \DateTimeImmutable::getLastErrors();
		if (! $date || $date->format('Y-m-d') !== $value || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new \InvalidArgumentException('Calibration dates must use valid YYYY-MM-DD values.');
		}
		return $date;
	}

	/** @return list<array{0:\DateTimeImmutable,1:\DateTimeImmutable}> */
	private static function buckets(\DateTimeImmutable $from, \DateTimeImmutable $to): array {
		$days = (int) $from->diff($to)->days + 1;
		$count = min(3, $days);
		$base = intdiv($days, $count);
		$remainder = $days % $count;
		$buckets = [];
		$cursor = $from;
		for ($index = 0; $index < $count; $index++) {
			$length = $base + ($index < $remainder ? 1 : 0);
			$end = $cursor->modify('+' . ($length - 1) . ' days');
			$buckets[] = [$cursor, $end];
			$cursor = $end->modify('+1 day');
		}
		return $buckets;
	}
}
