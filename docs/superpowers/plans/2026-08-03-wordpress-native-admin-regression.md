# WordPress-native Admin Refresh and Regression Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship plugin version 0.2.16 with a responsive, accessible, WordPress-native administration interface and comprehensive portable/live regression evidence, without changing schema or delivery behaviour.

**Architecture:** Add one server-side `AdminUi` presentation helper and use it to turn existing dashboard and health arrays into escaped semantic HTML. Keep all services, settings, nonces, capabilities, mappings, consent, classification, delivery, and database behaviour unchanged; extend only plugin-scoped CSS and test coverage. Validate the package through standalone tests, a four-cell disposable WordPress/CF7 matrix, archive inspection, and guarded live acceptance.

**Tech Stack:** WordPress 6.5+, PHP 8.1/8.3, Contact Form 7, Flamingo, CFDB7, server-rendered HTML, plugin-scoped CSS, Node.js 20, Playwright 1.62.1, WP-CLI, Docker Compose, PowerShell, Git.

## Global Constraints

- Target release is exactly `0.2.16`; `CF7AIV\Infrastructure\Migration::VERSION` remains exactly `'10'`.
- No database definition, migration, classification, delivery, consent, attribution, mapping, or public-site behaviour change is permitted.
- All new CSS selectors are rooted beneath `.cf7aiv-admin`; no admin asset may load on unrelated WordPress screens or the public site.
- Use WordPress-native typography, colours, controls, buttons, notices, form patterns, and visible focus conventions; add no JavaScript dependency.
- Status meaning must be present in text and never communicated by colour alone.
- Dynamic labels, counts, status values, destination names, and log fields are escaped at the final render boundary.
- Never expose credentials, private submissions, raw provider responses, hidden source detail, or site-specific account/property/tag/form identifiers.
- Preserve all form actions, nonce checks, capability checks, write-only secret behaviour, live-transition guards, and redirect behaviour.
- The page must not overflow horizontally at desktop, tablet, or narrow mobile widths; only labelled table regions may scroll horizontally.
- Remote StackCP/20i calls are serial. Never use, clean, reset, commit, or depend on the dirty server-side Git checkout.
- Production backup files and settings snapshots stay on the server outside `public_html`.

---

## File structure and responsibilities

- Create `src/Admin/AdminUi.php`: escaped reusable presentation primitives and duration formatting only.
- Create `tests/AdminUiTest.php`: unit contracts for presentation primitives and stylesheet scoping.
- Modify `src/Admin/AdminPages.php`: semantic Overview and Tracking Health composition.
- Modify `src/Admin/ReviewQueuePage.php`: labelled local table scrolling and toolbar structure.
- Modify `src/Admin/IntegrationsPage.php`: native panel grouping, button classes, and corrected Microsoft test copy.
- Modify `src/Admin/PolicyLabPage.php`: consistent header/panel/action classes without workflow changes.
- Modify `src/Admin/SetupPage.php`: consistent header/panel/action classes without lifecycle changes.
- Modify `assets/css/admin.css`: the complete prefixed WordPress-native responsive component layer.
- Modify `tests/AdminTest.php`: integrated render, security-boundary, populated/empty, and copy regressions using existing WordPress stubs.
- Modify `tests/wordpress/portability.spec.js`: authenticated administration browser checks in each existing matrix cell.
- Modify `cf7-ai-enquiry-validator.php`, `readme.txt`, `README.md`, `CHANGELOG.md`, `VERIFICATION.md`, and `tests/ReleasePackageTest.php`: release 0.2.16 identity, operator notes, and fresh evidence.

---

### Task 1: Escaped administration presentation primitives

**Files:**
- Create: `src/Admin/AdminUi.php`
- Create: `tests/AdminUiTest.php`

**Interfaces:**
- Consumes: scalar labels/values and structured arrays from existing admin services.
- Produces: `AdminUi::badge(string,string): string`, `AdminUi::metric(string,string,string): string`, `AdminUi::emptyState(string): string`, `AdminUi::panel(string,string,string): string`, `AdminUi::labelCountTable(string,array): string`, `AdminUi::keyValueTable(string,array): string`, `AdminUi::tableRegion(string,string): string`, and `AdminUi::duration(?int): string`.

- [ ] **Step 1: Write the failing primitive and escaping tests**

Create `tests/AdminUiTest.php` with a local assertion function, an `esc_html()` stub, the repository autoloader, and these contracts:

```php
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

ui_assert(AdminUi::duration(null) === 'No pending queue', 'Null queue age must be human-readable.');
ui_assert(AdminUi::duration(59) === '59 seconds', 'Sub-minute duration must use seconds.');
ui_assert(AdminUi::duration(3660) === '1 hour 1 minute', 'Duration must use compact human units.');

echo "PASS AdminUiTest\n";
```

- [ ] **Step 2: Run the primitive test and verify RED**

Run: `php tests/AdminUiTest.php`

Expected: non-zero exit because `CF7AIV\Admin\AdminUi` does not exist.

- [ ] **Step 3: Implement the minimal `AdminUi` helper**

Create `src/Admin/AdminUi.php` as a final static helper. Use `esc_html()` when available and `htmlspecialchars(..., ENT_QUOTES)` otherwise. Use exact tone allow-list `neutral`, `positive`, `warning`, and `negative`; unknown tones become `neutral`. `panel()` permits only space-separated class tokens matching `^[a-z0-9_-]+$`. `tableRegion()` treats its table argument as trusted internal HTML but escapes the region label. `duration()` rounds down and emits at most two non-zero units from days, hours, minutes, and seconds.

