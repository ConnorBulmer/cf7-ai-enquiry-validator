<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\ContextBuilder;
use CF7AIV\Classification\PolicyPackRegistry;
use CF7AIV\Classification\PolicyValidator;
use InvalidArgumentException;

final class GuidedPolicyDraft {
	/**
	 * @param array<string,mixed> $input
	 * @param list<array<string,mixed>> $acceptedSuggestions
	 * @return array<string,mixed>
	 */
	public static function build(array $input, array $acceptedSuggestions = []): array {
		$packKey = substr(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($input['pack_key'] ?? 'custom_business'))) ?? '', 0, 100);
		$definition = (new PolicyPackRegistry())->get($packKey);
		if (! is_array($definition)) {
			throw new InvalidArgumentException('A valid starter pack is required.');
		}

		$description = self::text((string) ($input['business_description'] ?? ''), 500);
		if ($description !== '') {
			$definition['business']['description'] = $description;
		}
		$definition['business']['services'] = self::lines((string) ($input['services'] ?? ''));
		$definition['business']['audiences'] = self::lines((string) ($input['audiences'] ?? ''));

		$primaryDefinition = self::text((string) ($input['primary_definition'] ?? ''), 500);
		if ($primaryDefinition !== '') {
			foreach ($definition['tier_definitions'] as &$tier) {
				if (($tier['outcome_id'] ?? '') === 'primary_opportunity') {
					$tier['description'] = $primaryDefinition;
				}
			}
			unset($tier);
		}

		$selectedExclusions = array_values(array_intersect(
			$definition['exclusion_catalog'],
			self::codes((array) ($input['exclusion_codes'] ?? [])),
		));
		foreach ($definition['tier_definitions'] as &$tier) {
			$tier['exclusion_codes'] = array_values(array_intersect((array) $tier['exclusion_codes'], $selectedExclusions));
		}
		unset($tier);

		$selectedFields = array_values(array_intersect(
			$definition['ai_whitelist'],
			self::fields((array) ($input['ai_whitelist'] ?? [])),
		));
		if ($selectedFields !== []) {
			$definition['ai_whitelist'] = $selectedFields;
		}

		foreach ($acceptedSuggestions as $suggestion) {
			if (! is_array($suggestion)) {
				continue;
			}
			$type = (string) ($suggestion['suggestion_type'] ?? $suggestion['type'] ?? '');
			$value = $suggestion['value'] ?? $suggestion['value_json'] ?? null;
			if (is_string($value)) {
				$decoded = json_decode($value, true);
				if ($decoded !== null || trim($value) === 'null') {
					$value = $decoded;
				}
			}
			self::applySuggestion($definition, $type, $value);
		}

		return PolicyValidator::importV2($definition);
	}

	/** @param array<string,mixed> $definition */
	private static function applySuggestion(array &$definition, string $type, mixed $value): void {
		if (in_array($type, ['service', 'audience'], true) && is_string($value)) {
			$text = self::text($value, 300);
			if ($text !== '') {
				$key = $type === 'service' ? 'services' : 'audiences';
				$definition['business'][$key][] = $text;
				$definition['business'][$key] = array_values(array_unique($definition['business'][$key]));
			}
			return;
		}
		if ($type === 'field_mapping' && is_array($value)) {
			$field = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($value['field'] ?? '')) ?? '', 0, 100);
			$meaning = self::text((string) ($value['meaning'] ?? ''), 300);
			if ($field !== '' && $meaning !== '' && ! ContextBuilder::isForbiddenField($field)) {
				$definition['field_mapping'][$field] = $meaning;
				$definition['ai_whitelist'][] = $field;
				$definition['ai_whitelist'] = array_values(array_unique($definition['ai_whitelist']));
			}
			return;
		}
		if (! is_string($value)) {
			return;
		}
		$text = self::text($value, 300);
		if ($text === '') {
			return;
		}
		$outcomes = match ($type) {
			'value_signal', 'seriousness_signal' => ['primary_opportunity'],
			'legitimate_non_conversion' => ['legitimate_non_conversion'],
			'exclusion' => ['excluded'],
			default => [],
		};
		foreach ($definition['tier_definitions'] as &$tier) {
			if (in_array((string) ($tier['outcome_id'] ?? ''), $outcomes, true)) {
				$tier['description'] = self::text((string) $tier['description'] . '; ' . $text, 700);
			}
		}
		unset($tier);
	}

	/** @return list<string> */
	private static function lines(string $value): array {
		$out = [];
		foreach (preg_split('/\R+/', $value) ?: [] as $line) {
			$line = self::text($line, 300);
			if ($line !== '') {
				$out[] = $line;
			}
		}
		return array_slice(array_values(array_unique($out)), 0, 50);
	}

	/** @param list<mixed> $values @return list<string> */
	private static function codes(array $values): array {
		$out = [];
		foreach ($values as $value) {
			$code = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $value)) ?? '', 0, 64);
			if ($code !== '') {
				$out[] = $code;
			}
		}
		return array_values(array_unique($out));
	}

	/** @param list<mixed> $values @return list<string> */
	private static function fields(array $values): array {
		$out = [];
		foreach ($values as $value) {
			$field = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value) ?? '', 0, 100);
			if ($field !== '' && ! ContextBuilder::isForbiddenField($field)) {
				$out[] = $field;
			}
		}
		return array_values(array_unique($out));
	}

	private static function text(string $value, int $maximum): string {
		$value = trim(strip_tags($value));
		return function_exists('mb_substr') ? mb_substr($value, 0, $maximum) : substr($value, 0, $maximum);
	}
}
