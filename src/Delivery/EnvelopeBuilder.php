<?php
declare(strict_types=1);
namespace CF7AIV\Delivery;

use CF7AIV\Domain\ConversionEvent;

final class EnvelopeBuilder {
	/** @param array<string,mixed> $item @param array<string,scalar> $attribution @param array<string,scalar> $consent @param array<string,mixed> $audit */
	public static function build(array $item, DeliveryMapping $mapping, array $attribution, array $consent, bool $includeHashes = false, array $audit = []): ConversionEvent {
		$itemId = (int) ($item['id'] ?? 0);
		$key = hash('sha256', $itemId . '|' . $mapping->key . '|' . $mapping->version . '|' . $mapping->eventName);
		$submissionAt = self::date((string) ($item['created_at'] ?? ''));
		$classificationAt = self::date((string) ($item['classified_at'] ?? $item['updated_at'] ?? ''));
		$context = [];
		if ((int) ($item['form_id'] ?? 0) > 0) { $context['form_id'] = (int) $item['form_id']; }
		if (isset($item['source_type']) && is_scalar($item['source_type'])) { $context['source_type'] = substr((string) $item['source_type'], 0, 32); }
		$userData = [];
		if ($includeHashes) {
			foreach (['email_hash' => 'email_sha256', 'phone_hash' => 'phone_sha256'] as $source => $target) {
				$value = strtolower((string) ($item[$source] ?? ''));
				if (preg_match('/^[a-f0-9]{64}$/', $value)) { $userData[$target] = $value; }
			}
		}
		return new ConversionEvent($key, $mapping->tierId, $mapping->eventName, $mapping->value, $mapping->currency, $submissionAt, $context, self::safeAttribution($attribution), self::safeConsent($consent), $submissionAt, $classificationAt, $key, $userData, $audit);
	}

	private static function date(string $value): string { $time = strtotime($value); return $time === false ? gmdate(DATE_ATOM) : gmdate(DATE_ATOM, $time); }
	/** @param array<string,scalar> $values @return array<string,scalar> */
	private static function safeAttribution(array $values): array {
		$result = [];
		foreach (['client_id' => ['client_id', 'ga_client_id'], 'session_id' => ['session_id', 'ga_session_id']] as $target => $aliases) {
			foreach ($aliases as $alias) {
				if (isset($values[$alias]) && is_scalar($values[$alias]) && trim((string) $values[$alias]) !== '') { $result[$target] = substr(trim((string) $values[$alias]), 0, 256); break; }
			}
		}
		foreach (['session_started_at', 'gclid', 'gbraid', 'wbraid', 'msclkid'] as $key) { if (isset($values[$key]) && is_scalar($values[$key]) && trim((string) $values[$key]) !== '') { $result[$key] = substr(trim((string) $values[$key]), 0, 256); } }
		return $result;
	}
	/** @param array<string,scalar> $values @return array<string,scalar> */
	private static function safeConsent(array $values): array {
		$result = [];
		foreach (['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization'] as $key) { $result[$key] = in_array(($values[$key] ?? 'unknown'), ['granted', 'denied'], true) ? $values[$key] : 'unknown'; }
		return $result;
	}
}
