<?php

declare(strict_types=1);

namespace CF7AIV\Domain;

use InvalidArgumentException;
use JsonException;

final class PolicySuggestionResult {
	public const MAX_SUGGESTIONS = 20;

	/** @param list<array<string,mixed>> $suggestions */
	public function __construct(
		public readonly array $suggestions = [],
		public readonly ?string $error = null,
	) {}

	public static function fromJson(string $json): self {
		try {
			$value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new InvalidArgumentException('Suggestion response is malformed.', 0, $error);
		}
		if (! is_array($value) || ! is_array($value['suggestions'] ?? null)) {
			throw new InvalidArgumentException('Suggestion response does not match the required schema.');
		}
		return new self(array_values(array_filter($value['suggestions'], 'is_array')));
	}

	/** @return array<string,mixed> */
	public static function jsonSchema(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => ['suggestions'],
			'properties' => ['suggestions' => [
				'type' => 'array',
				'maxItems' => self::MAX_SUGGESTIONS,
				'items' => [
					'type' => 'object',
					'additionalProperties' => false,
					'required' => ['type', 'value', 'source_refs'],
					'properties' => [
						'type' => ['type' => 'string', 'enum' => ['service', 'audience', 'value_signal', 'seriousness_signal', 'legitimate_non_conversion', 'exclusion', 'field_mapping']],
						'value' => ['anyOf' => [
							['type' => 'string', 'maxLength' => 500],
							['type' => 'object', 'additionalProperties' => false, 'required' => ['field', 'meaning'], 'properties' => ['field' => ['type' => 'string', 'maxLength' => 100], 'meaning' => ['type' => 'string', 'maxLength' => 300]]],
						]],
						'source_refs' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 120]],
					],
				],
			]],
		];
	}
}
