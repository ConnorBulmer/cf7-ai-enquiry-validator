<?php
declare(strict_types=1);

define('CF7AIV\\DIR', dirname(__DIR__) . '/');

spl_autoload_register(static function (string $class): void {
	$prefix = 'CF7AIV\\';
	if (! str_starts_with($class, $prefix)) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($path)) { require_once $path; }
});

function diag_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

use CF7AIV\Admin\DiagnosticsService;
use CF7AIV\Providers\DeepSeekProvider;

// The whole point of the change: the class and the reason both survive, so an
// administrator can tell one failure from another.
$described = DiagnosticsService::describeFailure(
	new \CF7AIV\Providers\PermanentProviderException('DeepSeek suggestion request failed (HTTP 401).')
);
diag_assert(str_contains($described, 'PermanentProviderException'), 'The exception class must be reported.');
diag_assert(str_contains($described, 'HTTP 401'), 'The status code must survive, it is the diagnosis.');
diag_assert(! str_contains($described, 'CF7AIV\\'), 'The namespace is noise and must be trimmed.');

// A provider that quotes the key it was given must not put it on screen.
$leaky = DiagnosticsService::describeFailure(
	new RuntimeException('Auth failed for key sk-fbfcd8f5000f42bcb61a8ee32ce7821c on request')
);
diag_assert(! str_contains($leaky, 'sk-fbfcd8f5000f42bcb61a8ee32ce7821c'), 'An API key must never reach the screen.');
diag_assert(str_contains($leaky, '[REDACTED_KEY]'), 'The redaction must be visible, not silent.');

foreach ([
	'Bearer abcdef1234567890abcdef' => 'bearer tokens',
	'api_key=abcdef1234567890abcdef' => 'api_key assignments',
	'token: abcdef1234567890abcdef' => 'token assignments',
	'secret=hunter2000000000000000' => 'secret assignments',
] as $sample => $label) {
	$scrubbed = DiagnosticsService::describeFailure(new RuntimeException($sample));
	diag_assert(str_contains($scrubbed, '[REDACTED_KEY]'), "Credential form must be scrubbed: {$label}.");
}

// Long opaque runs are credential-shaped even without a recognisable prefix.
$opaque = DiagnosticsService::describeFailure(new RuntimeException('rejected AbCdEf0123456789AbCdEf0123456789xyz'));
diag_assert(str_contains($opaque, '[REDACTED_KEY]'), 'Long opaque tokens must be scrubbed.');

// Enquiry content must still be handled by the existing redactor.
$personal = DiagnosticsService::describeFailure(new RuntimeException('failed for resident@example.com'));
diag_assert(! str_contains($personal, 'resident@example.com'), 'Personal data must not reach the screen.');

// Bounded, so a provider cannot flood the notice area.
$long = DiagnosticsService::describeFailure(new RuntimeException(str_repeat('failure detail ', 200)));
diag_assert(strlen($long) <= 300, 'The description must be length-capped.');

// An exception with no message must still name its class rather than read blank.
$bare = DiagnosticsService::describeFailure(new RuntimeException(''));
diag_assert(str_contains($bare, 'RuntimeException'), 'A message-less failure must still be identifiable.');
diag_assert(! str_ends_with(trim($bare), ':'), 'A message-less failure must not end in a dangling separator.');

// The status must actually be attached at the provider, otherwise there is
// nothing for describeFailure() to report.
$source = file_get_contents(__DIR__ . '/../src/Providers/DeepSeekProvider.php');
diag_assert(is_string($source), 'Provider source must be readable.');
diag_assert(
	substr_count($source, "(HTTP ' . \$response->status") === 2,
	'Both the permanent and transient suggestion failures must name the HTTP status.'
);
diag_assert(
	str_contains($source, "request failed (HTTP ") && str_contains($source, "failed temporarily (HTTP "),
	'Permanent and transient failures must remain distinguishable as well as numbered.'
);

// The service must keep the reason rather than discarding the throwable.
$service = file_get_contents(__DIR__ . '/../src/Admin/SetupSuggestionService.php');
diag_assert(is_string($service), 'Service source must be readable.');
// Every path that reports a failure must also record why. A bare catch around
// a third-party do_action is legitimate and deliberately not covered here:
// nothing is reported to the administrator from there.
$reported = preg_match_all('/this->error = \'/', $service);
$explained = substr_count($service, 'this->diagnostic = DiagnosticsService::describeFailure');
diag_assert($reported > 0, 'The service must still report failures.');
diag_assert(
	$reported === $explained,
	"Every failure that sets a message must also record its reason ({$reported} reported, {$explained} explained)."
);
diag_assert(
	substr_count($service, 'this->diagnostic = null') === substr_count($service, 'this->error = null'),
	'The diagnostic must be reset wherever the error is, so a stale reason cannot persist.'
);
diag_assert(str_contains($service, 'lastDiagnostic'), 'The service must expose the diagnostic.');

// The reason must not travel in the URL, where it would be logged and kept.
$hooks = file_get_contents(__DIR__ . '/../src/Admin/AdminHooks.php');
diag_assert(is_string($hooks), 'AdminHooks source must be readable.');
diag_assert(str_contains($hooks, 'set_transient'), 'The diagnostic must use a transient.');
diag_assert(str_contains($hooks, 'delete_transient'), 'The diagnostic must be cleared after one read.');
diag_assert(! str_contains($hooks, 'suggestion_reason='), 'The reason must never be placed in the query string.');

echo "PASS FailureDiagnosticTest\n";
