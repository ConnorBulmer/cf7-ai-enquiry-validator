<?php
/** Standalone regressions for Task 4 review findings. */
declare(strict_types=1);

$failures = [];
function review_check(bool $condition, string $message): void { global $failures; if (! $condition) { $failures[] = $message; } }
$GLOBALS['cf7aiv_test_actions'] = []; $GLOBALS['cf7aiv_test_filters'] = []; $GLOBALS['review_options'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_filters'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function plugin_dir_url(string $file): string { return 'https://example.test/plugin/'; }
function as_enqueue_async_action(): int { return 1; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['review_options'][$key] ?? $default; }
define('ABSPATH', __DIR__ . '/'); define('ARRAY_A', 'ARRAY_A'); define('AUTH_KEY', 'review-auth'); define('SECURE_AUTH_KEY', 'review-secure'); define('CF7AIV_GA4_API_SECRET', 'ga-secret');
require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

$agencyPolicy = (new CF7AIV\Classification\PolicyPackRegistry())->get('agency');
$decisionEvidence = [
	new CF7AIV\Classification\EvidenceAssertion('service_fit', 'service_fit', 'message', 'website rebuild', true),
	new CF7AIV\Classification\EvidenceAssertion('commercial_intent', 'commercial_intent', 'message', 'need a website', true),
	new CF7AIV\Classification\EvidenceAssertion('specificity', 'specific_need', 'message', 'before October', true),
];
$reviewLowConfidence = new CF7AIV\Domain\ClassificationResult('website_rebuild', 'qualified', 0.01, 'Safe.', [], 'primary_opportunity', $decisionEvidence);
$reviewHighConfidence = new CF7AIV\Domain\ClassificationResult('website_rebuild', 'qualified', 0.99, 'Safe.', [], 'primary_opportunity', $decisionEvidence);
$reviewLowDecision = CF7AIV\Classification\EvidenceEvaluator::evaluate($reviewLowConfidence, $agencyPolicy ?? []);
$reviewHighDecision = CF7AIV\Classification\EvidenceEvaluator::evaluate($reviewHighConfidence, $agencyPolicy ?? []);
review_check($reviewLowDecision->state === 'strong_match' && $reviewLowDecision->state === $reviewHighDecision->state, 'Delivery-review regressions must remain independent of diagnostic model confidence.');

$mapping = CF7AIV\Delivery\DeliveryMapping::fromArray(['key' => 'ga', 'version' => '1', 'tier_id' => 'high_value_care', 'destination_key' => 'ga4', 'event_name' => 'qualify_lead', 'value' => 1, 'currency' => 'GBP', 'status' => 'published', 'enabled' => true]);
$item = ['id' => 91, 'tier_id' => 'high_value_care', 'created_at' => '2026-07-22T10:00:00Z', 'classified_at' => '2026-07-22T10:01:00Z'];
$consent = ['analytics_storage' => 'granted', 'ad_storage' => 'granted', 'ad_user_data' => 'granted', 'ad_personalization' => 'granted'];
$intakeEvent = CF7AIV\Delivery\EnvelopeBuilder::build($item, $mapping, ['ga_client_id' => '111.222', 'ga_session_id' => '1234567890', 'session_started_at' => '2026-07-22T09:59:00Z'], $consent);
review_check(($intakeEvent->attribution['client_id'] ?? null) === '111.222' && ($intakeEvent->attribution['session_id'] ?? null) === '1234567890', 'Envelope must map intake ga_client_id/ga_session_id to canonical GA keys.');
review_check(($intakeEvent->attribution['session_started_at'] ?? null) === '2026-07-22T09:59:00Z', 'Envelope must preserve session_started_at.');
$canonicalEvent = CF7AIV\Delivery\EnvelopeBuilder::build($item, $mapping, ['client_id' => '333.444', 'ga_client_id' => 'wrong', 'session_id' => '2345678901', 'ga_session_id' => 'wrong', 'msclkid' => '0123456789abcdef0123456789abcdef'], $consent);
review_check(($canonicalEvent->attribution['client_id'] ?? null) === '333.444' && ($canonicalEvent->attribution['session_id'] ?? null) === '2345678901', 'Canonical GA aliases must remain accepted and take precedence.');

$base = CF7AIV\Infrastructure\Settings::defaults();
$base['destinations']['ga4'] = ['enabled' => true, 'measurement_id' => 'G-TEST', 'live_enabled' => false, 'debug_validated' => false, 'dry_run' => false];
$GLOBALS['review_options'][CF7AIV\Infrastructure\Settings::OPTION] = $base;
review_check(! isset(CF7AIV\Delivery\DeliveryHooks::destinations([])['ga4']), 'Unvalidated GA debug must never register in the conversion worker.');
$base['destinations']['ga4']['dry_run'] = true;
$GLOBALS['review_options'][CF7AIV\Infrastructure\Settings::OPTION] = $base;
$dryDestination = CF7AIV\Delivery\DeliveryHooks::destinations([])['ga4'] ?? null;
$dryResult = $dryDestination instanceof CF7AIV\Contracts\DestinationInterface ? $dryDestination->send($canonicalEvent) : null;
review_check($dryResult?->dryRun === true && str_contains((string) ($dryResult->payload['url'] ?? ''), '/mp/collect?') && ! str_contains((string) ($dryResult->payload['url'] ?? ''), '/debug/'), 'GA dry-run may register, but it must build a non-debug payload without claiming a live debug delivery.');
$base['destinations']['ga4'] = ['enabled' => true, 'measurement_id' => 'G-TEST', 'live_enabled' => true, 'debug_validated' => true, 'dry_run' => false];
$GLOBALS['review_options'][CF7AIV\Infrastructure\Settings::OPTION] = $base;
review_check(isset(CF7AIV\Delivery\DeliveryHooks::destinations([])['ga4']), 'GA may register live only after live enablement and debug validation.');

$schema = CF7AIV\Infrastructure\Schema::definitions('wp_')['wp_cf7aiv_deliveries'];
review_check(str_contains($schema, 'lease_expires_at') && str_contains($schema, 'lease_token'), 'Delivery schema must persist lease ownership and expiry.');
review_check((int) CF7AIV\Infrastructure\Migration::VERSION >= 5, 'Atomic lease schema must remain at migration version 5 or later.');

final class ReviewLeaseWpdb {
	public string $prefix = 'wp_'; public int $rows_affected = 0; public int $insert_id = 0; public ?array $row = null; public array $queries = [];
	public function prepare(string $sql, mixed ...$args): string { foreach ($args as $arg) { $replace = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'"; $sql = preg_replace('/%[ds]/', $replace, $sql, 1) ?? $sql; } return $sql; }
	public function query(string $sql): int { $this->queries[] = $sql; $this->rows_affected = 0;
		if (str_starts_with($sql, 'INSERT IGNORE') && $this->row === null) { $this->row = ['id' => 1, 'state' => 'pending', 'attempts' => 0, 'lease_token' => null, 'lease_expires_at' => null]; $this->rows_affected = 1; }
		if (str_contains($sql, "SET state='sending'")) { $eligible = in_array($this->row['state'] ?? '', ['pending', 'retrying', 'dry_run'], true) || (($this->row['state'] ?? '') === 'sending' && ($this->row['lease_expires_at'] ?? 0) < time()); if ($eligible) { preg_match("/lease_token='([^']+)'/", $sql, $match); $this->row['state'] = 'sending'; $this->row['lease_token'] = $match[1] ?? ''; $this->row['lease_expires_at'] = time() + 120; $this->rows_affected = 1; } }
		if (preg_match("/SET state='(dry_run|delivered|retrying|failed)'/", $sql, $match) && ($this->row['state'] ?? '') === 'sending') { $this->row['state'] = $match[1]; $this->row['attempts']++; $this->row['lease_token'] = null; $this->row['lease_expires_at'] = null; $this->rows_affected = 1; }
		return $this->rows_affected;
	}
	public function get_row(string $sql, mixed $format = null): ?array { return $this->row; }
	public function get_var(string $sql): mixed { return $this->row['attempts'] ?? 0; }
}
$GLOBALS['wpdb'] = new ReviewLeaseWpdb(); $leaseStore = new CF7AIV\Delivery\WpDeliveryStore();
$first = $leaseStore->claim(91, $mapping, $canonicalEvent); $second = $leaseStore->claim(91, $mapping, $canonicalEvent);
review_check(($first['acquired'] ?? false) === true && ($second['acquired'] ?? true) === false, 'Only the first concurrent claim may acquire the delivery lease.');
$leaseStore->record(1, new CF7AIV\Domain\DeliveryResult(false, false, null, null, [], [], true), (string) ($first['lease_token'] ?? ''));
$dryReclaim = $leaseStore->claim(91, $mapping, $canonicalEvent);
review_check(($dryReclaim['acquired'] ?? false) === true, 'Dry-run records must be explicitly reacquirable.');
$leaseStore->record(1, new CF7AIV\Domain\DeliveryResult(true), (string) ($dryReclaim['lease_token'] ?? ''));
$afterDelivered = $leaseStore->claim(91, $mapping, $canonicalEvent);
review_check(($afterDelivered['acquired'] ?? true) === false, 'Delivered records must never reacquire a lease.');

final class ReviewTransport implements CF7AIV\Infrastructure\HttpTransportInterface { public array $requests = []; public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse { $this->requests[] = compact('url', 'arguments'); return new CF7AIV\Infrastructure\HttpResponse(204, ''); } }
$msTransport = new ReviewTransport(); (new CF7AIV\Destinations\MicrosoftUetDestination($msTransport, '123', 'token'))->send($canonicalEvent);
$msBody = json_decode($msTransport->requests[0]['arguments']['body'], true, 32, JSON_THROW_ON_ERROR);
$msEvent = $msBody['data'][0] ?? [];
review_check(is_int($msEvent['eventTime'] ?? null) && $msEvent['eventTime'] === strtotime($canonicalEvent->occurredAt), 'Microsoft eventTime must be an integer Unix timestamp in seconds.');
review_check(($msEvent['eventId'] ?? '') === $canonicalEvent->eventId && ($msEvent['customData']['transactionId'] ?? '') === $canonicalEvent->eventId, 'Microsoft retries must retain one canonical event and transaction identity.');

if ($failures !== []) { throw new RuntimeException(implode("\n", $failures)); }
echo "PASS DeliveryReviewRegressionTest\n";