```php
<?php
declare(strict_types=1);

namespace CF7AIV\Admin;

final class AdminUi {
	public static function badge(string $label, string $tone = 'neutral'): string {
		$tone = in_array($tone, ['neutral', 'positive', 'warning', 'negative'], true) ? $tone : 'neutral';
		return '<span class="cf7aiv-status-badge is-' . $tone . '">' . self::e($label) . '</span>';
	}

	public static function metric(string $label, string $value, string $description = ''): string {
		$html = '<div class="cf7aiv-metric"><dl><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></dl>';
		if ($description !== '') {
			$html .= '<p>' . self::e($description) . '</p>';
		}
		return $html . '</div>';
	}

	public static function emptyState(string $message): string {
		return '<p class="cf7aiv-empty-state" role="status">' . self::e($message) . '</p>';
	}

	public static function panel(string $title, string $body, string $classes = ''): string {
		$tokens = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$tokens = array_values(array_filter($tokens, static fn(string $token): bool => preg_match('/^[a-z0-9_-]+$/', $token) === 1));
		$class = 'card cf7aiv-panel' . ($tokens === [] ? '' : ' ' . implode(' ', $tokens));
		return '<section class="' . self::e($class) . '"><h2>' . self::e($title) . '</h2>' . $body . '</section>';
	}

	/** @param array<int,array{label:mixed,total:mixed}> $rows */
	public static function labelCountTable(string $caption, array $rows): string {
		$html = '<table class="widefat striped cf7aiv-compact-table"><caption>' . self::e($caption) . '</caption><thead><tr><th scope="col">Label</th><th scope="col">Count</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			$html .= '<tr><th scope="row">' . self::e((string) ($row['label'] ?? '')) . '</th><td>' . self::e((string) ($row['total'] ?? '0')) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/** @param array<int,array{label:mixed,value:mixed}> $rows */
	public static function keyValueTable(string $caption, array $rows): string {
		$html = '<table class="widefat striped cf7aiv-key-value-table"><caption>' . self::e($caption) . '</caption><tbody>';
		foreach ($rows as $row) {
			$html .= '<tr><th scope="row">' . self::e((string) ($row['label'] ?? '')) . '</th><td>' . self::e((string) ($row['value'] ?? '')) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	public static function tableRegion(string $label, string $table): string {
		return '<div class="cf7aiv-table-region" tabindex="0" aria-label="' . self::e($label) . '">' . $table . '</div>';
	}

	public static function duration(?int $seconds): string {
		if ($seconds === null) { return 'No pending queue'; }
		$remaining = max(0, $seconds);
		$parts = [];
		foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'] as $size => $unit) {
			$value = intdiv($remaining, $size);
			if ($value === 0) { continue; }
			$parts[] = $value . ' ' . $unit . ($value === 1 ? '' : 's');
			$remaining %= $size;
			if (count($parts) === 2) { break; }
		}
		return $parts === [] ? '0 seconds' : implode(' ', $parts);
	}

	private static function e(string $value): string {
		return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES);
	}
}
```

- [ ] **Step 4: Run the primitive test and syntax check**

Run: `php tests/AdminUiTest.php`

Expected: `PASS AdminUiTest`.

Run: `php -l src/Admin/AdminUi.php`

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit the primitive boundary**

```bash
git add src/Admin/AdminUi.php tests/AdminUiTest.php
git commit -m "feat: add native admin presentation helpers"
```

---

### Task 2: Semantic Overview dashboard

**Files:**
- Modify: `tests/AdminTest.php`
- Modify: `src/Admin/AdminPages.php:8-32`

**Interfaces:**
- Consumes: `DashboardService::metrics(): array` and every `AdminUi` method from Task 1.
- Produces: escaped Overview panels, metrics, distribution tables, quality tables, and empty states with no raw JSON.

- [ ] **Step 1: Add failing populated and empty Overview assertions**

Immediately after the existing Overview render in `tests/AdminTest.php`, assert:

```php
admin_assert(! str_contains($overview, '<pre>'), 'Overview must not expose raw JSON presentation.');
admin_assert(str_contains($overview, 'cf7aiv-page-header') && str_contains($overview, 'cf7aiv-metric-grid'), 'Overview must use the shared header and metric grid.');
admin_assert(str_contains($overview, '<caption>Counts by state</caption>') && str_contains($overview, '<caption>Counts by tier</caption>') && str_contains($overview, '<caption>Counts by reason</caption>'), 'Overview distributions must render as separate semantic tables.');
admin_assert(str_contains($overview, 'No human-reviewed enquiries yet.'), 'Zero reviewed data must render an explanatory empty state.');
admin_assert(str_contains($overview, 'No destinations are paused.'), 'No paused destinations must render an explicit empty state.');
admin_assert(str_contains($overview, '14 hours 34 minutes') || str_contains($overview, 'No pending queue'), 'Queue age must be human-readable rather than raw seconds.');
```

Extend the existing `$wpdb` test double with populated metric rows containing escaped hostile labels, reviewed quality rows, and a paused destination; render again and assert semantic tables contain escaped labels, a confusion-matrix caption, per-tier counts, and a text-labelled paused warning.

- [ ] **Step 2: Run the integrated admin test and verify RED**

Run: `php tests/AdminTest.php`

Expected: FAIL on the first no-`<pre>` or shared-header assertion.

- [ ] **Step 3: Compose the Overview with `AdminUi`**

Replace the Overview echo chain with:

- a `.cf7aiv-page-header` containing the existing `<h1>` and concise description;
- automation and setup badges using `positive` only for live/complete and `warning` otherwise;
- a `.cf7aiv-metric-grid` containing queue age, attribution readiness, API errors, and reviewed count;
- three distribution panels using `labelCountTable()` and distinct empty-state messages;
- one quality panel with exact agreement, Ads precision, and false-positive values;
- a semantic confusion matrix built from the existing `quality.confusion_matrix` keys and a per-tier label/count table;
- a paused-destinations panel that accepts both list and associative option shapes without exposing values other than destination names/status labels; and
- the existing navigation links inside `.cf7aiv-page-actions`.

Do not change `DashboardService` queries or metric definitions.

Use this composition pattern so empty and populated data take the same escaped
path:

