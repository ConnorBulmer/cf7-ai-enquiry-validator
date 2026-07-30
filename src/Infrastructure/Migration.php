<?php

declare(strict_types=1);

namespace CF7AIV\Infrastructure;

final class Migration {
	public const VERSION = '10';

	public static function migrate(): void {
		global $wpdb;
		if (! isset($wpdb) || ! isset($wpdb->prefix)) {
			return;
		}
		if (! function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach (Schema::definitions((string) $wpdb->prefix) as $sql) {
			dbDelta($sql);
		}
		if (! self::ensureFingerprintIndex($wpdb)) {
			if (function_exists('error_log')) {
				error_log('[CF7AIV migration] Could not establish the cross-source fingerprint index; migration will retry.');
			}
			return;
		}
		if (! self::ensureApprovalSchema($wpdb)) {
			if (function_exists('error_log')) {
				error_log('[CF7AIV migration] Could not verify the atomic dispatch-approval schema; migration will retry.');
			}
			return;
		}
		if (! self::ensureCalibrationSchema($wpdb)) {
			if (function_exists('error_log')) {
				error_log('[CF7AIV migration] Could not verify the authoritative calibration-run schema; migration will retry.');
			}
			return;
		}
		update_option('cf7aiv_schema_version', self::VERSION, false);
	}

	private static function ensureCalibrationSchema(object $wpdb):bool {
		$items=(string)$wpdb->prefix.'cf7aiv_items';
		$runs=(string)$wpdb->prefix.'cf7aiv_calibration_runs';
		$itemColumns=self::columns($wpdb,$items);
		$runColumns=self::columns($wpdb,$runs);
		foreach(['run_id','source_key','state','administrator_id','criteria_hash','scheduled_quota','created_at','updated_at','activated_at','cancelled_at'] as $column){
			if(!isset($runColumns[$column])){return false;}
		}
		if(!isset($itemColumns['calibration_run_id'])){return false;}
		$indexes=self::indexes($wpdb,$runs);
		return self::isUniqueSingleColumn($indexes['PRIMARY']??[],'run_id');
	}

	private static function ensureApprovalSchema(object $wpdb): bool {
		$items = (string) $wpdb->prefix . 'cf7aiv_items';
		$audits = (string) $wpdb->prefix . 'cf7aiv_review_suggestions';
		$itemColumns = self::columns($wpdb, $items);
		$auditColumns = self::columns($wpdb, $audits);
		if (! isset($itemColumns['dispatch_approval_id'], $itemColumns['review_revision'], $auditColumns['dispatch_approval_id'], $auditColumns['reviewed_outcome_id'])) {
			return false;
		}
		$itemIndexes = self::indexes($wpdb, $items);
		$auditIndexes = self::indexes($wpdb, $audits);
		return self::isUniqueSingleColumn($itemIndexes['dispatch_approval_id'] ?? [], 'dispatch_approval_id')
			&& self::isUniqueSingleColumn($auditIndexes['dispatch_approval_id'] ?? [], 'dispatch_approval_id');
	}

	private static function ensureFingerprintIndex(object $wpdb): bool {
		$table = (string) $wpdb->prefix . 'cf7aiv_items';
		$indexes = self::indexes($wpdb, $table);
		// An interrupted or older upgrade can leave an index with the correct
		// name but the wrong cardinality. Treat it as missing: global de-dupe
		// depends on this being a unique single-column index, not merely an
		// index called "fingerprint".
		if (isset($indexes['fingerprint']) && ! self::isUniqueSingleColumn($indexes['fingerprint'], 'fingerprint')) {
			$wpdb->query("ALTER TABLE {$table} DROP INDEX fingerprint");
			$indexes = self::indexes($wpdb, $table);
		}
		if (! isset($indexes['fingerprint'])) {
			// Never collapse item rows automatically: choosing a keeper can discard
			// classification/review truth and orphan dependent audit or delivery
			// rows. An installation with legacy duplicates must be remediated
			// explicitly before this migration can complete.
			$duplicateGroups = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT fingerprint FROM {$table} GROUP BY fingerprint HAVING COUNT(*) > 1) duplicate_fingerprints");
			if ($duplicateGroups > 0) { return false; }
			$wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY fingerprint (fingerprint)");
			$indexes = self::indexes($wpdb, $table);
		}
		if (! self::isUniqueSingleColumn($indexes['fingerprint'] ?? [], 'fingerprint')) { return false; }
		if (isset($indexes['fingerprint_source'])) {
			$wpdb->query("ALTER TABLE {$table} DROP INDEX fingerprint_source");
		}
		return true;
	}

	/** @param list<array<string, mixed>> $index */
	private static function isUnique(array $index): bool {
		foreach ($index as $row) {
			if ((int) ($row['Non_unique'] ?? $row['non_unique'] ?? 1) !== 0) {
				return false;
			}
		}
		return $index !== [];
	}

	/** @param list<array<string,mixed>> $index */
	private static function isUniqueSingleColumn(array $index, string $column): bool {
		if (count($index) !== 1) { return false; }
		$row = $index[0];
		return (int) ($row['Non_unique'] ?? $row['non_unique'] ?? 1) === 0
			&& (string) ($row['Column_name'] ?? $row['column_name'] ?? '') === $column
			&& (int) ($row['Seq_in_index'] ?? $row['seq_in_index'] ?? 1) === 1;
	}

	/** @return array<string,true> */
	private static function columns(object $wpdb, string $table): array {
		$rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
		$result = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$field = (string) ($row['Field'] ?? $row['field'] ?? '');
			if ($field !== '') { $result[$field] = true; }
		}
		return $result;
	}

	/** @return array<string, list<array<string, mixed>>> */
	private static function indexes(object $wpdb, string $table): array {
		$rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
		$result = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$key = (string) ($row['Key_name'] ?? $row['key_name'] ?? '');
			if ($key !== '') { $result[$key][] = $row; }
		}
		return $result;
	}
}
