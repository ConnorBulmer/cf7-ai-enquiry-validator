<?php

declare(strict_types=1);

namespace CF7AIV\Intake;

final class ItemRepository {
	/** @param array<string, mixed> $item */
	public function persist(array $item): int {
		global $wpdb;
		if (! isset($wpdb->prefix)) { return 0; }
		$table = $wpdb->prefix . 'cf7aiv_items';
		$sql = "INSERT INTO {$table} (fingerprint,source_type,source_reference,form_id,calibration_run_id,state,review_state,attribution_encrypted,consent_encrypted,attribution_ready,email_hash,phone_hash,is_calibration,is_historical,created_at,updated_at) VALUES (%s,%s,%s,%d,%s,'captured','pending',%s,%s,%d,%s,%s,%d,%d,%s,%s) ON DUPLICATE KEY UPDATE source_type=CASE WHEN VALUES(source_type) = 'flamingo' AND COALESCE(VALUES(source_reference),'') <> '' THEN 'flamingo' WHEN source_type = 'flamingo' THEN source_type WHEN COALESCE(VALUES(source_reference),'') <> '' AND (source_type = 'cf7' OR COALESCE(source_reference,'') = '') THEN VALUES(source_type) ELSE source_type END, source_reference=CASE WHEN VALUES(source_type) = 'flamingo' AND COALESCE(VALUES(source_reference),'') <> '' THEN VALUES(source_reference) WHEN source_type = 'flamingo' THEN source_reference WHEN COALESCE(VALUES(source_reference),'') <> '' AND (source_type = 'cf7' OR COALESCE(source_reference,'') = '') THEN VALUES(source_reference) ELSE source_reference END, attribution_encrypted=VALUES(attribution_encrypted), consent_encrypted=VALUES(consent_encrypted), attribution_ready=VALUES(attribution_ready), email_hash=VALUES(email_hash), phone_hash=VALUES(phone_hash), updated_at=VALUES(updated_at)";
		$wpdb->query($wpdb->prepare($sql,
			$item['fingerprint'], $item['source_type'], $item['source_reference'], $item['form_id'], $item['calibration_run_id'] ?? null,
			$item['attribution_encrypted'], $item['consent_encrypted'], $item['attribution_ready'] ? 1 : 0,
			$item['email_hash'], $item['phone_hash'], $item['is_calibration'] ? 1 : 0, $item['is_historical'] ? 1 : 0,
			$item['created_at'], $item['updated_at']
		));
		$id = (int) ($wpdb->insert_id ?? 0);
		if ($id > 0) { return $id; }
		return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE fingerprint = %s", $item['fingerprint']));
	}
}