```php
$distributionPanels = '';
foreach (['by_state' => ['Counts by state', 'No classified states yet.'], 'by_tier' => ['Counts by tier', 'No classified tiers yet.'], 'by_reason' => ['Counts by reason', 'No classification reasons yet.']] as $key => [$title, $empty]) {
	$rows = (array) ($metrics[$key] ?? []);
	$body = $rows === [] ? AdminUi::emptyState($empty) : AdminUi::labelCountTable($title, $rows);
	$distributionPanels .= AdminUi::panel($title, $body, 'cf7aiv-distribution-panel');
}

$qualityRows = [
	['label' => 'Reviewed enquiries', 'value' => (string) $reviewed],
	['label' => 'Exact agreement', 'value' => $exactText],
	['label' => 'Ads precision', 'value' => $adsText],
	['label' => 'False positives', 'value' => (string) $falsePositives],
];
$qualityBody = $reviewed === 0
	? AdminUi::emptyState('No human-reviewed enquiries yet.') . AdminUi::keyValueTable('Human-reviewed quality', $qualityRows)
	: AdminUi::keyValueTable('Human-reviewed quality', $qualityRows) . self::confusionMatrix((array) ($quality['confusion_matrix'] ?? []));

$pausedRows = [];
foreach ((array) ($metrics['paused_destinations'] ?? []) as $key => $value) {
	$destination = is_int($key) && is_scalar($value) ? (string) $value : (string) $key;
	$pausedRows[] = ['label' => $destination, 'value' => 'Paused'];
}
$pausedBody = $pausedRows === []
	? AdminUi::emptyState('No destinations are paused.')
	: AdminUi::keyValueTable('Paused destinations', $pausedRows);
```

Add `private static function confusionMatrix(array $matrix): string` to
`AdminPages`. It builds the union of predicted and reviewed outcome keys, uses
those values as escaped column/row headers, and prints integer counts with a
caption of `Reviewed confusion matrix`. Return an empty state when the union is
empty. Add `private static function e(string $value): string` only once and use
it for that locally composed table.

- [ ] **Step 4: Run focused tests and lint**

Run: `php tests/AdminUiTest.php`

Expected: `PASS AdminUiTest`.

Run: `php tests/AdminTest.php`

Expected: `PASS AdminTest`.

Run: `php -l src/Admin/AdminPages.php`

Expected: no syntax errors.

- [ ] **Step 5: Commit the Overview**

```bash
git add src/Admin/AdminPages.php tests/AdminTest.php
git commit -m "feat: render semantic admin overview"
```

---

### Task 3: Structured Tracking Health and delivery logs

**Files:**
- Modify: `tests/AdminTest.php`
- Modify: `src/Admin/AdminPages.php:45-71`

**Interfaces:**
- Consumes: `HealthService::inspect(): array`, sanitised delivery rows, and `AdminUi` table/panel primitives.
- Produces: source availability, read-only checks, paused destinations, calibration, and sanitised delivery-error panels with no raw JSON.

- [ ] **Step 1: Add failing Health render assertions**

Render Health with one available source, one unavailable source, one paused destination, one hostile `last_error`, and one ordinary delivery row. Assert:

```php
admin_assert(! str_contains($healthPage, '<pre>'), 'Tracking Health must not expose raw JSON presentation.');
admin_assert(str_contains($healthPage, '<caption>Submission source health</caption>'), 'Source health must use a semantic table.');
admin_assert(str_contains($healthPage, 'Available') && str_contains($healthPage, 'Unavailable'), 'Availability must be stated in text.');
admin_assert(str_contains($healthPage, '<caption>Read-only checks</caption>'), 'Read-only checks must be structured.');
admin_assert(str_contains($healthPage, 'cf7aiv-table-region') && str_contains($healthPage, 'aria-label="Sanitized delivery errors"'), 'Delivery logs must scroll locally in a labelled region.');
admin_assert(str_contains($healthPage, '&lt;script&gt;') && ! str_contains($healthPage, '<script>'), 'Sanitised delivery error text must remain escaped.');
```

Render a second empty state and assert `No submission sources were detected.`, `No destinations are paused.`, and `No delivery errors were recorded.`.

- [ ] **Step 2: Run `AdminTest` and verify RED**

Run: `php tests/AdminTest.php`

Expected: FAIL because Health still renders two `<pre>` elements.

- [ ] **Step 3: Replace Health JSON with semantic output**

In `AdminPages::health()`:

- create rows from `health.sources`, showing source key, `Available`/`Unavailable`, and count or `Not available`;
- convert the three read-only checks to human labels and `Read only` badges/text;
- render paused destination names without serialising arbitrary option values;
- wrap the existing calibration form in a native card and preserve every field, nonce, date default, bounds, and opt-in copy;
- render `id`, `destination_key`, `state`, `last_error`, and `updated_at` in a scoped-header table;
- pass the delivery table through `AdminUi::tableRegion('Sanitized delivery errors', ...)`; and
- use explicit empty states for sources, paused destinations, and logs.

Do not change `HealthService`, the delivery query, the 100-row limit, or `AdminSafe::log()`.

Build source and check rows with these exact transformations:

```php
$sourceRows = [];
foreach ((array) ($health['sources'] ?? []) as $sourceKey => $source) {
	$source = (array) $source;
	$sourceRows[] = [
		'label' => (string) $sourceKey,
		'value' => ! empty($source['available'])
			? 'Available — ' . (int) ($source['count'] ?? 0) . ' records'
			: 'Unavailable',
	];
}

$checkLabels = [
	'landing_navigation' => 'Landing navigation',
	'form_presence' => 'Form presence',
	'saved_attribution' => 'Saved attribution',
];
$checkRows = [];
foreach ($checkLabels as $key => $label) {
	$checkRows[] = ['label' => $label, 'value' => (($health['checks'][$key] ?? '') === 'read_only' ? 'Read only' : 'Unavailable')];
}
```

Compose delivery rows into a `widefat striped` table using escaped `id`,
`destination_key`, `state`, `last_error`, and `updated_at` cells, then pass that
complete table to `AdminUi::tableRegion()`.

- [ ] **Step 4: Run focused tests and lint**

Run: `php tests/AdminTest.php`

Expected: `PASS AdminTest`.

Run: `php tests/AdminPrivacyTest.php`

Expected: `PASS AdminPrivacyTest`.

Run: `php -l src/Admin/AdminPages.php`

