<?php

declare(strict_types=1);

namespace CF7AIV\Classification;

final class WpPolicyStore implements PolicyStoreInterface {
	public function save(array $record): int {
		global $wpdb;
		if (! isset($wpdb->prefix)) { return 0; }
		$table = $wpdb->prefix . 'cf7aiv_policies';
		$sql = "INSERT INTO {$table} (policy_key,version,status,policy_format,pack_key,pack_version,definition_json,compiled_prompt,compiled_prompt_hash,thresholds_json,examples_json,created_by,created_at,updated_at,published_at) VALUES (%s,%s,'draft',%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,NULL) ON DUPLICATE KEY UPDATE policy_format=IF(status='draft',VALUES(policy_format),policy_format),pack_key=IF(status='draft',VALUES(pack_key),pack_key),pack_version=IF(status='draft',VALUES(pack_version),pack_version),definition_json=IF(status='draft',VALUES(definition_json),definition_json),compiled_prompt=IF(status='draft',VALUES(compiled_prompt),compiled_prompt),compiled_prompt_hash=IF(status='draft',VALUES(compiled_prompt_hash),compiled_prompt_hash),thresholds_json=IF(status='draft',VALUES(thresholds_json),thresholds_json),examples_json=IF(status='draft',VALUES(examples_json),examples_json),updated_at=IF(status='draft',VALUES(updated_at),updated_at),published_at=IF(status='draft',VALUES(published_at),published_at)";
		$wpdb->query($wpdb->prepare($sql, $record['policy_key'], $record['version'], $record['policy_format'], $record['pack_key'], $record['pack_version'], $record['definition_json'], $record['compiled_prompt'], $record['compiled_prompt_hash'], $record['thresholds_json'], $record['examples_json'], $record['created_by'], $record['created_at'], $record['updated_at']));
		$id = (int) ($wpdb->insert_id ?? 0);
		return $id > 0 ? $id : (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE policy_key=%s AND version=%s", $record['policy_key'], $record['version']));
	}

	public function publish(string $policyKey, string $version): bool {
		global $wpdb;
		if (! isset($wpdb->prefix)) { return false; }
		$table = $wpdb->prefix . 'cf7aiv_policies';
		if ($wpdb->query('START TRANSACTION') === false) { return false; }
		try {
			$target = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$table} WHERE policy_key=%s AND version=%s FOR UPDATE", $policyKey, $version), ARRAY_A);
			if (! is_array($target) || ! in_array((string) ($target['status'] ?? ''), ['draft', 'published'], true)) {
				$wpdb->query('ROLLBACK');
				return false;
			}
			$wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE policy_key=%s FOR UPDATE", $policyKey), ARRAY_A);
			$targetId = (int) ($target['id'] ?? 0);
			if ($targetId <= 0) {
				$wpdb->query('ROLLBACK');
				return false;
			}
			if ((string) $target['status'] === 'draft') {
				$updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='published',published_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=%d AND status='draft'", $targetId));
				if ($updated !== 1) {
					$wpdb->query('ROLLBACK');
					return false;
				}
			}
			if ($wpdb->query($wpdb->prepare("UPDATE {$table} SET status='superseded',updated_at=UTC_TIMESTAMP() WHERE policy_key=%s AND id<>%d AND status='published'", $policyKey, $targetId)) === false
				|| $wpdb->query('COMMIT') === false) {
				$wpdb->query('ROLLBACK');
				return false;
			}
			return true;
		} catch (\Throwable) {
			$wpdb->query('ROLLBACK');
			return false;
		}
	}

	public function published(array $policyKeys): ?array {
		global $wpdb;
		if (! isset($wpdb->prefix)) { return null; }
		$table = $wpdb->prefix . 'cf7aiv_policies';
		foreach ($policyKeys as $key) {
			$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE policy_key=%s AND status='published' ORDER BY published_at DESC,id DESC LIMIT 1", $key), ARRAY_A);
			if (is_array($row)) { return $row; }
		}
		return null;
	}
}
