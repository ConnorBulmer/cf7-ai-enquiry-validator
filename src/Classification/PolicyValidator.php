<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use InvalidArgumentException;
use JsonException;

final class PolicyValidator {
	public const SCHEMA_V1 = 'cf7aiv-policy/v1';
	public const SCHEMA_V2 = 'cf7aiv-policy/v2';
	/** @deprecated Use SCHEMA_V1 or SCHEMA_V2 explicitly. */
	public const SCHEMA = self::SCHEMA_V1;
	public const OUTCOMES = [
		'primary_opportunity', 'secondary_opportunity', 'legitimate_non_conversion',
		'excluded', 'spam', 'insufficient_information',
	];
	public const V2_TIER_KEYS = [
		'id', 'label', 'outcome_id', 'description', 'ads_eligible', 'required_evidence',
		'supporting_evidence', 'minimum_supporting_evidence', 'exclusion_codes', 'ambiguity_codes',
	];
	public const IMPORTABLE_V1 = [
		'schema', 'business', 'audiences', 'tier_definitions', 'exclusions',
		'ambiguity_rules', 'terminology', 'examples', 'field_mapping', 'ai_whitelist',
	];
	/** @deprecated Use IMPORTABLE_V1. */
	public const IMPORTABLE = self::IMPORTABLE_V1;
	public const IMPORTABLE_V2 = [
		'schema', 'pack', 'business', 'outcome_labels', 'evidence_catalog', 'tier_definitions',
		'exclusion_catalog', 'ambiguity_catalog', 'terminology', 'examples', 'field_mapping',
		'ai_whitelist', 'migration',
	];

	/** @return array<string, mixed> */
	public static function import(string|array $input): array {
		$data = self::decode($input);
		return match ($data['schema'] ?? '') {
			self::SCHEMA_V1 => self::importV1($data),
			self::SCHEMA_V2 => self::importV2($data),
			default => throw new InvalidArgumentException('Policy must declare cf7aiv-policy/v1 or cf7aiv-policy/v2.'),
		};
	}

	/** @return array<string, mixed> */
	public static function importV1(string|array $input): array {
		$input = self::decode($input);
		if (($input['schema'] ?? null) !== self::SCHEMA_V1) {
			throw new InvalidArgumentException('Policy must declare cf7aiv-policy/v1.');
		}
		$policy = array_intersect_key($input, array_flip(self::IMPORTABLE_V1));
		if (! is_string($policy['business'] ?? null) || trim($policy['business']) === '') {
			throw new InvalidArgumentException('Policy business description is required.');
		}
		self::assertSafeProse($policy['business'], false);
		if (! is_array($policy['tier_definitions'] ?? null) || $policy['tier_definitions'] === []) {
			throw new InvalidArgumentException('Policy tier definitions are required.');
		}
		$seen = [];
		foreach ($policy['tier_definitions'] as $tier) {
			if (! is_array($tier) || array_diff(array_keys($tier), ['id', 'description', 'ads_eligible']) !== []
				|| ! self::isCode($tier['id'] ?? null)
				|| ! is_string($tier['description'] ?? null) || ! is_bool($tier['ads_eligible'] ?? null)) {
				throw new InvalidArgumentException('Each tier requires a stable id, description, and ads_eligible boolean.');
			}
			if (isset($seen[$tier['id']])) {
				throw new InvalidArgumentException('Policy tier IDs must be unique.');
			}
			self::assertSafeString($tier['id']);
			self::assertSafeProse($tier['description'], false);
			$seen[$tier['id']] = true;
		}
		$policy['ai_whitelist'] = self::validateWhitelist($policy['ai_whitelist'] ?? null);
		foreach (['audiences', 'exclusions', 'ambiguity_rules', 'terminology', 'examples', 'field_mapping'] as $optional) {
			if (isset($policy[$optional]) && ! is_array($policy[$optional])) {
				throw new InvalidArgumentException("Policy {$optional} must be an array.");
			}
			if (isset($policy[$optional])) {
				self::assertSafeProse($policy[$optional], true);
			}
		}
		return $policy;
	}

