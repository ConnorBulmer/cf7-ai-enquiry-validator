<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['dedup_dbdelta'] = [];
$GLOBALS['dedup_options'] = [];
function dbDelta(string $sql): void { $GLOBALS['dedup_dbdelta'][] = $sql; }
function update_option(string $name, mixed $value, bool $autoload = false): void { $GLOBALS['dedup_options'][$name] = $value; }

final class DedupWpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	/** @var list<string> */ public array $queries = [];
	public bool $newIndex = false;
	public bool $oldIndex = true;
	public bool $nonUniqueFingerprint = false;
	public bool $compositeFingerprint = false;
	public int $duplicateGroups = 0;

	public function prepare(string $sql, mixed ...$args): string {
		foreach ($args as $arg) {
			$replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
			$sql = preg_replace('/%[sd]/', $replacement, $sql, 1) ?? $sql;
		}
		return $sql;
	}
	public function query(string $sql): int {
		$this->queries[] = $sql;
		if (str_contains($sql, 'ADD UNIQUE KEY fingerprint ')) { $this->newIndex = true; }
		if (str_contains($sql, 'DROP INDEX fingerprint')) { $this->nonUniqueFingerprint = false; $this->compositeFingerprint = false; }
		if (str_contains($sql, 'DROP INDEX fingerprint_source')) { $this->oldIndex = false; }
		return 1;
	}
	public function get_var(string $sql): mixed { $this->queries[] = $sql; return str_contains($sql, 'HAVING COUNT(*) > 1') ? $this->duplicateGroups : 73; }
	public function get_results(string $sql, mixed $format = null): array {
		$this->queries[] = $sql;
		if (str_contains($sql, 'SHOW COLUMNS FROM wp_cf7aiv_calibration_runs')) { return array_map(static fn(string $field):array=>['Field'=>$field], ['run_id','source_key','state','administrator_id','criteria_hash','scheduled_quota','created_at','updated_at','activated_at','cancelled_at']); }
		if (str_contains($sql, 'SHOW INDEX FROM wp_cf7aiv_calibration_runs')) { return [['Key_name'=>'PRIMARY','Non_unique'=>0,'Column_name'=>'run_id','Seq_in_index'=>1]]; }
		if (str_contains($sql, 'SHOW COLUMNS FROM wp_cf7aiv_items')) { return [['Field' => 'dispatch_approval_id'], ['Field' => 'review_revision'], ['Field' => 'calibration_run_id']]; }
		if (str_contains($sql, 'SHOW COLUMNS FROM wp_cf7aiv_review_suggestions')) { return [['Field' => 'dispatch_approval_id'], ['Field' => 'reviewed_outcome_id']]; }
		if (str_contains($sql, 'SHOW INDEX FROM wp_cf7aiv_review_suggestions')) { return [['Key_name' => 'dispatch_approval_id', 'Non_unique' => 0, 'Column_name' => 'dispatch_approval_id', 'Seq_in_index' => 1]]; }
		$rows = [];
		if ($this->newIndex) { $rows[] = ['Key_name' => 'fingerprint', 'Non_unique' => 0, 'Column_name' => 'fingerprint']; }
		if ($this->nonUniqueFingerprint) { $rows[] = ['Key_name' => 'fingerprint', 'Non_unique' => 1, 'Column_name' => 'fingerprint']; }
		if ($this->compositeFingerprint) {
			$rows[] = ['Key_name' => 'fingerprint', 'Non_unique' => 0, 'Column_name' => 'fingerprint', 'Seq_in_index' => 1];
			$rows[] = ['Key_name' => 'fingerprint', 'Non_unique' => 0, 'Column_name' => 'source_type', 'Seq_in_index' => 2];
		}
		$rows[] = ['Key_name' => 'dispatch_approval_id', 'Non_unique' => 0, 'Column_name' => 'dispatch_approval_id', 'Seq_in_index' => 1];
		if ($this->oldIndex) {
			$rows[] = ['Key_name' => 'fingerprint_source', 'Non_unique' => 0, 'Column_name' => 'fingerprint'];
			$rows[] = ['Key_name' => 'fingerprint_source', 'Non_unique' => 0, 'Column_name' => 'source_type'];
		}
		return $rows;
	}
}

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

function dedup_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$schema = CF7AIV\Infrastructure\Schema::definitions('wp_')['wp_cf7aiv_items'];
dedup_assert(str_contains($schema, 'UNIQUE KEY fingerprint (fingerprint)'), 'Items must have a cross-source unique fingerprint index.');
dedup_assert(! str_contains($schema, 'UNIQUE KEY fingerprint_source'), 'The obsolete source-scoped unique index must be removed from the target schema.');
dedup_assert(version_compare(CF7AIV\Infrastructure\Migration::VERSION, '2', '>='), 'The schema migration version must retain the global-dedup upgrade for existing installations.');

