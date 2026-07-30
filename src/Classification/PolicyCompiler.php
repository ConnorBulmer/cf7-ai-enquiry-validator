<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class PolicyCompiler {
	public const REASON_CODES = [
		'qualified', 'lower_value', 'general', 'supplier_pitch', 'recruitment',
		'existing_customer', 'spam', 'out_of_scope', 'duplicate', 'insufficient_information',
	];

	/** @param array<string, mixed> $definition @return array{definition:array<string,mixed>,prompt:string,hash:string} */
	public static function compile(array $definition): array {
		$definition = PolicyValidator::import($definition);
		$canonical = self::canonicalize($definition);
		$policyJson = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$contract = $definition['schema'] === PolicyValidator::SCHEMA_V2
			? "Use the policy's universal outcomes and evidence rules exactly. Each evidence assertion must quote an exact 2-160 character span from its named supplied context field and use the catalog dimension for its code. Each required_evidence group requires at least one grounded code; supporting evidence must meet its stated minimum.\n"
			: '';
		$prompt = "You classify business enquiries using only the supplied privacy-redacted context.\n"
			. "Return exactly one JSON object matching the supplied schema. Do not infer or reproduce identity, contact details, attribution, consent, files, click IDs, hashes, URLs, or secrets.\n"
			. 'Allowed reason_code values: ' . implode(', ', self::REASON_CODES) . ".\n"
			. "If evidence is incomplete, use reason_code insufficient_information and add an ambiguity flag. Never make dispatch decisions.\n"
			. $contract
			. 'Business policy: ' . $policyJson;
		return ['definition' => $definition, 'prompt' => $prompt, 'hash' => hash('sha256', $prompt)];
	}

	private static function canonicalize(mixed $value): mixed {
		if (! is_array($value)) { return $value; }
		if (! array_is_list($value)) { ksort($value, SORT_STRING); }
		foreach ($value as $key => $nested) { $value[$key] = self::canonicalize($nested); }
		return $value;
	}
}
