<?php
/** Standalone diagnostics and operational-observability tests. */
declare(strict_types=1);

function diagnostics_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$root = dirname(__DIR__);
$diagnostics = file_get_contents($root . '/src/Admin/DiagnosticsService.php');
$commands = file_get_contents($root . '/src/Cli/Commands.php');
$worker = file_get_contents($root . '/src/Delivery/DeliveryWorker.php');
$guard = file_get_contents($root . '/src/Admin/AdminRequestGuard.php');
$css = file_get_contents($root . '/assets/css/admin.css');
foreach ([$diagnostics, $commands, $worker, $guard, $css] as $source) {
	diagnostics_assert(is_string($source), 'Expected diagnostics source is readable.');
}
diagnostics_assert(str_contains($commands, 'public function doctor'), 'Doctor command must be registered.');
diagnostics_assert(str_contains($commands, 'public function diagnose'), 'Diagnose command must be registered.');
diagnostics_assert(str_contains($commands, 'new DiagnosticsService'), 'CLI commands must use DiagnosticsService.');
diagnostics_assert(str_contains($worker, 'DiagnosticsService::recordGate'), 'Delivery worker must record gate reasons.');
diagnostics_assert(str_contains($diagnostics, "cf7aiv_delivery_gate"), 'Delivery gate lifecycle event must be exposed.');
diagnostics_assert(str_contains($guard, 'wp_die') && str_contains($guard, "'response' => 403"), 'Invalid admin requests must use a controlled HTTP 403 response.');
diagnostics_assert(str_contains($css, '@media (min-width: 783px) and (max-width: 1100px)'), 'Overview tablet layout regression rule must exist.');
diagnostics_assert(str_contains($css, '.cf7aiv-admin .cf7aiv-panel .cf7aiv-compact-table'), 'Tablet rule must constrain Overview compact tables.');
echo "PASS DiagnosticsTest\n";