$GLOBALS['wpdb'] = new DedupWpdb();
$item = [
	'fingerprint' => str_repeat('a', 64), 'source_type' => 'flamingo', 'source_reference' => '55', 'form_id' => 12,
	'attribution_encrypted' => 'encrypted-a', 'consent_encrypted' => 'encrypted-c', 'attribution_ready' => true,
	'email_hash' => null, 'phone_hash' => null, 'is_calibration' => false, 'is_historical' => false,
	'created_at' => '2026-07-22 12:00:00', 'updated_at' => '2026-07-22 12:00:00',
];
(new CF7AIV\Intake\ItemRepository())->persist($item);
$repositorySql = implode("\n", $GLOBALS['wpdb']->queries);
dedup_assert(str_contains($repositorySql, "VALUES(source_type) = 'flamingo'"), 'Upsert must prefer a useful Flamingo source link over a generic CF7 link.');
dedup_assert(str_contains($repositorySql, 'calibration_run_id'), 'Item persistence must retain the immutable calibration run ID for downstream cancellation checks.');
dedup_assert(str_contains($repositorySql, 'WHERE fingerprint ='), 'Repository lookup must use the durable fingerprint.');
dedup_assert(! str_contains($repositorySql, 'WHERE fingerprint = ' . "'" . str_repeat('a', 64) . "' AND source_type"), 'Repository lookup must not scope deduplication by source type.');

$GLOBALS['wpdb'] = new DedupWpdb();
CF7AIV\Infrastructure\Migration::migrate();
$migrationSql = implode("\n", $GLOBALS['wpdb']->queries);
$addAt = strpos($migrationSql, 'ADD UNIQUE KEY fingerprint ');
$dropAt = strpos($migrationSql, 'DROP INDEX fingerprint_source');
dedup_assert($addAt !== false && $dropAt !== false && $addAt < $dropAt, 'Migration must add/confirm the new unique fingerprint before dropping the obsolete index.');
dedup_assert(($GLOBALS['dedup_options']['cf7aiv_schema_version'] ?? null) === CF7AIV\Infrastructure\Migration::VERSION, 'Migration must record the currently declared schema version after index replacement.');

// A previous incomplete upgrade could leave an index called "fingerprint" that
// is not unique. Its name alone must never be treated as the global dedup key.
$GLOBALS['wpdb'] = new DedupWpdb();
$GLOBALS['wpdb']->nonUniqueFingerprint = true;
CF7AIV\Infrastructure\Migration::migrate();
$nonUniqueMigrationSql = implode("\n", $GLOBALS['wpdb']->queries);
$dropNamedAt = strpos($nonUniqueMigrationSql, 'DROP INDEX fingerprint');
$addUniqueAt = strpos($nonUniqueMigrationSql, 'ADD UNIQUE KEY fingerprint ');
dedup_assert($dropNamedAt !== false && $addUniqueAt !== false && $dropNamedAt < $addUniqueAt, 'A non-unique fingerprint index must be replaced with the required unique global index.');

// A unique composite index with the expected name is still source-scoped and
// must be replaced with the exact single-column global fingerprint contract.
$GLOBALS['dedup_options'] = [];
$GLOBALS['wpdb'] = new DedupWpdb();
$GLOBALS['wpdb']->compositeFingerprint = true;
CF7AIV\Infrastructure\Migration::migrate();
$compositeMigrationSql = implode("\n", $GLOBALS['wpdb']->queries);
dedup_assert(str_contains($compositeMigrationSql, 'DROP INDEX fingerprint') && str_contains($compositeMigrationSql, 'ADD UNIQUE KEY fingerprint (fingerprint)'), 'A unique composite fingerprint index must be replaced with a unique single-column fingerprint index.');
dedup_assert(($GLOBALS['dedup_options']['cf7aiv_schema_version'] ?? null) === CF7AIV\Infrastructure\Migration::VERSION, 'Composite fingerprint repair must be verified before recording the schema version.');

// Legacy duplicates must never be deleted or silently reparented by an
// automatic migration. Fail closed so an operator can resolve them explicitly.
$GLOBALS['dedup_options'] = [];
$GLOBALS['wpdb'] = new DedupWpdb();
$GLOBALS['wpdb']->duplicateGroups = 1;
CF7AIV\Infrastructure\Migration::migrate();
$duplicateMigrationSql = implode("\n", $GLOBALS['wpdb']->queries);
dedup_assert(!str_contains($duplicateMigrationSql, 'DELETE duplicate') && !str_contains($duplicateMigrationSql, 'ADD UNIQUE KEY fingerprint'), 'Automatic migration must not delete duplicate items or add the index while duplicates remain.');
dedup_assert(!isset($GLOBALS['dedup_options']['cf7aiv_schema_version']), 'A migration blocked by legacy duplicates must not record schema completion.');

echo "PASS DedupRegressionTest\n";
