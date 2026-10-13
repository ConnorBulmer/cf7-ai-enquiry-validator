<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class AttributionDiagnostics {
	/**
	 * @param array<string, mixed> $attribution @param array<string, mixed> $consent
	 * @param bool $gaIdentityNeeded False when no GA destination is in use. A
	 *   Microsoft-only site has no use for GA ids, and requiring them marked every
	 *   one of its enquiries unattributable.
	 * @return array{ready:bool,issues:list<string>}
	 */
	public static function inspect(array $attribution, array $consent, bool $gaIdentityNeeded = true): array {
		$issues = [];
		$hasClick = false;
		foreach (IdentifierValidator::CLICK_KEYS as $key) {
			$hasClick = $hasClick || IdentifierValidator::clickId($key, $attribution[$key] ?? null) !== null;
		}
		if (! $hasClick) { $issues[] = 'missing_valid_click_identifier'; }
		if ($gaIdentityNeeded && (IdentifierValidator::gaClientId($attribution['ga_client_id'] ?? null) === null
			|| IdentifierValidator::gaSessionId($attribution['ga_session_id'] ?? null) === null)) {
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

	/**
	 * Only a GA destination uses the GA client and session ids.
	 *
	 * With nothing enabled yet they are still reported, so setup shows what a GA
	 * destination would need.
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function gaIdentityNeeded(array $settings): bool {
		$enabled = static fn (string $key): bool => ! empty($settings['destinations'][$key]['enabled']);
		if ($enabled('ga4') || $enabled('server_gtm')) { return true; }
		return ! $enabled('microsoft_uet') && ! $enabled('webhook');
	}
}