Expected: no syntax errors.

- [ ] **Step 5: Commit structured Health output**

```bash
git add src/Admin/AdminPages.php tests/AdminTest.php
git commit -m "feat: structure tracking health output"
```

---

### Task 4: Responsive queue and scoped WordPress-native CSS

**Files:**
- Modify: `tests/AdminUiTest.php`
- Modify: `tests/AdminTest.php`
- Modify: `src/Admin/ReviewQueuePage.php:20-62`
- Modify: `assets/css/admin.css`

**Interfaces:**
- Consumes: Review Queue's existing table HTML and `AdminUi::tableRegion()`.
- Produces: separate filter/bulk toolbars, local table scrolling, prefixed component styling, responsive grids, visible focus, and forced-colour support.

- [ ] **Step 1: Write failing queue and stylesheet contracts**

Add integrated assertions:

```php
admin_assert(str_contains($reviewQueue, 'cf7aiv-toolbar cf7aiv-filter-toolbar'), 'Review filtering must have a dedicated toolbar.');
admin_assert(str_contains($reviewQueue, 'cf7aiv-toolbar cf7aiv-bulk-toolbar'), 'Bulk review must have a separate toolbar.');
admin_assert(str_contains($reviewQueue, 'aria-label="Review queue results"') && str_contains($reviewQueue, 'tabindex="0"'), 'Review results must use a labelled local scroll region.');
```

Extend `tests/AdminUiTest.php` with CSS checks:

```php
$css = file_get_contents(__DIR__ . '/../assets/css/admin.css');
ui_assert(is_string($css), 'Admin stylesheet must be readable.');
foreach (['.cf7aiv-admin {', '.cf7aiv-admin .cf7aiv-metric-grid', '.cf7aiv-admin .cf7aiv-table-region', '.cf7aiv-admin :focus-visible', '@media (max-width: 782px)', '@media (forced-colors: active)'] as $fragment) {
	ui_assert(str_contains($css, $fragment), "Admin stylesheet must contain {$fragment}.");
}
ui_assert(! preg_match('/(^|})\s*(?:html|body|\.wrap|\.card|table|input|select|textarea|button)\b/m', $css), 'Admin selectors must not target global WordPress or public elements.');
ui_assert(! str_contains($css, '!important'), 'Admin CSS must avoid unverified important overrides.');
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php tests/AdminUiTest.php`

Expected: FAIL because required component and responsive rules are absent.

Run: `php tests/AdminTest.php`

Expected: FAIL because queue toolbar and table-region classes are absent.

- [ ] **Step 3: Add queue structure without changing actions**

Wrap the GET filter form with `cf7aiv-toolbar cf7aiv-filter-toolbar`, retain `page=cf7aiv-review`, and keep the exact `review_state` field. Apply `cf7aiv-toolbar cf7aiv-bulk-toolbar` to the existing `.tablenav.top` block. Capture the existing table HTML with output buffering or a local string and pass it to `AdminUi::tableRegion('Review queue results', $table)`; preserve every column, field name, revision value, detail link, nonce, and form boundary.

- [ ] **Step 4: Replace the minimal stylesheet with the scoped component layer**

Define prefixed custom properties only on `.cf7aiv-admin`, then style these exact components:

```css
.cf7aiv-admin {
	--cf7aiv-space-1: 4px;
	--cf7aiv-space-2: 8px;
	--cf7aiv-space-3: 12px;
	--cf7aiv-space-4: 16px;
	--cf7aiv-space-5: 24px;
	--cf7aiv-border: #c3c4c7;
	--cf7aiv-surface: #fff;
	--cf7aiv-surface-subtle: #f6f7f7;
	--cf7aiv-text-muted: #50575e;
}

.cf7aiv-admin .cf7aiv-metric-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	gap: var(--cf7aiv-space-4);
}

.cf7aiv-admin .cf7aiv-table-region {
	max-width: 100%;
	overflow-x: auto;
	-webkit-overflow-scrolling: touch;
}

.cf7aiv-admin .cf7aiv-table-region:focus-visible,
.cf7aiv-admin :focus-visible {
	outline: 2px solid #2271b1;
	outline-offset: 2px;
}

.cf7aiv-admin .cf7aiv-actions .button,
.cf7aiv-admin .cf7aiv-toolbar .button,
.cf7aiv-admin .cf7aiv-safety-action {
	display: inline-flex;
	align-items: center;
	min-height: 44px;
}

@media (max-width: 782px) {
	.cf7aiv-admin .cf7aiv-form-grid,
	.cf7aiv-admin .cf7aiv-metric-grid {
		grid-template-columns: 1fr;
	}
}

@media (forced-colors: active) {
	.cf7aiv-admin .cf7aiv-status-badge,
	.cf7aiv-admin .card,
	.cf7aiv-admin .cf7aiv-table-region {
		border: 1px solid ButtonText;
	}
}
```

Complete the layer for headers, cards/panels, metrics, status tones, empty states, tables, captions, toolbars, forms, action groups, descriptions, and advanced disclosures. Inputs and textareas use `box-sizing: border-box; width: min(100%, 48rem)`. Buttons retain WordPress classes and achieve practical spacing without globally changing core controls.

- [ ] **Step 5: Run queue, CSS, and regression tests**

Run: `php tests/AdminUiTest.php`

Expected: `PASS AdminUiTest`.

Run: `php tests/AdminTest.php`

Expected: `PASS AdminTest`.

Run: `php tests/AdminPrivacyTest.php`

Expected: `PASS AdminPrivacyTest`.

- [ ] **Step 6: Commit responsive queue and CSS**

```bash
git add assets/css/admin.css src/Admin/ReviewQueuePage.php tests/AdminUiTest.php tests/AdminTest.php
git commit -m "feat: add responsive native admin layout"
```

---

### Task 5: Group Integrations and correct Microsoft test copy

**Files:**
- Modify: `tests/AdminTest.php`
- Modify: `src/Admin/IntegrationsPage.php`

