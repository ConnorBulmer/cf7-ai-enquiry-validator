<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class EvidenceGrounder {
	public static function ground(string $excerpt, mixed $fieldValue): bool {
		$needle = self::normalize($excerpt);
		$length = function_exists('mb_strlen') ? mb_strlen($needle, 'UTF-8') : strlen($needle);
		if ($length < 2 || $length > 160) { return false; }
		if (is_scalar($fieldValue)) { return str_contains(self::normalize((string) $fieldValue), $needle); }
		if (! is_array($fieldValue)) { return false; }
		foreach ($fieldValue as $value) {
			if (is_scalar($value) && str_contains(self::normalize((string) $value), $needle)) { return true; }
		}
		return false;
	}

	private static function normalize(string $value): string {
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
		$collapsed = preg_replace('/\s+/u', ' ', $value);
		return trim(is_string($collapsed) ? $collapsed : $value);
	}
}
