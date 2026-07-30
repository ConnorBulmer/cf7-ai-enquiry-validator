<?php

declare(strict_types=1);

namespace CF7AIV\Delivery;

final class WpMappingLifecycle {
	public function publish(string $mappingKey, string $version): bool { return $this->transition($mappingKey, $version, 'publish'); }
	public function enable(string $mappingKey, string $version): bool { return $this->transition($mappingKey, $version, 'enable'); }
	public function disable(string $mappingKey, string $version): bool { return $this->transition($mappingKey, $version, 'disable'); }

	private function transition(string $mappingKey, string $version, string $action): bool {
		global $wpdb;
		if (! isset($wpdb->prefix) || $mappingKey === '' || $version === '') { return false; }
		$table = $wpdb->prefix . 'cf7aiv_mappings';
		if ($wpdb->query('START TRANSACTION') === false) { return false; }
		try {
			$target = $wpdb->get_row($wpdb->prepare("SELECT id,status,enabled FROM {$table} WHERE mapping_key=%s AND version=%s FOR UPDATE", $mappingKey, $version), ARRAY_A);
			if (! is_array($target)) {
				$wpdb->query('ROLLBACK');
				return false;
			}
			$wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE mapping_key=%s FOR UPDATE", $mappingKey), ARRAY_A);
			$id = (int) ($target['id'] ?? 0);
			$status = (string) ($target['status'] ?? '');
			$now = function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s');
			if ($id <= 0) {
				$wpdb->query('ROLLBACK');
				return false;
			}
			if ($action === 'publish') {
				if (! in_array($status, ['draft', 'published'], true)) {
					$wpdb->query('ROLLBACK');
					return false;
				}
				if ($status === 'draft') {
					$updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='published',enabled=0,published_at=%s,updated_at=%s WHERE id=%d AND status='draft'", $now, $now, $id));
					if ($updated !== 1) {
						$wpdb->query('ROLLBACK');
						return false;
					}
				}
				if ($wpdb->query($wpdb->prepare("UPDATE {$table} SET status='superseded',enabled=0,updated_at=%s WHERE mapping_key=%s AND id<>%d AND status='published'", $now, $mappingKey, $id)) === false) {
					$wpdb->query('ROLLBACK');
					return false;
				}
			} elseif ($action === 'enable') {
				if ($status !== 'published') {
					$wpdb->query('ROLLBACK');
					return false;
				}
				$updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET enabled=CASE WHEN version=%s AND status='published' THEN 1 ELSE 0 END,updated_at=%s WHERE mapping_key=%s", $version, $now, $mappingKey));
				if ($updated === false) {
					$wpdb->query('ROLLBACK');
					return false;
				}
			} else {
				$updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET enabled=0,updated_at=%s WHERE id=%d", $now, $id));
				if ($updated === false) {
					$wpdb->query('ROLLBACK');
					return false;
				}
			}
			if ($wpdb->query('COMMIT') === false) {
				$wpdb->query('ROLLBACK');
				return false;
			}
			return true;
		} catch (\Throwable) {
			$wpdb->query('ROLLBACK');
			return false;
		}
	}
}
