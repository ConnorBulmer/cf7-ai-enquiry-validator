<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class SubmissionFingerprint {
	/** @param array<string, mixed> $posted */
	public static function make(int $formId, array $posted, string $submissionId = ''): string {
		$basis = $submissionId !== '' ? ['submission_id' => $submissionId] : ['posted' => self::canonical($posted)];
		$basis['form_id'] = $formId;
		return hash('sha256', (string) json_encode($basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private static function canonical(mixed $value): mixed {
		if (! is_array($value)) {
			return is_scalar($value) ? trim((string) $value) : '';
		}
		if (! array_is_list($value)) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as $key => $item) {
			$value[$key] = self::canonical($item);
		}
		return $value;
	}
}
