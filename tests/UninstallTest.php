<?php

declare(strict_types=1);

function uninstall_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
function get_option(string $name, mixed $default = false): mixed { return $name === 'cf7aiv_settings' ? ['privacy' => ['delete_on_uninstall' => $GLOBALS['uninstall_opt_in']]] : $default; }
function delete_option(string $name): bool { $GLOBALS['uninstall_options'][] = $name; return true; }
function esc_sql(string $value): string { return $value; }

final class UninstallWpdb {
	public string $prefix = 'wp_';
	/** @var list<string> */ public array $queries = [];
	public function query(string $sql): int { $this->queries[] = $sql; return 1; }
}

define('WP_UNINSTALL_PLUGIN', true);
$GLOBALS['uninstall_options'] = [];
$GLOBALS['wpdb'] = new UninstallWpdb();
$GLOBALS['uninstall_opt_in'] = false;
require dirname(__DIR__) . '/uninstall.php';
uninstall_assert($GLOBALS['wpdb']->queries === [] && $GLOBALS['uninstall_options'] === [], 'Uninstall must retain every table and option unless deletion is explicitly enabled.');

$GLOBALS['uninstall_opt_in'] = true;
require dirname(__DIR__) . '/uninstall.php';

$sql = implode("\n", $GLOBALS['wpdb']->queries);
foreach (['cf7aiv_review_suggestions', 'cf7aiv_deliveries', 'cf7aiv_items', 'cf7aiv_calibration_runs', 'cf7aiv_mappings', 'cf7aiv_setup_suggestions', 'cf7aiv_policies'] as $table) {
	uninstall_assert(str_contains($sql, $table), "Uninstall must remove {$table} when deletion is explicitly enabled.");
}
foreach (['cf7aiv_settings', 'cf7aiv_secrets', 'cf7aiv_schema_version', 'cf7aiv_paused_destinations', 'cf7aiv_resumed_destinations', 'cf7aiv_consent_override_audit'] as $option) {
	uninstall_assert(in_array($option, $GLOBALS['uninstall_options'], true), "Uninstall must remove option {$option}.");
}
uninstall_assert(strpos($sql, 'cf7aiv_review_suggestions') < strpos($sql, 'cf7aiv_items') && strpos($sql, 'cf7aiv_deliveries') < strpos($sql, 'cf7aiv_items'), 'Uninstall must remove dependent review suggestions and deliveries before items.');
uninstall_assert(strpos($sql, 'cf7aiv_setup_suggestions') < strpos($sql, 'cf7aiv_policies'), 'Uninstall must remove setup suggestions before policies.');

echo "PASS UninstallTest\n";
