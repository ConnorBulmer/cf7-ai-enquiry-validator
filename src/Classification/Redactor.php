<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class Redactor {
	public static function redact(string $text): string {
		$patterns = [
			'/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu' => '[REDACTED_EMAIL]',
			'/\b(?:https?:\/\/|www\.)[^\s<>()]+/iu' => '[REDACTED_URL]',
			'/\b(?:gclid|dclid|wbraid|gbraid|msclkid|fbclid|ttclid|li_fat_id)\s*[:=]\s*[A-Z0-9._\-]+/iu' => '[REDACTED_CLICK_ID]',
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/u' => '[REDACTED_IP]',
			'/(?<![\pL\d])(?:\+?\d[\d\s().\-]{5,}\d)(?![\pL\d])/u' => '[REDACTED_PHONE]',
		];
		foreach ($patterns as $pattern => $replacement) {
			$text = preg_replace($pattern, $replacement, $text) ?? $text;
		}
		return trim($text);
	}
}
