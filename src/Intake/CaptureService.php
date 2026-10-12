<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

use CF7AIV\Domain\ClientSignals;
use CF7AIV\Hooks;
use CF7AIV\Infrastructure\Crypto;
use CF7AIV\Infrastructure\Settings;

final class CaptureService {
	public function __construct(private readonly ItemRepository $items = new ItemRepository()) {}

	/** @param array<string, mixed> $result */
	public function capture(object $contactForm, array $result, string $source = 'cf7', ?string $sourceReference = null): int {
		if (! in_array((string) ($result['status'] ?? ''), ['mail_sent', 'mail_failed'], true)
			|| ! class_exists('WPCF7_Submission')) { return 0; }
		$submission = \WPCF7_Submission::get_instance();
		if (! is_object($submission) || ! method_exists($submission, 'get_posted_data')) { return 0; }
		$posted = $submission->get_posted_data();
		if (! is_array($posted)) { return 0; }
		$formId = method_exists($contactForm, 'id') ? (int) $contactForm->id() : (int) ($posted['_wpcf7'] ?? 0);
		$attribution = Attribution::fromPosted($posted);
		$signals = ['google' => array_intersect_key($attribution, array_flip(['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization']))];
		$wordpress = AttributionCookie::resolveWordPressConsent(
			function_exists('wp_get_consent_type') ? wp_get_consent_type() : null,
			function_exists('wp_has_consent') ? (bool) wp_has_consent('statistics') : null,
			function_exists('wp_has_consent') ? (bool) wp_has_consent('marketing') : null
		);
		if ($wordpress !== null) {
			$signals['wordpress'] = [
				'ad_storage' => $wordpress['marketing'],
				'ad_user_data' => $wordpress['marketing'],
				'analytics_storage' => $wordpress['statistics'],
			];
		}
		if (function_exists('cmplz_has_consent')) {
			$signals['complianz'] = ['ad_storage' => cmplz_has_consent('marketing'), 'ad_user_data' => cmplz_has_consent('marketing'), 'analytics_storage' => cmplz_has_consent('statistics')];
		}
		$consent = (new ConsentResolver())->resolve(new ClientSignals($signals));
		$diagnostics = AttributionDiagnostics::inspect($attribution, $consent->states, AttributionDiagnostics::gaIdentityNeeded(function_exists('get_option') ? (array) get_option(Settings::OPTION, []) : []));
		$submissionId = isset($attribution['submission_id']) ? (string) $attribution['submission_id'] : '';
		if ($submissionId === '' && method_exists($submission, 'get_posted_data_hash')) { $submissionId = (string) $submission->get_posted_data_hash(); }
		$fingerprint = SubmissionFingerprint::make($formId, $posted, $submissionId);
		[$emailHash, $phoneHash] = $this->identityHashes($posted, $consent->states);
		$now = current_time('mysql', true);
		$itemId = $this->items->persist([
			'fingerprint' => $fingerprint, 'source_type' => $source, 'source_reference' => $sourceReference,
			'form_id' => $formId, 'attribution_encrypted' => Crypto::encrypt((string) wp_json_encode($attribution)),
			'consent_encrypted' => Crypto::encrypt((string) wp_json_encode(['states' => $consent->states, 'source' => $consent->source, 'resolved_at' => $consent->resolvedAt, 'diagnostics' => $diagnostics['issues']])),
			'attribution_ready' => $diagnostics['ready'], 'email_hash' => $emailHash, 'phone_hash' => $phoneHash,
			'is_calibration' => false, 'is_historical' => false, 'created_at' => $now, 'updated_at' => $now,
		]);
		if ($itemId > 0 && function_exists('do_action')) { do_action(Hooks::AFTER_CAPTURE, $itemId, $source, $sourceReference); }
		return $itemId;
	}

	/** @param array<string, mixed> $posted @param array<string, string> $consentStates @return array{?string,?string} */
	private function identityHashes(array $posted, array $consentStates): array {
		$settings = function_exists('get_option') ? (array) get_option(Settings::OPTION, []) : [];
		if (empty($settings['privacy']['enhanced_matching']) || ($consentStates['ad_user_data'] ?? 'unknown') !== 'granted') { return [null, null]; }
		$email = null; $phone = null;
		foreach ($posted as $key => $value) {
			if (! is_scalar($value)) { continue; }
			if ($email === null && str_contains(strtolower((string) $key), 'email') && filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL)) {
				$email = hash('sha256', strtolower(trim((string) $value)));
			}
			if ($phone === null && self::isPhoneField((string) $key)) {
				$normalized = self::e164((string) $value);
				if ($normalized !== null) { $phone = hash('sha256', $normalized); }
			}
		}
		return [$email, $phone];
	}

	/**
	 * A field named for a phone number.
	 *
	 * "phone" and "mobile" count anywhere (phoneNumber, your-mobile,
	 * cellphone), but "tel" only as its own word or as telephone, telefon or
	 * telefono: matching it anywhere also matched hotel-booking-ref and
	 * intel-code.
	 */
	private static function isPhoneField(string $key): bool {
		return preg_match('/phone|mobile|(?:^|[^a-z])tel(?:e(?:phone|fon|fono))?(?:[^a-z]|$)/', strtolower($key)) === 1;
	}

	/**
	 * The number in E.164 form, or null when its country cannot be known.
	 *
	 * Google and Microsoft both match on the SHA-256 of +<country><number>. The
	 * digits of a national number such as 07700 900123 hash to a value neither
	 * will ever match, so sending it only discloses a hash for no gain. A number
	 * written internationally, with + or 00, can be normalised safely.
	 */
	private static function e164(string $value): ?string {
		// "+44 (0) 7700 900123" writes the national trunk zero beside the country
		// code; it is not dialled internationally and is not part of E.164.
		$value = trim((string) preg_replace('/\(\s*0\s*\)/', '', $value));
		if (str_starts_with($value, '00')) { $value = '+' . substr($value, 2); }
		if (! str_starts_with($value, '+')) { return null; }
		$digits = preg_replace('/\D/', '', $value) ?? '';
		return strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0' ? '+' . $digits : null;
	}
}