**Interfaces:**
- Consumes: existing settings arrays and all existing POST handlers.
- Produces: grouped native panels and action classes with byte-for-byte-compatible field names and corrected privacy-safe Microsoft test explanation.

- [ ] **Step 1: Add failing grouping, copy, and safety assertions**

Add:

```php
admin_assert(substr_count($integrations, 'class="card cf7aiv-integration-panel') >= 7, 'Integration concerns must be grouped into native panels.');
admin_assert(str_contains($integrations, 'uses only a one-off synthetic anonymous ID required by CAPI'), 'Microsoft test copy must describe the required synthetic ID.');
admin_assert(! str_contains($integrations, 'identifier-free diagnostic event'), 'Microsoft test copy must not claim the event is identifier-free.');
admin_assert(str_contains($integrations, 'button button-primary') && str_contains($integrations, 'button button-secondary'), 'Integration actions must use WordPress button hierarchy.');
foreach (['cf7aiv_save_settings','cf7aiv_test_microsoft','cf7aiv_automation_transition','cf7aiv_save_consent','cf7aiv_validate_ga4','cf7aiv_ga_transition','cf7aiv_save_mapping','cf7aiv_mapping_publish','cf7aiv_mapping_enable','cf7aiv_mapping_disable'] as $action) {
	admin_assert(str_contains($integrations, 'value="' . $action . '"'), "Integrations must retain action {$action}.");
}
```

Retain the existing assertions for write-only secret fields, Advanced mode, crafted live flags, encrypted options, and nonces.

- [ ] **Step 2: Run `AdminTest` and verify RED**

Run: `php tests/AdminTest.php`

Expected: FAIL on native panel or Microsoft copy assertions.

- [ ] **Step 3: Group settings and destination panels**

Wrap the main settings form groups with card classes and headings matching the approved order. Add `.cf7aiv-form-grid` only around related label/control groups. Keep secret inputs blank and keep every current `name`, value, min/max, checkbox hidden fallback, Advanced-mode condition, and destination description.

Use `button button-primary` for saving integration settings and `button button-secondary` for ordinary validation/save-draft operations. Use `button` plus `cf7aiv-safety-action` for explicit live/disable/test transitions; do not disable, rename, combine, or auto-submit them.

Render Advanced-mode provider usage as a native disclosure:

```php
echo '<details class="card cf7aiv-advanced"><summary>Provider usage diagnostics</summary>';
echo '<p>Token totals are provider diagnostics. No currency estimate is inferred.</p>';
echo '<table class="widefat striped"><tbody>';
foreach (['calls' => 'Calls', 'input_tokens' => 'Input tokens', 'output_tokens' => 'Output tokens', 'reasoning_tokens' => 'Reasoning tokens'] as $key => $label) {
	echo '<tr><th scope="row">' . self::e($label) . '</th><td>' . (int) $usage[$key] . '</td></tr>';
}
echo '</tbody></table></details>';
```

- [ ] **Step 4: Correct controlled Microsoft test wording**

Render this exact factual meaning:

```text
Sends one zero-value, consent-denied diagnostic event without changing Microsoft settings or mappings. It includes no click ID or enhanced identity and uses only a one-off synthetic anonymous ID required by CAPI.
```

Retain the exact confirmation phrase `SEND_ZERO_VALUE_MICROSOFT_TEST`, nonce action `cf7aiv_test_microsoft`, and POST action `cf7aiv_test_microsoft`.

- [ ] **Step 5: Run integration and Microsoft regressions**

Run: `php tests/AdminTest.php`

Expected: `PASS AdminTest`.

Run: `php tests/MicrosoftUetTestServiceTest.php`

Expected: `PASS MicrosoftUetTestServiceTest`.

Run: `php tests/DeliveryTest.php`

Expected: `PASS DeliveryTest`.

- [ ] **Step 6: Commit Integrations presentation**

```bash
git add src/Admin/IntegrationsPage.php tests/AdminTest.php
git commit -m "feat: clarify and group integration controls"
```

---

### Task 6: Align Policy Lab and Setup presentation

**Files:**
- Modify: `tests/AdminTest.php`
- Modify: `src/Admin/PolicyLabPage.php`
- Modify: `src/Admin/SetupPage.php`

**Interfaces:**
- Consumes: existing starter packs, suggestions, policy draft summary, setup stage, and all existing guarded actions.
- Produces: consistent page headers, panels, action groups, and field layout without workflow changes.

- [ ] **Step 1: Add failing structural preservation tests**

Assert both pages contain `.cf7aiv-page-header`, `.card`, and `.cf7aiv-actions`, while preserving exact workflow controls:

```php
admin_assert(str_contains($policy, 'cf7aiv-page-header') && str_contains($policy, 'cf7aiv-actions'), 'Policy Lab must use shared native structure.');
admin_assert(str_contains($setup, 'cf7aiv-page-header') && str_contains($setup, 'cf7aiv-actions'), 'Setup must use shared native structure.');
foreach (['cf7aiv_save_guided_policy','cf7aiv_policy_publish'] as $action) {
	admin_assert(str_contains($policy, 'value="' . $action . '"'), "Policy Lab must retain {$action}.");
}
foreach (['cf7aiv_setup_scan','cf7aiv_setup_suggestion','cf7aiv_save_guided_policy','cf7aiv_lifecycle_transition'] as $action) {
	admin_assert(str_contains($setup, 'value="' . $action . '"'), "Setup must retain {$action}.");
}
admin_assert(str_contains($setup, 'explicit_opt_in') && str_contains($setup, 'whitelist_fingerprint'), 'Calibration must retain explicit opt-in and whitelist binding.');
```

- [ ] **Step 2: Run `AdminTest` and verify RED**

Run: `php tests/AdminTest.php`

Expected: FAIL because the shared header/action classes are absent.

- [ ] **Step 3: Apply shared structure**

Add the shared header and action classes, preserve numbered headings and lifecycle badges, and place existing form fields into `.cf7aiv-form-grid` only where it improves scanability. Use WordPress button classes as follows:

- ordinary draft/suggestion operations: `button button-secondary`;
- save guided draft: `button button-primary`;
- publish and lifecycle transitions: `button cf7aiv-safety-action`;
- no automatic state transition and no new JavaScript.

