<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class AttributionDiagnostics {
	/** @param array<string, mixed> $attribution @param array<string, mixed> $consent @return array{ready:bool,issues:list<string>} */
	public static function inspect(array $attribution, array $consent): array {
		$issues = [];
		$hasClick = false;
		foreach (IdentifierValidator::CLICK_KEYS as $key) {
			$hasClick = $hasClick || IdentifierValidator::clickId($key, $attribution[$key] ?? null) !== null;
		}
		if (! $hasClick) { $issues[] = 'missing_valid_click_identifier'; }
		if (IdentifierValidator::gaClientId($attribution['ga_client_id'] ?? null) === null
			|| IdentifierValidator::gaSessionId($attribution['ga_session_id'] ?? null) === null) {
			$issues[] = 'missing_ga_identity';
		}
		$adStorage = strtolower((string) ($consent['ad_storage'] ?? 'unknown'));
		$adUserData = strtolower((string) ($consent['ad_user_data'] ?? 'unknown'));
		if ($adStorage === 'unknown' || $adUserData === 'unknown') {
			$issues[] = 'missing_consent';
		} elseif ($adStorage !== 'granted' || $adUserData !== 'granted') {
			$issues[] = 'consent_denied';
		}
		return ['ready' => $issues === [], 'issues' => $issues];
	}
}
