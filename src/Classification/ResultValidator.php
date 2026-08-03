<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;
use InvalidArgumentException;
use JsonException;

final class ResultValidator {
	public static function fromJson(string $json, ClassificationRequest $request): ClassificationResult {
		try {
			$value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $error) {
			throw new InvalidArgumentException('Classification JSON is malformed.', 0, $error);
		}
		if (! is_array($value)) { throw new InvalidArgumentException('Classification result must be an object.'); }
		return self::fromArray($value, $request);
	}

	/** @param array<string,mixed> $value */
	public static function fromArray(array $value, ClassificationRequest $request): ClassificationResult {
		return $request->policySchema === PolicyValidator::SCHEMA_V2
			? self::fromV2($value, $request)
			: self::fromV1($value, $request);
	}

	/** @return array<string,mixed> */
	public static function jsonSchema(ClassificationRequest $request): array {
		if ($request->policySchema !== PolicyValidator::SCHEMA_V2) {
			return self::v1Schema($request->allowedTierIds);
		}
		$evidenceCodes = array_keys($request->allowedEvidence);
		$dimensions = array_values(array_unique(array_values($request->allowedEvidence)));
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => ['outcome_id', 'tier_id', 'reason_code', 'model_confidence', 'evidence', 'missing_evidence', 'exclusion_codes', 'ambiguity_flags', 'summary'],
			'properties' => [
				'outcome_id' => ['type' => 'string', 'enum' => array_values($request->allowedOutcomeIds)],
				'tier_id' => ['type' => 'string', 'enum' => array_values($request->allowedTierIds)],
				'reason_code' => ['type' => 'string', 'enum' => PolicyCompiler::REASON_CODES],
				'model_confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
				'evidence' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'additionalProperties' => false,
						'required' => ['dimension', 'code', 'field_key', 'excerpt'],
						'properties' => [
							'dimension' => ['type' => 'string', 'enum' => $dimensions],
							'code' => ['type' => 'string', 'enum' => $evidenceCodes],
							'field_key' => ['type' => 'string', 'enum' => array_keys($request->context)],
							'excerpt' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 160],
						],
					],
				],
				'missing_evidence' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $evidenceCodes]],
				'exclusion_codes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_values($request->allowedExclusionCodes)]],
				'ambiguity_flags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_values($request->allowedAmbiguityFlags)]],
				'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 280, 'description' => 'Describe intent only. Do not include any personal name or identifying detail.'],
			],
		];
	}

	/** @param array<string,mixed> $value */
	private static function fromV1(array $value, ClassificationRequest $request): ClassificationResult {
		$required = ['tier_id', 'reason_code', 'confidence', 'summary', 'ambiguity_flags'];
		self::assertExactKeys($value, $required);
		self::assertTier($value['tier_id'], $request->allowedTierIds);
		self::assertReason($value['reason_code']);
		$confidence = self::confidence($value['confidence']);
		self::assertSummary($value['summary']);
		$flags = self::codeList($value['ambiguity_flags'], 'ambiguity flags');
		return new ClassificationResult(
			$value['tier_id'],
			$value['reason_code'],
			$confidence,
			self::safeReasonSummary($value['reason_code']),
			$flags,
		);
	}

	/** @param array<string,mixed> $value */
	private static function fromV2(array $value, ClassificationRequest $request): ClassificationResult {
		$required = ['outcome_id', 'tier_id', 'reason_code', 'model_confidence', 'evidence', 'missing_evidence', 'exclusion_codes', 'ambiguity_flags', 'summary'];
		self::assertExactKeys($value, $required);
		if (! is_string($value['outcome_id']) || ! in_array($value['outcome_id'], $request->allowedOutcomeIds, true)) {
			throw new InvalidArgumentException('Classification outcome is not in the published policy.');
		}
		self::assertTier($value['tier_id'], $request->allowedTierIds);
		self::assertReason($value['reason_code']);
		$confidence = self::confidence($value['model_confidence']);
		self::assertSummary($value['summary']);
		if (! is_array($value['evidence']) || ! array_is_list($value['evidence'])) {
			throw new InvalidArgumentException('Classification evidence must be an array.');
		}
		$evidence = [];
		foreach ($value['evidence'] as $assertion) {
			if (! is_array($assertion)) { throw new InvalidArgumentException('Classification evidence assertions must be objects.'); }
			self::assertExactKeys($assertion, ['dimension', 'code', 'field_key', 'excerpt']);
			foreach (['dimension', 'code', 'field_key', 'excerpt'] as $key) {
				if (! is_string($assertion[$key])) { throw new InvalidArgumentException("Classification evidence {$key} must be a string."); }
			}
			$expectedDimension = $request->allowedEvidence[$assertion['code']] ?? null;
			if ($expectedDimension === null) {
				throw new InvalidArgumentException('Classification evidence is not in the published catalog.');
			}
			if (! array_key_exists($assertion['field_key'], $request->context)
				|| ! EvidenceGrounder::ground($assertion['excerpt'], $request->context[$assertion['field_key']])) {
				throw new InvalidArgumentException('Classification evidence is not grounded in its request field.');
			}
			$evidence[] = new EvidenceAssertion(
				$expectedDimension,
				$assertion['code'],
				$assertion['field_key'],
				$assertion['excerpt'],
				true,
			);
		}
		$missingEvidence = self::codeList($value['missing_evidence'], 'missing evidence');
		foreach ($missingEvidence as $code) {
			if (! array_key_exists($code, $request->allowedEvidence)) {
				throw new InvalidArgumentException('Classification missing evidence is not in the published catalog.');
			}
		}
		$ambiguityFlags = self::allowedCodeList($value['ambiguity_flags'], $request->allowedAmbiguityFlags, 'ambiguity flags');
		$exclusionCodes = self::allowedCodeList($value['exclusion_codes'], $request->allowedExclusionCodes, 'exclusion codes');
		return new ClassificationResult(
			$value['tier_id'],
			$value['reason_code'],
			$confidence,
			self::safeOutcomeSummary($value['outcome_id']),
			$ambiguityFlags,
			$value['outcome_id'],
			$evidence,
			$missingEvidence,
			$exclusionCodes,
		);
	}

	/** @param list<string> $required */
	private static function assertExactKeys(array $value, array $required): void {
		$keys = array_keys($value);
		if (count($keys) !== count($required) || array_diff($keys, $required) !== [] || array_diff($required, $keys) !== []) {
			throw new InvalidArgumentException('Classification result must use the exact schema properties.');
		}
	}

	/** @param list<string> $allowedTiers */
	private static function assertTier(mixed $tier, array $allowedTiers): void {
		if (! is_string($tier) || ! in_array($tier, $allowedTiers, true)) {
			throw new InvalidArgumentException('Classification tier is not in the published policy.');
		}
	}

	private static function assertReason(mixed $reason): void {
		if (! is_string($reason) || ! in_array($reason, PolicyCompiler::REASON_CODES, true)) {
			throw new InvalidArgumentException('Classification reason code is invalid.');
		}
	}

	private static function confidence(mixed $value): float {
		if (! is_int($value) && ! is_float($value)) {
			throw new InvalidArgumentException('Classification confidence must be numeric.');
		}
		$confidence = (float) $value;
		if ($confidence < 0.0 || $confidence > 1.0) { throw new InvalidArgumentException('Classification confidence must be between 0 and 1.'); }
		return $confidence;
	}

	private static function assertSummary(mixed $summary): void {
		if (! is_string($summary) || trim($summary) === '' || strlen($summary) > 280 || Redactor::redact($summary) !== trim($summary)) {
			throw new InvalidArgumentException('Classification summary must be short and non-identifying.');
		}
	}

	/** @return list<string> */
	private static function codeList(mixed $values, string $name): array {
		if (! is_array($values) || ! array_is_list($values)) {
			throw new InvalidArgumentException("Classification {$name} must be an array.");
		}
		$result = [];
		foreach ($values as $value) {
			if (! is_string($value) || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $value) !== 1) {
				throw new InvalidArgumentException("Classification {$name} must contain stable strings.");
			}
			$result[] = $value;
		}
		return array_values(array_unique($result));
	}

	/** @param list<string> $allowed @return list<string> */
	private static function allowedCodeList(mixed $values, array $allowed, string $name): array {
		$result = self::codeList($values, $name);
		foreach ($result as $value) {
			if (! in_array($value, $allowed, true)) {
				throw new InvalidArgumentException("Classification {$name} contains a value outside the published policy.");
			}
		}
		return $result;
	}

	/** @param list<string> $tierIds @return array<string,mixed> */
	private static function v1Schema(array $tierIds): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => ['tier_id', 'reason_code', 'confidence', 'summary', 'ambiguity_flags'],
			'properties' => [
				'tier_id' => ['type' => 'string', 'enum' => array_values($tierIds)],
				'reason_code' => ['type' => 'string', 'enum' => PolicyCompiler::REASON_CODES],
				'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
				'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 280, 'description' => 'Describe intent only. Do not include any personal name or identifying detail.'],
				'ambiguity_flags' => ['type' => 'array', 'items' => self::codeSchema()],
			],
		];
	}

	/** @return array<string,mixed> */
	private static function codeSchema(): array {
		return ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,63}$'];
	}

	private static function safeOutcomeSummary(string $outcomeId): string {
		return match ($outcomeId) {
			'primary_opportunity' => 'Potential primary business opportunity.',
			'secondary_opportunity' => 'Potential secondary business opportunity.',
			'legitimate_non_conversion' => 'Legitimate non-converting message.',
			'excluded' => 'Message matches a configured exclusion.',
			'spam' => 'Spam or automated content.',
			default => 'Insufficient information to classify reliably.',
		};
	}

	private static function safeReasonSummary(string $reasonCode): string {
		return match ($reasonCode) {
			'qualified' => 'Prospective care enquiry.',
			'lower_value' => 'Lower-value care information enquiry.',
			'general' => 'General legitimate enquiry.',
			'supplier_pitch' => 'Supplier or commercial approach.',
			'recruitment' => 'Recruitment or employment enquiry.',
			'existing_customer' => 'Existing customer or resident matter.',
			'spam' => 'Spam or automated content.',
			'out_of_scope' => 'Out-of-scope enquiry.',
			'duplicate' => 'Potential duplicate enquiry.',
			default => 'Insufficient information to classify reliably.',
		};
	}
}
