<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class SourceNormalizer {
	/** @param array<string, mixed> $record @return array<string, mixed> */
	public static function fromArray(string $source, string $reference, array $record, bool $historical): array {
		$fields = $record['fields'] ?? $record['form_value'] ?? [];
		return [
			'source' => $source,
			'reference' => $reference,
			'form_id' => (int) ($record['form_id'] ?? $record['form_post_id'] ?? 0),
			'submitted_at' => (string) ($record['submitted_at'] ?? $record['form_date'] ?? ''),
			'fields' => is_array($fields) ? $fields : [],
			'is_historical' => $historical,
			'is_calibration' => $historical,
		];
	}
}
