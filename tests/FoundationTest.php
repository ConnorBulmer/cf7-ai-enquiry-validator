<?php
/**
 * Standalone foundation tests. Run: php tests/FoundationTest.php
 */

declare(strict_types=1);

function cf7aiv_assert(bool $condition, string $message): void {
	if (! $condition) {
		throw new RuntimeException($message);
	}
}

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'foundation-test-auth-key');
define('SECURE_AUTH_KEY', 'foundation-test-secure-auth-key');
define('ARRAY_A', 'ARRAY_A');

final class WP_Error { public function __construct(private readonly string $code = 'invalid_schedule') {} public function get_error_code(): string { return $this->code; } }
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function plugin_basename(string $file): string { return 'cf7-ai-enquiry-validator/' . basename($file); }
function load_plugin_textdomain(string $domain, bool $deprecated, string $path): bool { $GLOBALS['foundation_textdomain'] = compact('domain', 'path'); return true; }
function wp_schedule_single_event(int $timestamp, string $hook, array $arguments, bool $wpError): WP_Error { return new WP_Error($GLOBALS['foundation_cron_error'] ?? 'invalid_schedule'); }

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

cf7aiv_assert(CF7AIV\URL === 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/', 'Plugin URL must be calculated through WordPress when available.');
CF7AIV\Plugin::instance()->loadTextdomain();
cf7aiv_assert(($GLOBALS['foundation_textdomain']['domain'] ?? '') === 'cf7-ai-enquiry-validator' && ($GLOBALS['foundation_textdomain']['path'] ?? '') === 'cf7-ai-enquiry-validator/languages', 'Textdomain loading must derive the plugin language path without an undefined constant fatal.');
cf7aiv_assert(CF7AIV\Hooks::AFTER_CAPTURE === 'cf7aiv_after_capture', 'Capture lifecycle action name must be stable.');
cf7aiv_assert(CF7AIV\Hooks::AFTER_CLASSIFICATION === 'cf7aiv_after_classification', 'Classification lifecycle action name must be stable.');
cf7aiv_assert(CF7AIV\Hooks::AFTER_REVIEW === 'cf7aiv_after_review', 'Review lifecycle action name must be stable.');
cf7aiv_assert(CF7AIV\Hooks::AFTER_DELIVERY === 'cf7aiv_after_delivery', 'Delivery lifecycle action name must be stable.');
cf7aiv_assert(CF7AIV\Infrastructure\Queue::enqueue('cf7aiv_test') === false, 'WP-Cron WP_Error responses must normalize to false.');
$GLOBALS['foundation_cron_error'] = 'duplicate_event';
cf7aiv_assert(CF7AIV\Infrastructure\Queue::enqueue('cf7aiv_test') === true, 'WP-Cron refusing an identical pending event means the job is already queued.');
unset($GLOBALS['foundation_cron_error']);
cf7aiv_assert(CF7AIV\Infrastructure\Queue::accepted(0) === false && CF7AIV\Infrastructure\Queue::accepted(false) === false, 'An Action Scheduler 0 and a false must both count as a refused enqueue.');
cf7aiv_assert(CF7AIV\Infrastructure\Queue::accepted(12) === true && CF7AIV\Infrastructure\Queue::accepted(true) === true, 'An action ID or an already-queued true must count as accepted.');

$plaintext = '{"gclid":"test-click-id","landing":"https://example.test/"}';
$ciphertext = CF7AIV\Infrastructure\Crypto::encrypt($plaintext);
cf7aiv_assert($ciphertext !== $plaintext, 'Encryption must not return plaintext.');
cf7aiv_assert(CF7AIV\Infrastructure\Crypto::decrypt($ciphertext) === $plaintext, 'Encrypted data must decrypt using the site key.');

$settings = CF7AIV\Infrastructure\Settings::defaults();
cf7aiv_assert($settings['automation']['mode'] === 'shadow', 'New installs must default to shadow mode.');
cf7aiv_assert(($settings['setup']['stage'] ?? '') === 'setup', 'New installs must begin in Setup.');
cf7aiv_assert(($settings['ui']['advanced_mode'] ?? null) === false, 'Advanced mode must default off.');
cf7aiv_assert(($settings['automation']['verification_mode'] ?? '') === 'off', 'Second-pass verification must be opt-in.');
cf7aiv_assert(($settings['calibration']['default_sample_size'] ?? 0) === 25, 'Calibration must suggest 25 records.');
cf7aiv_assert(! array_key_exists('minimum_confidence', $settings['automation']), 'Model confidence must not have an automation threshold.');
cf7aiv_assert($settings['privacy']['enhanced_matching'] === false, 'Enhanced matching must be disabled by default.');
cf7aiv_assert($settings['privacy']['delete_on_uninstall'] === false, 'Uninstall deletion must be disabled by default.');
cf7aiv_assert(CF7AIV\Infrastructure\Privacy::normalizedEmailHash(' Person@Example.test ') === hash('sha256', 'person@example.test'), 'Privacy matching must use normalized hashes, never raw email.');
$legacySettings = [
	'automation' => ['mode' => 'live'],
	'destinations' => ['ga4' => ['enabled' => true, 'dry_run' => false, 'measurement_id' => 'G-EXISTING']],
	'custom_extension' => ['preserve' => true],
];
$hydratedSettings = CF7AIV\Infrastructure\Settings::withDefaults($legacySettings);
cf7aiv_assert(($hydratedSettings['destinations']['server_gtm']['dry_run'] ?? null) === true, 'Existing installations must gain newly introduced destination defaults.');
cf7aiv_assert(($hydratedSettings['destinations']['ga4']['measurement_id'] ?? '') === 'G-EXISTING' && ($hydratedSettings['destinations']['ga4']['dry_run'] ?? true) === false, 'Settings hydration must preserve existing destination configuration.');
cf7aiv_assert(($hydratedSettings['automation']['mode'] ?? '') === 'live' && ($hydratedSettings['custom_extension']['preserve'] ?? false) === true, 'Settings hydration must preserve existing and extension-owned values.');

$schema = CF7AIV\Infrastructure\Schema::definitions('wp_');
cf7aiv_assert(isset($schema['wp_cf7aiv_items'], $schema['wp_cf7aiv_policies'], $schema['wp_cf7aiv_deliveries']), 'Schema must define all three plugin tables.');
cf7aiv_assert(str_contains($schema['wp_cf7aiv_items'], 'fingerprint'), 'Items table must persist submission fingerprints.');
cf7aiv_assert(str_contains($schema['wp_cf7aiv_deliveries'], 'idempotency_key'), 'Deliveries table must enforce idempotency.');
cf7aiv_assert(str_contains($schema['wp_cf7aiv_items'], 'KEY state_created_at (state,created_at)'), 'Items table must index state with created_at for queue scans.');
foreach (['outcome_id', 'predicted_tier_id', 'predicted_outcome_id', 'decision_state', 'evidence_json', 'missing_evidence_json', 'exclusion_json', 'verification_state', 'provider_usage_json', 'reviewed_tier_id', 'reviewed_outcome_id'] as $column) {
	cf7aiv_assert(str_contains($schema['wp_cf7aiv_items'], $column), "Items schema must contain {$column}.");
}
foreach (['policy_format', 'pack_key', 'pack_version'] as $column) {
	cf7aiv_assert(str_contains($schema['wp_cf7aiv_policies'], $column), "Policies schema must contain {$column}.");
}
cf7aiv_assert(isset($schema['wp_cf7aiv_setup_suggestions']), 'Schema must define setup suggestions.');
cf7aiv_assert(isset($schema['wp_cf7aiv_calibration_runs']), 'Schema must define authoritative calibration runs.');
cf7aiv_assert(CF7AIV\Infrastructure\Migration::VERSION === '10', 'Review-revision CAS and authoritative calibration runs require schema version 10.');
$itemsSchema = $schema['wp_cf7aiv_items'];
$reviewSchema = $schema['wp_cf7aiv_review_suggestions'];
cf7aiv_assert(str_contains($itemsSchema, 'dispatch_approval_id char(64) NULL') && str_contains($itemsSchema, 'UNIQUE KEY dispatch_approval_id (dispatch_approval_id)'), 'Items must store a unique immutable dispatch approval ID.');
cf7aiv_assert(str_contains($itemsSchema, 'review_revision bigint(20) unsigned NOT NULL DEFAULT 0'), 'Items must persist a monotonic review revision for approval CAS.');
cf7aiv_assert(str_contains($itemsSchema, 'calibration_run_id varchar(64) NULL'), 'Calibration items must retain their immutable run ID so downstream workers can fail closed.');
$runSchema = $schema['wp_cf7aiv_calibration_runs'];
foreach (['run_id varchar(64) NOT NULL', 'state varchar(20) NOT NULL', 'source_key varchar(32) NOT NULL', 'PRIMARY KEY  (run_id)'] as $fragment) {
	cf7aiv_assert(str_contains($runSchema, $fragment), "Calibration-run schema must contain {$fragment}.");
}
cf7aiv_assert(str_contains($reviewSchema, 'dispatch_approval_id char(64) NULL') && str_contains($reviewSchema, 'reviewed_outcome_id varchar(64) NULL') && str_contains($reviewSchema, 'UNIQUE KEY dispatch_approval_id (dispatch_approval_id)'), 'Review audits must uniquely store the same approval ID and exact reviewed outcome.');

$GLOBALS['wpdb'] = new class {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $lastSql = '';
	public function prepare(string $sql, mixed ...$arguments): string { return $sql; }
	public function query(string $sql): int { $this->lastSql = $sql; return 1; }
	public function get_var(string $sql): int { return 73; }
};
$policyRecord = [
	'policy_key' => 'global', 'version' => 'immutable-v1', 'policy_format' => 'cf7aiv-policy/v2',
	'pack_key' => 'agency', 'pack_version' => '1.0.0', 'definition_json' => '{}',
	'compiled_prompt' => 'prompt', 'compiled_prompt_hash' => hash('sha256', 'prompt'),
	'thresholds_json' => '[]', 'examples_json' => '[]', 'created_by' => 1,
	'created_at' => '2026-07-22 10:00:00', 'updated_at' => '2026-07-22 10:00:00',
];
cf7aiv_assert((new CF7AIV\Classification\WpPolicyStore())->save($policyRecord) === 73, 'Policy store must retain its existing-row ID lookup.');
$duplicateSql = substr($GLOBALS['wpdb']->lastSql, strpos($GLOBALS['wpdb']->lastSql, 'ON DUPLICATE KEY UPDATE'));
$guardedFields = ['policy_format', 'pack_key', 'pack_version', 'definition_json', 'compiled_prompt', 'compiled_prompt_hash', 'thresholds_json', 'examples_json', 'updated_at', 'published_at'];
$compactDuplicateSql = preg_replace('/\s+/', '', $duplicateSql);
foreach ($guardedFields as $field) {
	cf7aiv_assert(str_contains((string) $compactDuplicateSql, "{$field}=IF(status='draft',VALUES({$field}),{$field})"), "Duplicate policy writes must update {$field} only while the existing version is a draft.");
}
cf7aiv_assert(preg_match('/(?:UPDATE|,)\s*status\s*=/i', $duplicateSql) !== 1, 'Duplicate policy writes must never demote a published or superseded version to draft.');

final class FoundationLifecycleWpdb {
	public string $prefix = 'wp_';
	/** @var list<string> */
	public array $queries = [];
	public ?array $lockedTarget = ['id' => 2, 'status' => 'draft'];
	public bool $failTargetUpdate = false;
	public function prepare(string $sql, mixed ...$arguments): string {
		return $sql . ' /* ' . json_encode($arguments, JSON_UNESCAPED_SLASHES) . ' */';
	}
	public function get_row(string $sql, mixed $format = null): ?array {
		$this->queries[] = $sql;
		return str_contains($sql, 'version=%s') ? $this->lockedTarget : null;
	}
	public function get_results(string $sql, mixed $format = null): array {
		$this->queries[] = $sql;
		return [];
	}
	public function query(string $sql): int|false {
		$this->queries[] = $sql;
		if ($this->failTargetUpdate && str_contains($sql, "status='published'") && str_contains($sql, "version=%s")) { return 0; }
		return 1;
	}
}

$missingPolicyDb = new FoundationLifecycleWpdb();
$missingPolicyDb->lockedTarget = null;
$GLOBALS['wpdb'] = $missingPolicyDb;
cf7aiv_assert(!(new CF7AIV\Classification\WpPolicyStore())->publish('global', 'missing'), 'Publishing a missing policy version must fail.');
$missingPolicySql = implode("\n", $missingPolicyDb->queries);
cf7aiv_assert(str_contains($missingPolicySql, 'START TRANSACTION') && str_contains($missingPolicySql, 'FOR UPDATE') && str_contains($missingPolicySql, 'ROLLBACK') && !str_contains($missingPolicySql, "SET status='superseded'"), 'A missing policy target must roll back without superseding the current policy.');

$policyDb = new FoundationLifecycleWpdb();
$GLOBALS['wpdb'] = $policyDb;
cf7aiv_assert((new CF7AIV\Classification\WpPolicyStore())->publish('global', 'v2'), 'A locked draft policy must publish atomically.');
$policySql = implode("\n", $policyDb->queries);
$targetPublishAt = strpos($policySql, "SET status='published'");
$supersedeAt = strpos($policySql, "SET status='superseded'");
cf7aiv_assert(str_contains($policySql, 'START TRANSACTION') && str_contains($policySql, 'FOR UPDATE') && $targetPublishAt !== false && $supersedeAt !== false && $targetPublishAt < $supersedeAt && str_contains($policySql, 'COMMIT'), 'Policy publication must lock, publish the target, supersede older versions, and commit as one transaction.');

$mappingDb = new FoundationLifecycleWpdb();
$GLOBALS['wpdb'] = $mappingDb;
$mappingLifecycle = new CF7AIV\Delivery\WpMappingLifecycle();
cf7aiv_assert($mappingLifecycle->publish('care-ga4', 'v2'), 'A locked draft mapping must publish atomically.');
$mappingDb->lockedTarget = ['id' => 2, 'status' => 'published'];
cf7aiv_assert($mappingLifecycle->enable('care-ga4', 'v2'), 'A locked published mapping must enable atomically.');
$mappingSql = implode("\n", $mappingDb->queries);
cf7aiv_assert(substr_count($mappingSql, 'START TRANSACTION') === 2 && substr_count($mappingSql, 'FOR UPDATE') >= 2 && str_contains($mappingSql, "CASE WHEN version=%s AND status='published' THEN 1 ELSE 0 END"), 'Mapping publish/enable must serialize the mapping-key range and enable at most the selected published version.');

$mappingDb->lockedTarget = null;
$mappingDb->queries = [];
cf7aiv_assert(!$mappingLifecycle->publish('care-ga4', 'missing') && !str_contains(implode("\n", $mappingDb->queries), "SET status='superseded'"), 'A missing mapping target must not supersede the current mapping.');

$repositorySqlDb = new class {
	public string $prefix = 'wp_';
	public string $sql = '';
	public function prepare(string $sql, mixed ...$arguments): string { $this->sql = $sql; return $sql; }
	public function get_results(string $sql, mixed $format = null): array { $this->sql = $sql; return []; }
};
$GLOBALS['wpdb'] = $repositorySqlDb;
(new CF7AIV\Delivery\WpMappingRepository())->publishedForTier('care');
cf7aiv_assert(str_contains($repositorySqlDb->sql, 'NOT EXISTS') && str_contains($repositorySqlDb->sql, 'newer.mapping_key=mapping.mapping_key'), 'Mapping resolution must fail closed to the newest enabled version if legacy/concurrent duplicate versions exist.');

$result = new CF7AIV\Domain\ClassificationResult('high_value_care', 'qualified', 0.96, 'A care enquiry.', []);
cf7aiv_assert($result->isHighConfidence(0.90), 'Result confidence helper must honour the threshold.');

$event = new CF7AIV\Domain\ConversionEvent('event-123', 'high_value_care', 'qualify_lead', 100.0, 'GBP', '2026-07-22T12:00:00+00:00', [], [], []);
cf7aiv_assert($event->eventId === 'event-123', 'Conversion event must preserve the stable event ID.');

echo "PASS FoundationTest\n";
