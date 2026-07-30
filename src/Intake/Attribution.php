<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class Attribution {
	/**
	 * Fresh, valid URL values beat persisted values. zc_gad is treated only as
	 * a fresh alias for gclid and never overwrites a fresh canonical gclid.
	 *
	 * @param array<string, mixed> $fresh
	 * @param array<string, mixed> $persisted
	 * @return array<string, string>
	 */
	public static function merge(array $fresh, array $persisted): array {
		$result = [];
		foreach (IdentifierValidator::CLICK_KEYS as $key) {
			$freshValue = IdentifierValidator::clickId($key, $fresh[$key] ?? null);
			if ($key === 'gclid' && $freshValue === null) {
				$freshValue = IdentifierValidator::clickId('gclid', $fresh['zc_gad'] ?? null);
			}
			$persistedValue = IdentifierValidator::clickId($key, $persisted[$key] ?? null);
			$value = $freshValue ?? $persistedValue;
			if ($value !== null) {
				$result[$key] = $value;
			}
		}
		return $result;
	}

	/** @param array<string, mixed> $posted @return array<string, string> */
	public static function fromPosted(array $posted): array {
		$result = [];
		foreach (FieldNames::map() as $field => $key) {
			if (! isset($posted[$field]) || ! is_scalar($posted[$field])) {
				continue;
			}
			$value = trim((string) $posted[$field]);
			if ($value !== '') {
				$result[$key] = self::limit($value, str_contains($key, 'page') || $key === 'referrer' ? 2048 : 512);
			}
		}
		foreach (IdentifierValidator::CLICK_KEYS as $key) {
			if (isset($result[$key])) {
				$valid = IdentifierValidator::clickId($key, $result[$key]);
				if ($valid === null) {
					unset($result[$key]);
				} else {
					$result[$key] = $valid;
				}
			}
		}
		if (isset($result['ga_client_id']) && IdentifierValidator::gaClientId($result['ga_client_id']) === null) {
			unset($result['ga_client_id']);
		}
		if (isset($result['ga_session_id']) && IdentifierValidator::gaSessionId($result['ga_session_id']) === null) {
			unset($result['ga_session_id']);
		}
		return $result;
	}

	private static function limit(string $value, int $length): string {
		return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
	}
}
