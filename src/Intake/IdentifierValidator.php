<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class IdentifierValidator {
	/** @var list<string> */
	public const CLICK_KEYS = ['gclid', 'gbraid', 'wbraid', 'msclkid'];

	public static function clickId(string $key, mixed $value): ?string {
		$value = is_scalar($value) ? trim((string) $value) : '';
		if ($key === 'msclkid') {
			return preg_match('/\A[a-f0-9]{32}\z/i', $value) === 1 ? $value : null;
		}
		if (! in_array($key, ['gclid', 'gbraid', 'wbraid'], true)) {
			return null;
		}
		return preg_match('/\A[A-Za-z0-9._~-]{8,256}\z/', $value) === 1 ? $value : null;
	}

	public static function gaClientId(mixed $value): ?string {
		$value = is_scalar($value) ? trim((string) $value) : '';
		return preg_match('/\A\d{5,20}\.\d{5,20}\z/', $value) === 1 ? $value : null;
	}

	public static function gaSessionId(mixed $value): ?string {
		$value = is_scalar($value) ? trim((string) $value) : '';
		return preg_match('/\A\d{6,20}\z/', $value) === 1 ? $value : null;
	}
}
