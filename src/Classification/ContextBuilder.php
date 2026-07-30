<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

use CF7AIV\Domain\ClassificationRequest;

final class ContextBuilder {
	private const FORBIDDEN_PARTS = [
		'name', 'email', 'e-mail', 'phone', 'telephone', 'mobile', 'tel', 'address', 'postcode', 'postal',
		'ip', 'file', 'attachment', 'upload', 'gclid', 'dclid', 'wbraid', 'gbraid', 'msclkid', 'fbclid',
		'ttclid', 'li_fat_id', 'utm_', 'campaign', 'source', 'medium', 'landing', 'referrer', 'consent',
		'cookie', 'session', 'client_id', 'user_id', 'hash', 'secret', 'token', 'password',
	];

	/** @param array<string, mixed> $fields @param list<string> $whitelist */
	public static function request(string $itemId, string $policyVersion, array $fields, array $whitelist, string $systemPrompt): ClassificationRequest {
		$context = [];
		foreach ($whitelist as $field) {
			if (! is_string($field) || self::isForbiddenField($field) || ! array_key_exists($field, $fields)) { continue; }
			$value = self::safeValue($fields[$field]);
			if ($value !== null && $value !== '' && $value !== []) { $context[$field] = $value; }
		}
		$text = [];
		foreach ($context as $key => $value) {
			$text[] = $key . ': ' . (is_array($value) ? implode('; ', $value) : (string) $value);
		}
		return new ClassificationRequest($itemId, $policyVersion, implode("\n", $text), $context, $systemPrompt);
	}

	public static function isForbiddenField(string $field): bool {
		$normalized = strtolower(trim($field));
		if (str_starts_with($normalized, 'cf7aiv_')) { return true; }
		foreach (self::FORBIDDEN_PARTS as $part) {
			if (str_contains($normalized, $part)) { return true; }
		}
		return false;
	}

	/** @param array<mixed> $fields @return list<string> */
	public static function safeWhitelist(array $fields): array {
		$safe = [];
		foreach ($fields as $field) {
			if (! is_string($field)) { continue; }
			$field = trim($field);
			if ($field === '' || strlen($field) > 191 || self::isForbiddenField($field)) { continue; }
			$safe[$field] = true;
		}
		$result = array_keys($safe);
		sort($result, SORT_STRING);
		return $result;
	}

	/** @return scalar|array<scalar>|null */
	private static function safeValue(mixed $value): string|int|float|bool|array|null {
		if (is_string($value)) { return Redactor::redact($value); }
		if (is_int($value) || is_float($value) || is_bool($value)) { return $value; }
		if (! is_array($value)) { return null; }
		$safe = [];
		foreach ($value as $entry) {
			if (is_string($entry)) { $safe[] = Redactor::redact($entry); }
			elseif (is_int($entry) || is_float($entry) || is_bool($entry)) { $safe[] = $entry; }
		}
		return $safe;
	}
}