Keep every existing conditional, field name, nonce, warning, policy JSON control, draft-only statement, provenance message, calibration whitelist preview, and form scope.

- [ ] **Step 4: Run focused safety tests**

Run: `php tests/AdminTest.php`

Expected: `PASS AdminTest`.

Run: `php tests/AdminPrivacyTest.php`

Expected: `PASS AdminPrivacyTest`.

Run: `php tests/AssistedSetupTest.php`

Expected: `PASS AssistedSetupTest`.

- [ ] **Step 5: Commit Policy Lab and Setup alignment**

```bash
git add src/Admin/PolicyLabPage.php src/Admin/SetupPage.php tests/AdminTest.php
git commit -m "feat: align policy and setup admin screens"
```

---

### Task 7: Full local regression gate

**Files:**
- Modify only if a genuine regression is reproduced: the smallest responsible source file and its focused test.

**Interfaces:**
- Consumes: completed administration changes from Tasks 1-6.
- Produces: clean full-suite and lint evidence before browser-matrix work.

- [ ] **Step 1: Run every standalone PHP test serially**

PowerShell:

```powershell
$tests = Get-ChildItem -LiteralPath tests -Filter '*Test.php' | Sort-Object Name
foreach ($test in $tests) {
	& php $test.FullName
	if ($LASTEXITCODE -ne 0) { throw "Failed: $($test.Name)" }
}
```

Expected: every named test prints `PASS`; count is 26 after adding `AdminUiTest.php`.

- [ ] **Step 2: Diagnose and fix only reproduced failures test-first**

For each failure, rerun only the failing file, identify the first incorrect state transition/output boundary, add a focused assertion to that file, implement the smallest fix, and rerun the focused test. Do not weaken assertions or change delivery behaviour to satisfy presentation tests.

- [ ] **Step 3: Lint every non-vendor PHP file**

```powershell
$files = rg --files -g '*.php' -g '!vendor/**' | Sort-Object
foreach ($file in $files) {
	& php -l $file
	if ($LASTEXITCODE -ne 0) { throw "Lint failed: $file" }
}
```

Expected: every file reports no syntax errors; record the exact file count.

- [ ] **Step 4: Run JavaScript and release-package checks**

Run: `node tests/AttributionJsTest.js`

Expected: `PASS AttributionJsTest`.

Run: `node --check assets/js/attribution.js`

Expected: exit 0.

Run: `php tests/ReleasePackageTest.php`

Expected at this stage: PASS against version 0.2.15, because release identity changes occur in Task 9.

- [ ] **Step 5: Check diff integrity and commit any isolated regression fix**

Run: `git diff --check`

Expected: no whitespace errors.

If Step 2 reproduces a real failure, stop this gate, add the exact failing test
and responsible source paths to this plan, then make a focused fix commit. If
no failure is reproduced, create no empty commit.

---

### Task 8: Disposable WordPress administration browser matrix

**Files:**
- Modify: `tests/wordpress/portability.spec.js`

**Interfaces:**
- Consumes: the existing matrix credentials `admin` / `portability-test-only`, `CF7AIV_BASE_URL`, six admin page slugs, and the mounted plugin source.
- Produces: desktop/tablet/narrow viewport, overflow, local-scroll, control, focus, and forced-colour browser evidence in all four matrix cells.

The serial cells remain exactly:

| WordPress | PHP | Submission source |
| --- | --- | --- |
| 6.9 | 8.1 | Flamingo |
| 6.9 | 8.1 | CFDB7 |
| 7.0.2 | 8.3 | Flamingo |
| 7.0.2 | 8.3 | CFDB7 |

- [ ] **Step 1: Add authenticated admin browser acceptance helpers and tests**

Add:

```js
const adminPages = [
	['cf7aiv', 'AI Enquiry Validator'],
	['cf7aiv-review', 'Review Queue'],
	['cf7aiv-policy', 'Policy Lab'],
	['cf7aiv-integrations', 'Integrations'],
	['cf7aiv-health', 'Tracking Health & Logs'],
	['cf7aiv-setup', 'AI Enquiry Validator Setup'],
];

async function loginAdmin(page) {
	await page.goto(baseURL + '/wp-login.php');
	await page.getByLabel('Username or Email Address').fill('admin');
	await page.getByLabel('Password').fill('portability-test-only');
	await page.getByRole('button', { name: 'Log In' }).click();
	await expect(page.locator('#wpadminbar')).toBeVisible();
}

test('plugin admin screens are responsive and locally scroll wide tables', async ({ browser }) => {
	const context = await browser.newContext();
	const page = await context.newPage();
	await loginAdmin(page);
	for (const viewport of [{ width: 1440, height: 1000 }, { width: 900, height: 1000 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(viewport);
		for (const [slug, heading] of adminPages) {
			await page.goto(baseURL + '/wp-admin/admin.php?page=' + slug);
			await expect(page.locator('.cf7aiv-admin h1')).toContainText(heading);
			const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
			expect(overflow).toBeLessThanOrEqual(1);
		}
	}
	for (const slug of ['cf7aiv-review', 'cf7aiv-health']) {
		await page.goto(baseURL + '/wp-admin/admin.php?page=' + slug);
		const region = page.locator('.cf7aiv-table-region').first();
		await expect(region).toBeVisible();
		await region.focus();
		await expect(region).toBeFocused();
	}
	await context.close();
});
```

Add this second test for semantic and forced-colour acceptance:

