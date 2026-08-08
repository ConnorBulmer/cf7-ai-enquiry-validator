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

// ─── GA4 validation reason ───────────────────────────────────────────────────
// The debug endpoint exists to say what is wrong. Collapsing its answer into
// "validation failed" discarded the entire reason for calling it, and left a
// destination that would not leave dry-run for reasons nobody could see.

$ga4Source = file_get_contents(__DIR__ . '/../src/Destinations/GA4Destination.php');
visible_assert(is_string($ga4Source), 'GA4 destination source must be readable.');
visible_assert(str_contains($ga4Source, 'validationFailure'), 'GA4 validation messages must be reported.');
visible_assert(! str_contains($ga4Source, "'GA4 debug validation failed.'"), 'The reasonless message must be gone.');

$destination = new ReflectionClass(CF7AIV\Destinations\GA4Destination::class);
$failure = $destination->getMethod('validationFailure');
$failure->setAccessible(true);
$describe = static fn (mixed $messages): string => $failure->invoke(null, $messages);

$typical = $describe([[
	'fieldPath' => 'measurement_id',
	'description' => 'Measurement id not found for this api secret.',
	'validationCode' => 'MEASUREMENT_ID_NOT_FOUND',
]]);
visible_assert(str_contains($typical, 'measurement_id'), 'The offending field must be named.');
visible_assert(str_contains($typical, 'Measurement id not found'), 'The description from GA4 must survive.');
visible_assert(str_contains($typical, 'MEASUREMENT_ID_NOT_FOUND'), 'The validation code must survive.');

// A refusal with no explanation must say so rather than render blank.
visible_assert(str_contains($describe([]), 'no explanation'), 'An empty message list must be stated, not blank.');
visible_assert(str_contains($describe('nonsense'), 'no explanation'), 'A malformed message list must not throw.');

// Bounded, because it reaches the screen.
$many = array_fill(0, 20, ['fieldPath' => 'events', 'description' => str_repeat('long ', 200), 'validationCode' => 'X']);
visible_assert(strlen($describe($many)) <= 400, 'The reported reason must be length-capped.');

visible_assert(str_contains($integrations, 'takeGa4ValidationReason'), 'The GA4 reason must be surfaced on Integrations.');

$hooks = file_get_contents(__DIR__ . '/../src/Admin/AdminHooks.php');
visible_assert(is_string($hooks), 'AdminHooks source must be readable.');
visible_assert(str_contains($hooks, 'rememberGa4Validation'), 'The GA4 reason must be recorded when validation fails.');
visible_assert(str_contains($hooks, 'GA4_VALIDATION_PREFIX'), 'The GA4 reason must use its own transient key.');
visible_assert(! str_contains($hooks, 'ga4_reason='), 'The GA4 reason must never enter the query string.');

// ─── Checkboxes that can actually be cleared ─────────────────────────────────
// An unchecked box is not submitted, and the sanitizer only overwrites a flag
// whose key is present, starting from defaults otherwise. Any flag defaulting to
// true was therefore impossible to turn off: it silently reverted on save.

$checkbox = (new ReflectionClass(CF7AIV\Admin\IntegrationsPage::class))->getMethod('checkbox');
$checkbox->setAccessible(true);
$render = static fn (bool $checked, bool $disabled = false, string $note = ''): string
	=> $checkbox->invoke(null, 'Dry run', 'cf7aiv[destinations][ga4][dry_run]', $checked, '1', $disabled, $note);

$onEditable = $render(true);
visible_assert(substr_count($onEditable, 'type="hidden"') === 1, 'A checkbox must carry exactly one hidden companion.');
visible_assert(str_contains($onEditable, 'value="0"'), 'The companion must submit a cleared value when the box is cleared.');
visible_assert(strpos($onEditable, 'type="hidden"') < strpos($onEditable, 'type="checkbox"'), 'The companion must precede the box so a ticked box wins.');

// Defaults are only honoured when the key is absent, so the key must never be
// absent. This is the assertion that would have caught the reverting dry-run.
$offEditable = $render(false);
visible_assert(str_contains($offEditable, 'value="0"'), 'A cleared box must still submit its key.');
visible_assert(! str_contains($offEditable, 'checked'), 'A cleared box must not render as checked.');

// A disabled control is not submitted either, so its companion must preserve the
// current value. Submitting 0 here would take the destination out of dry-run.
$disabledOn = $render(true, true, 'Governed by the lifecycle transition.');
visible_assert(str_contains($disabledOn, 'disabled'), 'A governed flag must render as disabled.');
visible_assert(str_contains($disabledOn, 'type="hidden" value="1"') || str_contains($disabledOn, 'value="1"'), 'A disabled checked box must preserve its value, not clear it.');
visible_assert(! str_contains(explode('type="checkbox"', $disabledOn)[0], 'value="0"'), 'A disabled checked box must never submit a cleared value.');
visible_assert(str_contains($disabledOn, 'Governed by the lifecycle transition.'), 'A governed flag must explain what governs it.');

visible_assert(str_contains($integrations, 'Dry run is released by'), 'GA4 dry run must state how it is released.');
visible_assert(str_contains($integrations, "\$key === 'ga4'"), 'Only GA4 dry run is governed by the lifecycle.');

// ─── Plugin identity ─────────────────────────────────────────────────────────

$header = file_get_contents(__DIR__ . '/../cf7-ai-enquiry-validator.php');
visible_assert(is_string($header), 'Plugin header must be readable.');
visible_assert(str_contains($header, 'Author: Connor Bulmer'), 'The plugin must name its author.');
visible_assert(! str_contains($header, 'CF7 AI Enquiry Validator contributors'), 'The placeholder author must be gone.');

echo "PASS VisibleStateTest\n";
