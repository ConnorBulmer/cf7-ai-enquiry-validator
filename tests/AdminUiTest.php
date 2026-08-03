<?php
declare(strict_types=1);

function esc_html(string $value): string {
	return htmlspecialchars($value, ENT_QUOTES);
}

spl_autoload_register(static function (string $class): void {
	$prefix = 'CF7AIV\\';
	if (! str_starts_with($class, $prefix)) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($path)) { require_once $path; }
});

function ui_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

use CF7AIV\Admin\AdminUi;

$badge = AdminUi::badge('<Live>', 'positive');
ui_assert(str_contains($badge, 'cf7aiv-status-badge is-positive'), 'Badge tone must be prefixed.');
ui_assert(str_contains($badge, '&lt;Live&gt;') && ! str_contains($badge, '<Live>'), 'Badge labels must be escaped.');

$metric = AdminUi::metric('Queue age', '2 hours', 'Oldest pending item');
ui_assert(str_contains($metric, 'cf7aiv-metric') && str_contains($metric, '<dt>Queue age</dt>') && str_contains($metric, '<dd>2 hours</dd>'), 'Metric cards must use definition semantics.');

$empty = AdminUi::emptyState('No reviewed enquiries yet.');
ui_assert(str_contains($empty, 'role="status"') && str_contains($empty, 'No reviewed enquiries yet.'), 'Empty states must be explicit status text.');

$table = AdminUi::labelCountTable('Counts by state', [['label' => '<classified>', 'total' => '2']]);
ui_assert(str_contains($table, '<caption>Counts by state</caption>') && str_contains($table, '&lt;classified&gt;') && str_contains($table, '<td>2</td>'), 'Count tables must be semantic and escaped.');

$keys = AdminUi::keyValueTable('Health checks', [['label' => 'Landing navigation', 'value' => '<read-only>']]);
ui_assert(str_contains($keys, '<th scope="row">Landing navigation</th>') && str_contains($keys, '&lt;read-only&gt;'), 'Key/value tables must escape values.');

$region = AdminUi::tableRegion('Review queue results', '<table><tr><td>Trusted table</td></tr></table>');
ui_assert(str_contains($region, 'tabindex="0"') && str_contains($region, 'aria-label="Review queue results"'), 'Wide table regions must be keyboard focusable and labelled.');

$css = file_get_contents(__DIR__ . '/../assets/css/admin.css');
ui_assert(is_string($css), 'Admin stylesheet must be readable.');
foreach (['.cf7aiv-admin {', '.cf7aiv-admin .cf7aiv-metric-grid', '.cf7aiv-admin .cf7aiv-table-region', '.cf7aiv-admin :focus-visible', '@media (max-width: 782px)', '@media (forced-colors: active)'] as $fragment) {
	ui_assert(str_contains($css, $fragment), "Admin stylesheet must contain {$fragment}.");
}
ui_assert(
	(bool) preg_match('/\.cf7aiv-admin \.cf7aiv-form-grid\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*repeat\(auto-fit, minmax\(190px, 1fr\)\);[^}]*gap:\s*var\(--cf7aiv-space-4\);[^}]*\}/s', $css),
	'Form grids must define scoped desktop grid display, columns, and spacing.'
);
ui_assert(
	(bool) preg_match('/\.cf7aiv-admin \.card,\s*\.cf7aiv-admin \.cf7aiv-panel\s*\{[^}]*min-width:\s*0;/s', $css),
	'Plugin admin cards must override WordPress minimum widths so responsive grid items can shrink.'
);
ui_assert(
	(bool) preg_match('/\.cf7aiv-admin \.cf7aiv-actions \.button,\s*\.cf7aiv-admin \.cf7aiv-toolbar \.button,\s*\.cf7aiv-admin \.cf7aiv-safety-action\s*\{[^}]*min-height:\s*44px;/s', $css),
	'Buttons in action and toolbar wrappers must retain the scoped 44px minimum target-size contract.'
);
ui_assert(! preg_match('/(^|})\s*(?:html|body|\.wrap|\.card|table|input|select|textarea|button)\b/m', $css), 'Admin selectors must not target global WordPress or public elements.');
ui_assert(! str_contains($css, '!important'), 'Admin CSS must avoid unverified important overrides.');

ui_assert(AdminUi::duration(null) === 'No pending queue', 'Null queue age must be human-readable.');
ui_assert(AdminUi::duration(59) === '59 seconds', 'Sub-minute duration must use seconds.');
ui_assert(AdminUi::duration(3660) === '1 hour 1 minute', 'Duration must use compact human units.');

echo "PASS AdminUiTest\n";