```js
test('plugin admin semantics survive forced colours', async ({ browser }) => {
	const context = await browser.newContext({ forcedColors: 'active' });
	const page = await context.newPage();
	await loginAdmin(page);
	for (const [slug, heading] of adminPages) {
		await page.goto(baseURL + '/wp-admin/admin.php?page=' + slug);
		await expect(page.locator('.cf7aiv-admin h1')).toContainText(heading);
		const controls = page.locator('.cf7aiv-admin a, .cf7aiv-admin button, .cf7aiv-admin input, .cf7aiv-admin select, .cf7aiv-admin textarea, .cf7aiv-admin summary');
		expect(await controls.count()).toBeGreaterThan(0);
		for (const badge of await page.locator('.cf7aiv-status-badge').all()) {
			expect((await badge.textContent()).trim()).not.toBe('');
		}
	}
	for (const slug of ['cf7aiv', 'cf7aiv-health']) {
		await page.goto(baseURL + '/wp-admin/admin.php?page=' + slug);
		await expect(page.locator('.cf7aiv-admin pre')).toHaveCount(0);
	}
	await page.goto(baseURL + '/wp-admin/admin.php?page=cf7aiv-integrations');
	await expect(page.locator('.cf7aiv-admin')).toContainText('uses only a one-off synthetic anonymous ID required by CAPI');
	await context.close();
});
```

- [ ] **Step 2: Run one matrix cell as a fast browser acceptance check**

Temporarily invoke the existing bootstrap/run commands for WordPress 7.0.2, PHP 8.3, Flamingo on port 18083, then run:

```powershell
$env:CF7AIV_BASE_URL = 'http://127.0.0.1:18083'
tests\wordpress\node_modules\.bin\playwright.cmd test tests/wordpress/portability.spec.js --workers=1 --reporter=line
```

Expected after Tasks 1-6: the frontend portability tests and the new admin
acceptance tests pass. The page-level assertions supplement the failing
server-render and CSS contracts written before implementation in Tasks 1-6;
do not deliberately corrupt or invert an assertion merely to manufacture a
browser failure.

- [ ] **Step 3: Run the complete four-cell matrix serially**

Run: `powershell -ExecutionPolicy Bypass -File tests/wordpress/run-matrix.ps1`

Expected:

```text
PASS matrix php=8.1 source=flamingo
PASS matrix php=8.1 source=cfdb7
PASS matrix php=8.3 source=flamingo
PASS matrix php=8.3 source=cfdb7
```

Each cell must also pass pre-browser and post-browser runtime assertions. The script must remove its containers and volumes in `finally`.

- [ ] **Step 4: Verify matrix cleanup**

Run: `docker ps -a --filter name=cf7aiv-portability`

Expected: no matching containers.

Run: `docker volume ls --filter name=cf7aiv-portability`

Expected: no matching volumes.

- [ ] **Step 5: Commit browser coverage**

```bash
git add tests/wordpress/portability.spec.js
git commit -m "test: cover responsive plugin administration"
```

---

### Task 9: Release 0.2.16 identity, documentation, and archive verification

**Files:**
- Modify: `tests/ReleasePackageTest.php`
- Modify: `cf7-ai-enquiry-validator.php`
- Modify: `readme.txt`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `VERIFICATION.md`
- Produce: `dist/cf7-ai-enquiry-validator-0.2.16.zip`
- Produce: `dist/cf7-ai-enquiry-validator-0.2.16.zip.sha256`

**Interfaces:**
- Consumes: verified source from Tasks 1-8 and `tools/build-release.php`.
- Produces: one safe WordPress release archive whose header, constant, stable tag, documentation, and test expectation all equal 0.2.16; schema remains 10.

- [ ] **Step 1: Change the release test first and verify RED**

Change `tests/ReleasePackageTest.php` expected metadata from `0.2.15` to `0.2.16`:

```php
$metadata = ReleasePackager::validateMetadata($pluginRoot, '0.2.16');
cf7aiv_release_assert($metadata === [
	'plugin_header' => '0.2.16',
	'version_constant' => '0.2.16',
	'stable_tag' => '0.2.16',
], 'All release identities must agree on 0.2.16.');
```

Run: `php tests/ReleasePackageTest.php`

Expected: FAIL because source metadata remains 0.2.15.

- [ ] **Step 2: Update all release identities**

Set:

- plugin header `Version: 0.2.16`;
- `CF7AIV\VERSION` to `0.2.16`;
- `readme.txt` `Stable tag: 0.2.16`; and
- current-release/build/tag examples in README to 0.2.16.

Do not change `Migration::VERSION` or schema documentation from 10.

- [ ] **Step 3: Document the release**

Prepend a `0.2.16 — 2026-08-03` changelog entry covering semantic Overview/Health output, responsive local table scrolling, native form grouping, accessibility coverage, corrected Microsoft synthetic-ID copy, and unchanged schema 10.

Add a 0.2.16 current-release section to `readme.txt` and README. At the top of `VERIFICATION.md`, record exact test counts, lint count, matrix cells, archive name, SHA-256, and local/browser outcomes. Do not record credentials, account/property/tag/form IDs, private content, click IDs, or raw provider responses.

- [ ] **Step 4: Run the complete suite at release identity**

Repeat Task 7 Steps 1, 3, and 4.

Expected: 26 PHP test files pass, every non-vendor PHP file lints, both JavaScript checks pass, and `ReleasePackageTest` passes for 0.2.16.

- [ ] **Step 5: Re-run the four-cell matrix at the release commit candidate**

Run: `powershell -ExecutionPolicy Bypass -File tests/wordpress/run-matrix.ps1`

Expected: all four named cells pass and clean up.

- [ ] **Step 6: Build and independently inspect the archive**

Run: `php tools/build-release.php --tag=v0.2.16 --output-dir=dist`

Expected: JSON identifies `dist/cf7-ai-enquiry-validator-0.2.16.zip` and its SHA-256 file.

Reopen it through `CF7AIVRelease\ReleasePackager::verifyArchive()`, verify one root named `cf7-ai-enquiry-validator/`, and scan both source diff and archive entries for credential patterns, private-data fixtures, Windows-reserved paths, and site-specific account/property/tag/form identifiers. Expected unsafe match count: zero outside explicitly historical non-packaged design/verification prose.

- [ ] **Step 7: Verify diff and commit the release candidate**

Run: `git diff --check`

Expected: no whitespace errors.

```bash
git add cf7-ai-enquiry-validator.php readme.txt README.md CHANGELOG.md VERIFICATION.md tests/ReleasePackageTest.php
git commit -m "release: prepare native admin refresh 0.2.16"
```

