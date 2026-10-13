<?php
/**
 * Admin handler exception guard.
 *
 * Every admin_post handler validates input and hands it to a service that
 * throws when the input is wrong. Registered bare, those throws left the
 * handler uncaught, so WordPress rendered its "critical error" page, mailed the
 * site administrator and logged a fatal - for a value the plugin was correct to
 * reject. This file pins the guard that turns a throw into an HTTP response.
 *
 * It is the only test file that defines wp_die. Everywhere else the suite runs
 * without it, which is what keeps faults surfacing as real exceptions rather
 * than being absorbed; the last assertion here pins that fallback too.
 */
declare(strict_types=1);

namespace CF7AIV\Admin {
	/**
	 * Captured rather than written, so a passing run stays quiet.
	 *
	 * An unqualified error_log() call inside CF7AIV\Admin resolves to this before
	 * falling back to the global function, while function_exists('error_log')
	 * still reports true - which is exactly the pairing the guard relies on.
	 */
	function error_log(string $message): bool { $GLOBALS['guard_logs'][] = $message; return true; }
}

namespace {
	function guard_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }

	$GLOBALS['guard_logs'] = [];
	$GLOBALS['guard_actions'] = [];
	$GLOBALS['guard_fired'] = [];

	define('CF7AIV\\DIR', dirname(__DIR__) . '/');

	final class WpDie extends Exception {
		public function __construct(public readonly string $body, public readonly string $title, public readonly int $response) { parent::__construct($body); }
	}

	/** WordPress halts the request here; throwing is how a test observes the response it would have sent. */
	function wp_die(string $message = '', string $title = '', array $args = []): void { throw new WpDie($message, $title, (int) ($args['response'] ?? 0)); }
	function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['guard_actions'][$hook][] = $callback; }
	function do_action(string $hook, mixed ...$args): void { $GLOBALS['guard_fired'][] = [$hook, $args]; }
	function current_user_can(string $capability): bool { return false; }

	spl_autoload_register(static function (string $class): void {
		$prefix = 'CF7AIV\\';
		if (! str_starts_with($class, $prefix)) { return; }
		$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($path)) { require_once $path; }
	});

	/** @return WpDie|null The response the guarded handler produced, or null if it completed. */
	function guard_run(callable $handler): ?WpDie {
		$GLOBALS['guard_logs'] = [];
		$GLOBALS['guard_fired'] = [];
		try { (\CF7AIV\Admin\AdminHooks::guarded($handler))(); } catch (WpDie $die) { return $die; }
		return null;
	}

	// A handler that completes must not be interfered with.
	$ran = false;
	guard_assert(guard_run(static function () use (&$ran): void { $ran = true; }) === null, 'A successful handler must not produce a response of its own.');
	guard_assert($ran, 'The guard must actually invoke the handler it wraps.');
	guard_assert($GLOBALS['guard_fired'] === [], 'A successful handler must not report a failure.');

	// Bad input is the administrator's to fix, so the message they wrote is the
	// message they get back, with a status that says "your request", not "our crash".
	$_POST = ['action' => 'cf7aiv_review'];
	$refused = guard_run(static function (): void { throw new InvalidArgumentException('Unknown review action.'); });
	guard_assert($refused instanceof WpDie, 'Invalid input must produce a response rather than an uncaught throw.');
	guard_assert($refused->response === 400, 'Invalid input must be refused with 400, not reported as a server fault.');
	guard_assert($refused->title === 'Bad Request', 'A refusal must be titled as a bad request.');
	guard_assert($refused->body === 'Unknown review action.', 'A validation message is written for a person and must be shown to them.');

	// The administrator who triggered a refusal reads it on screen; nobody else
	// sees it. One that only happens sometimes, or to someone else, needs to be
	// observable - but it is not an error, so it stays out of the site log unless
	// debugging is switched on.
	guard_assert(count($GLOBALS['guard_fired']) === 1 && $GLOBALS['guard_fired'][0][0] === 'cf7aiv_admin_action_refused', 'A refusal must be broadcast so it can be observed after the fact.');
	guard_assert($GLOBALS['guard_fired'][0][1] === ['cf7aiv_review', 'Unknown review action.'], 'The refusal broadcast must carry the action and the reason.');
	guard_assert($GLOBALS['guard_logs'] === [], 'A routine refusal must not be written to the site error log outside CF7AIV_DEBUG.');

	// Anything else is a fault the administrator cannot act on, and its message
	// may describe internals - Crypto is the live example, hence this fixture.
	$_POST = ['action' => 'cf7aiv_save_settings'];
	$fault = guard_run(static function (): void { throw new RuntimeException('Could not decrypt CF7 AI Enquiry Validator data.'); });
	guard_assert($fault instanceof WpDie, 'An unexpected fault must produce a response rather than WordPress\' critical error page.');
	guard_assert($fault->response === 500, 'An unexpected fault must be reported as a server error.');
	guard_assert(! str_contains($fault->body, 'Could not decrypt'), 'An internal fault message must not be echoed to the browser.');
	guard_assert(str_contains($fault->body, 'error log'), 'The response must tell the administrator where the detail went.');
	guard_assert(str_contains($fault->body, 'no settings were changed'), 'The response must say what did not happen, so nobody re-runs a half-applied action.');

	// Catching removes the fatal WordPress would have logged unconditionally, so
	// the detail has to be preserved somewhere an administrator can reach it.
	guard_assert(count($GLOBALS['guard_logs']) === 1, 'An unexpected fault must be logged exactly once.');
	guard_assert(str_contains($GLOBALS['guard_logs'][0], 'Could not decrypt CF7 AI Enquiry Validator data.'), 'The log must carry the real message the browser was not shown.');
	guard_assert(str_contains($GLOBALS['guard_logs'][0], 'cf7aiv_save_settings'), 'The log must name the action so a report can be correlated.');
	guard_assert((bool) preg_match('/AdminHandlerGuardTest\.php:\d+/', $GLOBALS['guard_logs'][0]), 'The log must carry the origin, which is all that is left once the stack is discarded.');

	guard_assert(count($GLOBALS['guard_fired']) === 1 && $GLOBALS['guard_fired'][0][0] === 'cf7aiv_admin_action_failed', 'A fault must be broadcast so site owners can observe it.');
	[$firedAction, $firedError] = $GLOBALS['guard_fired'][0][1];
	guard_assert($firedAction === 'cf7aiv_save_settings' && $firedError instanceof RuntimeException, 'The broadcast must carry the action and the original throwable.');

	// A handler that already answered must be left alone. wp_die is filterable and
	// is not guaranteed to end the request - WordPress' own harness makes it throw -
	// so a deliberate refusal can reach the guard as a throwable. Reporting it as a
	// fault turned "you may not do that" into "the plugin broke", and buried the
	// real status under a 500.
	$halted = guard_run(static function (): void { \CF7AIV\Admin\AdminRequestGuard::authorize('nonce', 'cf7aiv_review'); });
	guard_assert($halted instanceof WpDie, 'An unauthorized request must still be stopped.');
	guard_assert($halted->response === 403 && $halted->title === 'Forbidden', 'A deliberate denial must keep its own status, not be re-reported as a server fault.');
	guard_assert($GLOBALS['guard_logs'] === [] && $GLOBALS['guard_fired'] === [], 'A denial is not a fault and must not be logged or broadcast as one.');

	// Errors are not Exceptions. A TypeError from a handler used to be a fatal too.
	$typeError = guard_run(static function (): void { throw new TypeError('Argument #1 must be of type int, string given'); });
	guard_assert($typeError instanceof WpDie && $typeError->response === 500, 'A PHP Error must be caught as well - the guard covers Throwable, not just Exception.');

	// The action name reaches a log file and an action hook, so it is sanitised
	// at the boundary rather than trusted because it "came from our own form".
	$_POST = ['action' => "cf7aiv_review<script>alert(1)</script>\n injected"];
	guard_run(static function (): void { throw new RuntimeException('boom'); });
	guard_assert($GLOBALS['guard_fired'][0][1][0] === 'cf7aiv_reviewscriptalert1scriptinjected', 'The reported action must be reduced to identifier characters.');
	guard_assert(! str_contains($GLOBALS['guard_logs'][0], '<script>') && ! str_contains($GLOBALS['guard_logs'][0], "\n injected"), 'A hostile action value must not be written into the log verbatim.');

	$_POST = [];
	guard_run(static function (): void { throw new RuntimeException('boom'); });
	guard_assert($GLOBALS['guard_fired'][0][1][0] === '' && str_contains($GLOBALS['guard_logs'][0], 'unknown:'), 'A missing action must be reported as unknown rather than blank.');

	// Registration is where the exposure came from: one handler added bare is one
	// fatal page. Assert the wiring, not just the wrapper.
	$GLOBALS['guard_actions'] = [];
	\CF7AIV\Admin\AdminHooks::register();
	$posted = [];
	foreach ($GLOBALS['guard_actions'] as $hook => $callbacks) {
		if (str_starts_with($hook, 'admin_post_')) { $posted[substr($hook, strlen('admin_post_'))] = $callbacks; }
	}
	$expected = [
		'cf7aiv_review', 'cf7aiv_bulk_review', 'cf7aiv_save_settings', 'cf7aiv_save_guided_policy',
		'cf7aiv_lifecycle_transition', 'cf7aiv_automation_transition', 'cf7aiv_ga_transition', 'cf7aiv_save_consent',
		'cf7aiv_policy_import', 'cf7aiv_policy_publish', 'cf7aiv_save_mapping', 'cf7aiv_mapping_publish',
		'cf7aiv_mapping_enable', 'cf7aiv_mapping_disable', 'cf7aiv_validate_ga4', 'cf7aiv_test_microsoft',
		'cf7aiv_setup', 'cf7aiv_setup_scan', 'cf7aiv_setup_suggestion', 'cf7aiv_backfill',
		'cf7aiv_resume_destination', 'cf7aiv_test_provider', 'cf7aiv_accuracy_test', 'cf7aiv_accuracy_cancel', 'cf7aiv_corrections_to_examples',
	];
	sort($expected);
	$registered = array_keys($posted);
	sort($registered);
	guard_assert($registered === $expected, 'The registered admin_post actions must match the published set exactly.');

	foreach ($posted as $action => $callbacks) {
		guard_assert(count($callbacks) === 1 && $callbacks[0] instanceof Closure, "Handler {$action} must be registered through the guard, not as a bare callable.");
	}

	// Invoking each one proves the map has no typo: a wrong method name would be
	// an Error caught as a 500, where a real handler authorizes and returns 403.
	$_POST = [];
	foreach ($posted as $action => $callbacks) {
		$response = null;
		try { ($callbacks[0])(); } catch (WpDie $die) { $response = $die; }
		guard_assert($response instanceof WpDie, "Handler {$action} must respond to an unauthorized request.");
		guard_assert($response->response === 403, "Handler {$action} must authorize before doing anything - a 500 here means its mapped method does not exist.");
	}

	// Switching debugging on is what makes intermittent refusals traceable - the
	// case that is hardest to diagnose, because the person who saw the message is
	// rarely the person reading the log. Defined last so every assertion above ran
	// against the quiet default.
	define('CF7AIV_DEBUG', true);
	$_POST = ['action' => 'cf7aiv_save_mapping'];
	guard_run(static function (): void { throw new InvalidArgumentException('Delivery mapping identifiers are required.'); });
	guard_assert(count($GLOBALS['guard_logs']) === 1, 'Under CF7AIV_DEBUG a refusal must be logged.');
	guard_assert(str_contains($GLOBALS['guard_logs'][0], 'cf7aiv_save_mapping') && str_contains($GLOBALS['guard_logs'][0], 'Delivery mapping identifiers are required.'), 'The debug log must name the action and the reason it was refused.');

	// The matching case - no wp_die, so the original throwable must arrive intact -
	// cannot be asserted here, because this file defines wp_die. It is pinned in
	// AdminTest.php, which defines none.

	echo "PASS AdminHandlerGuardTest\n";
}