	/** @return array<string, mixed> */
	public static function importV2(string|array $input): array {
		$input = self::decode($input);
		if (($input['schema'] ?? null) !== self::SCHEMA_V2) {
			throw new InvalidArgumentException('Policy must declare cf7aiv-policy/v2.');
		}
		$policy = array_intersect_key($input, array_flip(self::IMPORTABLE_V2));
		self::validatePack($policy['pack'] ?? null);
		self::validateBusiness($policy['business'] ?? null);
		self::validateOutcomeLabels($policy['outcome_labels'] ?? null);

		$evidenceCodes = self::validateEvidenceCatalog($policy['evidence_catalog'] ?? null);
		$exclusionCodes = self::validateCatalog($policy['exclusion_catalog'] ?? null, 'exclusion');
		$ambiguityCodes = self::validateCatalog($policy['ambiguity_catalog'] ?? null, 'ambiguity');
		$policy['tier_definitions'] = self::validateV2Tiers(
			$policy['tier_definitions'] ?? null,
			$evidenceCodes,
			$exclusionCodes,
			$ambiguityCodes,
		);
		$policy['ai_whitelist'] = self::validateWhitelist($policy['ai_whitelist'] ?? null);

		foreach (['terminology', 'examples', 'field_mapping'] as $requiredArray) {
			if (! is_array($policy[$requiredArray] ?? null)) {
				throw new InvalidArgumentException("Policy {$requiredArray} must be an array.");
			}
			self::assertSafeProse($policy[$requiredArray], true);
		}
		if (isset($policy['migration'])) {
			if (! is_array($policy['migration'])
				|| ! self::hasExactKeys($policy['migration'], ['source_schema'])
				|| ($policy['migration']['source_schema'] ?? null) !== self::SCHEMA_V1) {
				throw new InvalidArgumentException('Policy migration provenance is invalid.');
			}
		}
		return $policy;
	}

	/** @return array<string, mixed> */
	private static function decode(string|array $input): array {
		if (is_string($input)) {
			try {
				$input = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
			} catch (JsonException $error) {
				throw new InvalidArgumentException('Policy JSON is malformed.', 0, $error);
			}
		}
		if (! is_array($input)) {
			throw new InvalidArgumentException('Policy must be a JSON object or associative array.');
		}
		return $input;
	}

