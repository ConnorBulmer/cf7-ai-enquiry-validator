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

// The reason is persisted beside the flag it explains, not held in a one-read
// transient. A transient was consumed by whichever page load happened first -
// including one that rendered it inside a hidden tab panel - so refreshing lost
// it and the operator saw nothing at all.
visible_assert(str_contains($integrations, 'Ga4Validation::error()'), 'The GA4 reason must be read from its own option.');
visible_assert(str_contains($integrations, 'Ga4Validation::isPassed()'), 'The validation badge must read the dedicated result.');
// The shared settings array is rewritten in full on every page load, so an
// asynchronously produced result stored there loses the race.
$hooks = file_get_contents(__DIR__ . '/../src/Admin/AdminHooks.php');
visible_assert(is_string($hooks), 'AdminHooks source must be readable.');
$validation = file_get_contents(__DIR__ . '/../src/Admin/Ga4Validation.php');
visible_assert(is_string($validation), 'Validation store source must be readable.');
visible_assert(str_contains($validation, "const OPTION = 'cf7aiv_ga4_validation'"), 'The result must have its own option.');
visible_assert(str_contains($validation, 'could not be stored'), 'A lost write must report itself rather than pass silently.');
visible_assert(str_contains($hooks, 'Ga4Validation::invalidate()'), 'Changing GA4 credentials must withdraw a previous pass.');
visible_assert(str_contains($hooks, 'Ga4Validation::isPassed()'), 'The live transition must gate on the dedicated result.');
visible_assert(str_contains($integrations, 'cf7aiv-ga4-blocked'), 'A blocked GA4 destination must be reported at page level, not only inside its tab.');

$hooks = file_get_contents(__DIR__ . '/../src/Admin/AdminHooks.php');
visible_assert(is_string($hooks), 'AdminHooks source must be readable.');
visible_assert(str_contains($hooks, 'Ga4Validation::failed('), 'The GA4 reason must be recorded when validation fails.');
visible_assert(! str_contains($hooks, 'takeGa4ValidationReason'), 'The one-read transient must be gone.');
visible_assert(! str_contains($hooks, 'ga4_reason='), 'The GA4 reason must never enter the query string.');

$settingsSource = file_get_contents(__DIR__ . '/../src/Infrastructure/Settings.php');
visible_assert(is_string($settingsSource), 'Settings source must be readable.');


// Success must clear a stale reason, or a fixed problem keeps being reported.
visible_assert(str_contains($hooks, 'Ga4Validation::passed();'), 'A successful validation must record a pass, clearing any previous reason.');

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

// ─── Slow provider work runs off the request ─────────────────────────────────
// A destination check that contacts Google can outlive PHP's execution limit on
// a slow or filtered network. Run inline it took the whole request with it, so
// nothing was recorded and the page simply reloaded with no result and no
// reason - which is exactly what was observed on a live site.

visible_assert(str_contains($hooks, 'cf7aiv_validate_ga4_job'), 'GA4 validation must have a queued job.');
visible_assert(str_contains($hooks, 'runGa4Validation'), 'The queued job must have a handler.');
visible_assert(str_contains($hooks, "Queue::enqueue('cf7aiv_validate_ga4_job')"), 'The admin action must enqueue rather than call inline.');
// Refusing to check at all when the queue is unavailable would be worse.
visible_assert(str_contains($hooks, 'self::runGa4Validation();'), 'There must be an inline fallback when the queue is unavailable.');
visible_assert(str_contains($hooks, 'Ga4Validation::queued()'), 'The queued state must be recorded so the screen can show it.');
visible_assert(str_contains($integrations, "AdminUi::badge('Checking'"), 'A queued check must be shown as in progress.');

// ─── An activity log the operator can actually reach ─────────────────────────
// Managed hosting frequently does not expose the PHP error log, which left the
// plugin diagnosing its own failures with no way to hand over the answer.

$health = file_get_contents(__DIR__ . '/../src/Admin/AdminPages.php');
visible_assert(is_string($health), 'Health page source must be readable.');
visible_assert(str_contains($health, 'Plugin activity log'), 'The activity log must appear on Tracking Health and Logs.');
visible_assert(str_contains($health, 'EventLog::recent'), 'The activity log must read recorded events.');

$eventLog = file_get_contents(__DIR__ . '/../src/Admin/EventLog.php');
visible_assert(is_string($eventLog), 'Event log source must be readable.');
visible_assert(str_contains($eventLog, 'AdminSafe::log'), 'Events must be scrubbed before storage, not on display.');
visible_assert(str_contains($eventLog, "add_action('cf7aiv_admin_action_failed'"), 'Existing failure broadcasts must feed the log.');
visible_assert(str_contains($eventLog, "add_action('cf7aiv_delivery_gate'"), 'Delivery gate decisions must feed the log.');
visible_assert(str_contains($hooks, 'EventLog::register();'), 'The log must be attached during registration.');

echo "PASS VisibleStateTest\n";
