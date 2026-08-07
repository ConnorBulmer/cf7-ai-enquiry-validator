<?php
declare(strict_types=1);

define('CF7AIV\\DIR', dirname(__DIR__) . '/');

spl_autoload_register(static function (string $class): void {
	$prefix = 'CF7AIV\\';
	if (! str_starts_with($class, $prefix)) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($path)) { require_once $path; }
});

function visible_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

// ─── Classification failures ─────────────────────────────────────────────────
// The reason was always recorded and never displayed, so a queue of failed items
// offered no information beyond the fact that they had failed.

$review = file_get_contents(__DIR__ . '/../src/Admin/ReviewQueuePage.php');
visible_assert(is_string($review), 'Review queue source must be readable.');
visible_assert(str_contains($review, 'item.last_error'), 'The stored failure reason must be selected.');
visible_assert(str_contains($review, 'Why it failed'), 'The failure reason must have a column.');
visible_assert(str_contains($review, 'failureReason'), 'The failure reason must be rendered through a helper.');
visible_assert(str_contains($review, 'AdminSafe::log'), 'The reason must be sanitized like delivery errors are.');

$page = new ReflectionClass(\CF7AIV\Admin\ReviewQueuePage::class);
$reason = $page->getMethod('failureReason');
$reason->setAccessible(true);
$call = static fn (string $state, string $error): string => $reason->invoke(null, $state, $error);

visible_assert($call('error', 'Provider request timed out.') === 'Provider request timed out.', 'A failed item must show its reason.');
visible_assert($call('retrying', 'Temporary provider failure.') === 'Temporary provider failure.', 'A retrying item must show its reason.');
// A reason beside a healthy item reads as a new problem, so it is withheld.
visible_assert($call('pending', 'stale text') === '', 'A pending item must not show a reason.');
visible_assert($call('', 'stale text') === '', 'An item with no review state must not show a reason.');
visible_assert($call('error', '') === 'No reason recorded', 'A failure with no recorded reason must say so rather than look blank.');
// Whatever a provider wrote must be scrubbed on the way out.
visible_assert(! str_contains($call('error', 'failed with api_key=abcdef1234567890'), 'abcdef1234567890'), 'Credentials must not reach the review queue.');
visible_assert(! str_contains($call('error', 'failed for resident@example.com'), 'resident@example.com'), 'Personal data must not reach the review queue.');

// ─── GA4 validation state ────────────────────────────────────────────────────
// Live delivery silently refuses unless validation has passed, so its result
// being invisible made the next step unexplainable.

$integrations = file_get_contents(__DIR__ . '/../src/Admin/IntegrationsPage.php');
visible_assert(is_string($integrations), 'Integrations source must be readable.');
visible_assert(str_contains($integrations, 'debug_validated'), 'GA4 validation state must be read.');
visible_assert(str_contains($integrations, 'cf7aiv-ga4-validation-state'), 'GA4 validation state must be rendered.');
visible_assert(str_contains($integrations, "AdminUi::badge('Passed'"), 'A passed validation must be shown as passed.');
visible_assert(str_contains($integrations, "AdminUi::badge('Not validated'"), 'An unvalidated destination must say so.');
visible_assert(str_contains($integrations, 'stays in dry-run until this passes'), 'The consequence of not validating must be stated.');

// ─── Slow actions ────────────────────────────────────────────────────────────
// These call a provider synchronously and can take tens of seconds.

$js = file_get_contents(__DIR__ . '/../assets/js/admin.js');
visible_assert(is_string($js), 'Admin JavaScript must be readable.');
foreach (['cf7aiv_setup_scan', 'cf7aiv_validate_ga4', 'cf7aiv_test_microsoft'] as $action) {
	visible_assert(str_contains($js, $action), "The slow action {$action} must report progress.");
}
visible_assert(str_contains($js, 'aria-busy'), 'Progress must be announced to assistive technology.');
visible_assert(str_contains($js, 'role') && str_contains($js, 'aria-live'), 'The busy message must be a live region.');
// A disabled control is omitted from submitted data in some browsers, which
// would drop the action's own button value.
visible_assert(str_contains($js, 'setTimeout'), 'The button must only be disabled after submission begins.');
visible_assert(! preg_match('/button\.disabled = true;\s*\n\s*(?!\}).*form\.submit/', $js), 'The button must not be disabled before the form submits.');

$css = file_get_contents(__DIR__ . '/../assets/css/admin.css');
visible_assert(is_string($css), 'Admin stylesheet must be readable.');
visible_assert(str_contains($css, '.cf7aiv-busy-status'), 'The busy message must be styled.');
visible_assert(! str_contains($css, '!important'), 'Admin CSS must avoid unverified important overrides.');

echo "PASS VisibleStateTest\n";