Do not commit generated ZIP or checksum files.

---

### Task 10: Review, deploy, and verify Mosaic production

**Files:**
- Modify after successful acceptance: `VERIFICATION.md`
- Consume: `dist/cf7-ai-enquiry-validator-0.2.16.zip`
- Consume: `dist/cf7-ai-enquiry-validator-0.2.16.zip.sha256`

**Interfaces:**
- Consumes: exact verified release archive, the confirmed StackCP root `/home/sites/25b/3/3f7a206908/public_html`, PHP 8.3 WP-CLI, current encrypted settings, and existing guarded test paths.
- Produces: a server-side rollback backup, hash-matched live 0.2.16 installation, responsive browser evidence, controlled delivery evidence, and a final verification record without secrets or personal data.

- [ ] **Step 1: Perform final source review before production**

Inspect `git status --short --branch`, `git diff origin/main...HEAD --stat`, and the commits from Tasks 1-9. Confirm only intended portable plugin, tests, CSS, and documentation changed; schema is 10; no server Git operation is planned.

- [ ] **Step 2: Inventory live state serially**

Using the configured WordPress/StackCP access, confirm:

- document root exactly `/home/sites/25b/3/3f7a206908/public_html`;
- plugin slug exactly `cf7-ai-enquiry-validator`;
- installed version exactly `0.2.15` before upgrade;
- PHP CLI path `/usr/bin/php83`;
- plugin active state;
- installed schema `10`; and
- existing automation/destination dry-run/live states.

Stop if any exact path, slug, version, schema, or command output is missing.

- [ ] **Step 3: Create rollback assets outside `public_html`**

Create a timestamped directory beneath `/home/sites/25b/3/3f7a206908/backups/`. Copy the exact installed plugin directory there and write the relevant plugin option snapshots there using WP-CLI on the server. List the backup files, hash the plugin copy, and confirm the settings snapshot files are non-empty. Do not download settings or credentials locally.

- [ ] **Step 4: Upload and verify the exact release**

Upload the local 0.2.16 ZIP to a specific server-side temporary path outside `public_html`. Compare the local and remote SHA-256 values. Stop on mismatch or missing output.

Install/upgrade the hash-matched ZIP through PHP 8.3 WP-CLI, activate only if the prior inventory showed active, and remove only the exact temporary uploaded ZIP after successful activation and readback.

- [ ] **Step 5: Run WP-CLI acceptance**

Run serially through `/usr/bin/php83 /usr/local/bin/wp --path=/home/sites/25b/3/3f7a206908/public_html`:

```text
plugin get cf7-ai-enquiry-validator --fields=name,status,version --format=json
option get cf7aiv_schema_version
cf7aiv status
cf7aiv health
cf7aiv dry_run
```

Expected: active version 0.2.16, schema 10, status/health output present, and dry-run succeeds without enabling or altering destinations.

- [ ] **Step 6: Run authenticated browser acceptance**

Inspect all six plugin screens at approximately 1440px, 900px, and 390px viewport widths. Confirm:

- page heading and explanatory header present;
- no page-level horizontal overflow;
- Review Queue and Health table regions scroll locally and accept keyboard focus;
- Overview and Health contain no raw JSON `<pre>` output;
- empty and populated states are readable;
- Integrations uses grouped panels and corrected synthetic-ID copy;
- critical links, fields, buttons, native disclosures, and focus indicators work; and
- forced-colour emulation retains status text and borders.

Capture screenshots that contain no credentials or private enquiry content.

- [ ] **Step 7: Run controlled synthetic end-to-end checks**

Use non-personal synthetic enquiry content and the existing guarded commands/services to verify:

1. AI provider classification returns a valid grounded result;
2. GA4 debug validation succeeds;
3. the existing qualified GA4 live mapping produces one proper event with a stable transaction ID;
4. the Microsoft zero-value controlled transport succeeds with no click ID or enhanced identity and only the one-off synthetic anonymous ID;
5. one synthetic qualified pipeline item reaches its expected delivery state; and
6. repeating the same immutable event/approval identity does not create a duplicate delivery.

Record only statuses, timestamps, anonymised test identifiers, and provider acceptance classes. Do not record credentials, raw payload identity fields, private submissions, account/property/tag IDs, click IDs, or raw provider responses.

- [ ] **Step 8: Verify unchanged external and public behaviour**

Confirm automation mode, consent mode, destination enabled/dry-run/live flags, mapping keys/versions/statuses, and scheduled hooks match the pre-deployment inventory except for transient controlled-test records. Open the public site at desktop/tablet/mobile widths, confirm no `.cf7aiv-admin` CSS appears publicly, submit only the approved synthetic CF7 fixture if one is available, and verify the visitor response remains independent of asynchronous AI/delivery work.

- [ ] **Step 9: Roll back on any material failure**

If activation, schema, WP-CLI, browser, public-page, classification, delivery, privacy, or duplicate checks fail materially, restore the exact plugin backup and server-side settings snapshots, reactivate to the prior state, then repeat version, schema, status, health, public-page, and form checks. Record the failure without leaking sensitive data.

- [ ] **Step 10: Record final evidence and commit**

Update `VERIFICATION.md` with deployed version, schema, archive filename and SHA-256, server backup path, local suite counts, matrix results, browser widths, controlled test outcomes, duplicate result, public-site result, and rollback readiness.

Run the full standalone suite once more if the verification edit changes release-package contents; otherwise run `php tests/ReleasePackageTest.php` and `git diff --check`.

```bash
git add VERIFICATION.md
git commit -m "docs: record live admin refresh verification"
```

---

## Completion gate

The work is complete only when all ten tasks are committed, the worktree is clean, the exact 0.2.16 archive is reproducible and hash-verified, all 26 PHP tests and JavaScript checks pass, every non-vendor PHP file lints, all four disposable cells pass and clean up, live version/schema/status/health checks pass, all six administration screens pass responsive/accessibility inspection, controlled GA4/Microsoft/end-to-end/deduplication checks pass, the public site is unaffected, and rollback evidence exists outside `public_html`.
