<?php
declare(strict_types=1);

define('CF7AIV\\DIR', dirname(__DIR__) . '/');

spl_autoload_register(static function (string $class): void {
	$prefix = 'CF7AIV\\';
	if (! str_starts_with($class, $prefix)) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($path)) { require_once $path; }
});

$GLOBALS['options'] = [];
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, mixed $value, bool $autoload = true): bool { $GLOBALS['options'][$name] = $value; return true; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }

function log_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

use CF7AIV\Admin\EventLog;

EventLog::clear();
EventLog::record('ga4_validation', 'error', 'Validation failed. GA4 rejected the event.');
$recent = EventLog::recent();
log_assert(count($recent) === 1, 'An event must be recorded.');
log_assert($recent[0]['channel'] === 'ga4_validation', 'The area must be preserved.');
log_assert($recent[0]['level'] === 'error', 'The level must be preserved.');
log_assert(str_contains($recent[0]['message'], 'GA4 rejected'), 'The message must be preserved.');
log_assert(preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) $recent[0]['at']) === 1, 'Each event must be timestamped.');

// Newest first: an operator opens this log to find what just happened.
EventLog::record('delivery', 'info', 'Delivery gate: attribution_not_ready');
log_assert(EventLog::recent()[0]['channel'] === 'delivery', 'The newest event must come first.');

// Everything here is displayed, so it is scrubbed on the way in rather than out.
EventLog::clear();
EventLog::record('provider', 'error', 'auth failed api_key=abcdef1234567890 for resident@example.com');
$scrubbed = EventLog::recent()[0]['message'];
log_assert(! str_contains($scrubbed, 'abcdef1234567890'), 'Credentials must never be stored in the log.');
log_assert(! str_contains($scrubbed, 'resident@example.com'), 'Personal data must never be stored in the log.');

// A log that grows without bound becomes a performance problem of its own.
EventLog::clear();
for ($i = 0; $i < 260; $i++) { EventLog::record('provider', 'info', 'event ' . $i); }
$stored = $GLOBALS['options'][EventLog::OPTION];
log_assert(count($stored) <= 200, 'The log must be capped.');
log_assert(str_contains((string) end($stored)['message'], 'event 259'), 'The cap must discard the oldest, not the newest.');

// Bounded per entry, too.
EventLog::clear();
EventLog::record('provider', 'error', str_repeat('detail ', 400));
log_assert(strlen(EventLog::recent()[0]['message']) <= 500, 'A single message must be length-capped.');

// Unknown levels and odd area names must not produce broken markup downstream.
EventLog::clear();
EventLog::record('Weird Area!!', 'catastrophic', 'something');
$entry = EventLog::recent()[0];
log_assert($entry['level'] === 'info', 'An unknown level must fall back rather than render raw.');
log_assert(preg_match('/^[a-z0-9_\-]*$/', (string) $entry['channel']) === 1, 'The area must be reduced to a safe code.');

// Context is for identifiers, not payloads.
EventLog::clear();
EventLog::record('ga4_validation', 'info', 'queued', ['measurement_id' => 'G-ABC123', 'nested' => ['no'], 'ok' => 1]);
$context = EventLog::recent()[0]['context'];
log_assert(($context['measurement_id'] ?? '') === 'G-ABC123', 'Scalar context must be kept.');
log_assert(! array_key_exists('nested', $context), 'Non-scalar context must be dropped.');

// The log must never break the thing it observes.
EventLog::clear();
EventLog::recordFailure('ga4_validation', new RuntimeException('boom'));
log_assert(str_contains(EventLog::recent()[0]['message'], 'RuntimeException'), 'A failure must record its class.');

echo "PASS EventLogTest\n";