	private static function validatePack(mixed $pack): void {
		if (! is_array($pack) || ! self::hasExactKeys($pack, ['key', 'version', 'label'])
			|| ! self::isCode($pack['key'] ?? null)
			|| ! is_string($pack['version'] ?? null)
			|| preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $pack['version']) !== 1
			|| ! is_string($pack['label'] ?? null) || trim($pack['label']) === '') {
			throw new InvalidArgumentException('Policy pack requires a stable key, semantic version, and label.');
		}
		self::assertSafeString($pack['label']);
	}

	private static function validateBusiness(mixed $business): void {
		if (! is_array($business) || ! self::hasExactKeys($business, ['description', 'services', 'audiences'])
			|| ! is_string($business['description'] ?? null) || trim($business['description']) === ''
			|| ! is_array($business['services'] ?? null) || ! array_is_list($business['services'])
			|| ! is_array($business['audiences'] ?? null) || ! array_is_list($business['audiences'])) {
			throw new InvalidArgumentException('Policy business requires description, services, and audiences.');
		}
		self::assertStringList($business['services'], 'business services');
		self::assertStringList($business['audiences'], 'business audiences');
		self::assertSafeProse($business, false);
	}

	private static function validateOutcomeLabels(mixed $labels): void {
		if (! is_array($labels) || count($labels) !== count(self::OUTCOMES)
			|| array_diff(array_keys($labels), self::OUTCOMES) !== []
			|| array_diff(self::OUTCOMES, array_keys($labels)) !== []) {
			throw new InvalidArgumentException('Policy outcome labels must cover every universal outcome.');
		}
		foreach ($labels as $label) {
			if (! is_string($label) || trim($label) === '') {
				throw new InvalidArgumentException('Policy outcome labels must be non-empty strings.');
			}
			self::assertSafeString($label);
		}
	}

	/** @return array<string, true> */
	private static function validateEvidenceCatalog(mixed $catalog): array {
		if (! is_array($catalog) || ! array_is_list($catalog) || $catalog === []) {
			throw new InvalidArgumentException('Policy evidence catalog is required.');
		}
		$codes = [];
		foreach ($catalog as $evidence) {
			if (! is_array($evidence) || ! self::hasExactKeys($evidence, ['code', 'dimension', 'description'])
				|| ! self::isCode($evidence['code'] ?? null) || ! self::isCode($evidence['dimension'] ?? null)
				|| ! is_string($evidence['description'] ?? null) || trim($evidence['description']) === '') {
				throw new InvalidArgumentException('Evidence entries require code, dimension, and description.');
			}
			if (isset($codes[$evidence['code']])) {
				throw new InvalidArgumentException('Evidence codes must be unique.');
			}
			self::assertSafeString($evidence['description']);
			$codes[$evidence['code']] = true;
		}
		return $codes;
	}

	/** @return array<string, true> */
	private static function validateCatalog(mixed $catalog, string $name): array {
		if (! is_array($catalog) || ! array_is_list($catalog)) {
			throw new InvalidArgumentException("Policy {$name} catalog must be a list.");
		}
		$codes = [];
		foreach ($catalog as $code) {
			if (! self::isCode($code) || isset($codes[$code])) {
				throw new InvalidArgumentException("Policy {$name} codes must be stable and unique.");
			}
			$codes[$code] = true;
		}
		return $codes;
	}

	/**
	 * @param array<string, true> $evidenceCodes
	 * @param array<string, true> $exclusionCodes
	 * @param array<string, true> $ambiguityCodes
	 * @return list<array<string, mixed>>
	 */
	private static function validateV2Tiers(mixed $tiers, array $evidenceCodes, array $exclusionCodes, array $ambiguityCodes): array {
		if (! is_array($tiers) || ! array_is_list($tiers) || $tiers === []) {
			throw new InvalidArgumentException('Policy tier definitions are required.');
		}
		$validated = [];
		$seen = [];
		foreach ($tiers as $tier) {
			if (! is_array($tier) || ! self::hasExactKeys($tier, self::V2_TIER_KEYS)) {
				throw new InvalidArgumentException('Each v2 tier must use the exact tier contract.');
			}
			if (! self::isCode($tier['id'] ?? null) || isset($seen[$tier['id']])) {
				throw new InvalidArgumentException('Policy tier IDs must be stable and unique.');
			}
			if (! is_string($tier['label'] ?? null) || trim($tier['label']) === ''
				|| ! in_array($tier['outcome_id'] ?? null, self::OUTCOMES, true)
				|| ! is_string($tier['description'] ?? null) || trim($tier['description']) === ''
				|| ! is_bool($tier['ads_eligible'] ?? null)) {
				throw new InvalidArgumentException('Each v2 tier requires a label, universal outcome, description, and ads eligibility.');
			}
			if ($tier['ads_eligible'] && ! in_array($tier['outcome_id'], ['primary_opportunity', 'secondary_opportunity'], true)) {
				throw new InvalidArgumentException('Only opportunity outcomes may be Ads eligible.');
			}
			if (! is_array($tier['required_evidence'] ?? null) || ! array_is_list($tier['required_evidence'])) {
				throw new InvalidArgumentException('Tier required evidence must be a list of evidence groups.');
			}
			foreach ($tier['required_evidence'] as $group) {
				if (! is_array($group) || ! array_is_list($group) || $group === []) {
					throw new InvalidArgumentException('Tier required evidence groups cannot be empty.');
				}
				self::assertKnownCodes($group, $evidenceCodes, 'required evidence');
			}
			self::assertKnownCodes($tier['supporting_evidence'] ?? null, $evidenceCodes, 'supporting evidence');
			if (! is_int($tier['minimum_supporting_evidence'] ?? null)
				|| $tier['minimum_supporting_evidence'] < 0
				|| $tier['minimum_supporting_evidence'] > count($tier['supporting_evidence'])) {
				throw new InvalidArgumentException('Tier supporting-evidence minimum is invalid.');
			}
			self::assertKnownCodes($tier['exclusion_codes'] ?? null, $exclusionCodes, 'exclusion');
			self::assertKnownCodes($tier['ambiguity_codes'] ?? null, $ambiguityCodes, 'ambiguity');
			self::assertSafeString($tier['label']);
			self::assertSafeString($tier['description']);
			$seen[$tier['id']] = true;
			$normalized = [];
			foreach (self::V2_TIER_KEYS as $key) { $normalized[$key] = $tier[$key]; }
			$validated[] = $normalized;
		}
		return $validated;
	}

	/** @param array<string, true> $known */
	private static function assertKnownCodes(mixed $codes, array $known, string $name): void {
		if (! is_array($codes) || ! array_is_list($codes)) {
			throw new InvalidArgumentException("Tier {$name} codes must be a list.");
		}
		$seen = [];
		foreach ($codes as $code) {
			if (! is_string($code) || ! isset($known[$code]) || isset($seen[$code])) {
				throw new InvalidArgumentException("Tier {$name} contains an unknown or duplicate code.");
			}
			$seen[$code] = true;
		}
	}

	/** @return list<string> */
	private static function validateWhitelist(mixed $fields): array {
		if (! is_array($fields) || ! array_is_list($fields)) {
			throw new InvalidArgumentException('Policy AI whitelist is required.');
		}
		$whitelist = [];
		foreach ($fields as $field) {
			if (! is_string($field) || $field === '' || ContextBuilder::isForbiddenField($field)) {
				throw new InvalidArgumentException('AI whitelist contains a forbidden or invalid field.');
			}
			$whitelist[] = $field;
		}
		return array_values(array_unique($whitelist));
	}

	private static function assertStringList(array $values, string $name): void {
		foreach ($values as $value) {
			if (! is_string($value) || trim($value) === '') {
				throw new InvalidArgumentException("Policy {$name} must contain non-empty strings.");
			}
		}
	}

	private static function isCode(mixed $value): bool {
		return is_string($value) && preg_match('/^[a-z][a-z0-9_]{1,63}$/', $value) === 1;
	}

	/** @param list<string> $allowed */
	private static function hasExactKeys(array $value, array $allowed): bool {
		$keys = array_keys($value);
		return count($keys) === count($allowed)
			&& array_diff($keys, $allowed) === []
			&& array_diff($allowed, $keys) === [];
	}

	private static function assertSafeProse(mixed $value, bool $validateKeys): void {
		if (is_array($value)) {
			foreach ($value as $key => $child) {
				if ($validateKeys && is_string($key)) { self::assertSafeString($key); }
				self::assertSafeProse($child, $validateKeys);
			}
			return;
		}
		if (is_string($value)) { self::assertSafeString($value); return; }
		if (! is_scalar($value) && $value !== null) {
			throw new InvalidArgumentException('Policy prose must contain only scalar values and arrays.');
		}
	}

	private static function assertSafeString(string $value): void {
		$trimmed = trim($value);
		if ($trimmed === '') { return; }
		if (Redactor::redact($trimmed) !== $trimmed
			|| preg_match('/\b(?:identity|name|full[ _-]?name|email|e-mail|phone|telephone|mobile|ip[ _-]?address|street[ _-]?address|postcode|postal[ _-]?code|gclid|gbraid|wbraid|msclkid|fbclid|ttclid|li_fat_id|utm(?:_[a-z]+)?|attribution|consent|hash(?:ed)?|secret|api[ _-]?key|access[ _-]?token|token|password|passphrase|attachment|upload(?:ed)?|file(?:name)?)\b/i', $trimmed)) {
			throw new InvalidArgumentException('Policy prose contains privacy-sensitive or operationally forbidden content.');
		}
	}

	/** @param array<string, mixed> $policy */
	public static function export(array $policy): string {
		return json_encode(self::import($policy), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}
}
