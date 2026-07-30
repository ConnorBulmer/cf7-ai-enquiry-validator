<?php
/**
 * Standalone conversion delivery tests. Run: php tests/DeliveryTest.php
 */

declare(strict_types=1);

function cf7aiv_delivery_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$GLOBALS['cf7aiv_test_actions'] = [];
$GLOBALS['cf7aiv_test_filters'] = [];
$GLOBALS['cf7aiv_fired_actions'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_filters'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function do_action(string $hook, mixed ...$arguments): void { $GLOBALS['cf7aiv_fired_actions'][] = [$hook, $arguments]; }
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function as_enqueue_async_action(): int { return 1; }

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('AUTH_KEY', 'delivery-test-auth-key');
define('SECURE_AUTH_KEY', 'delivery-test-secure-auth-key');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

cf7aiv_delivery_assert(class_exists(CF7AIV\Delivery\EnvelopeBuilder::class), 'Delivery envelope builder must exist.');

final class CF7AIVDeliveryTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	public array $requests = [];
	public function __construct(public array $responses = []) {}
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->requests[] = compact('url', 'arguments');
		$response = array_shift($this->responses);
		if ($response instanceof Throwable) { throw $response; }
		return $response instanceof CF7AIV\Infrastructure\HttpResponse ? $response : new CF7AIV\Infrastructure\HttpResponse(204, '');
	}
}

$mapping = CF7AIV\Delivery\DeliveryMapping::fromArray([
	'key' => 'high-ga4-v2', 'version' => '2', 'tier_id' => 'high_value_care',
	'destination_key' => 'ga4', 'event_name' => 'qualify_lead', 'value' => 125.50,
	'currency' => 'GBP', 'status' => 'published', 'enabled' => true,
]);
cf7aiv_delivery_assert($mapping->isPublished() && $mapping->currency === 'GBP', 'Independent mappings must retain a published version, event, value, and ISO currency.');
$badCurrency = false;
try { CF7AIV\Delivery\DeliveryMapping::fromArray(['key' => 'x', 'version' => '1', 'tier_id' => 'x', 'destination_key' => 'ga4', 'event_name' => 'x', 'value' => 1, 'currency' => 'gbp', 'status' => 'published', 'enabled' => true]); } catch (InvalidArgumentException) { $badCurrency = true; }
cf7aiv_delivery_assert($badCurrency, 'Mappings must reject invalid ISO-4217 currency syntax.');

$item = [
	'id' => 42, 'tier_id' => 'high_value_care',
	'decision_state' => 'strong_match', 'verification_state' => 'off',
	'is_shadow' => 0, 'is_calibration' => 0, 'is_historical' => 0,
	'created_at' => '2026-07-22T10:00:00+00:00', 'classified_at' => '2026-07-22T10:05:00+00:00',
	'form_id' => 17, 'source_type' => 'flamingo',
	'enquiry_text' => 'PRIVATE enquiry text', 'name' => 'Ada Private', 'email' => 'ada@example.test',
	'email_hash' => hash('sha256', 'ada@example.test'), 'phone_hash' => hash('sha256', '+447700900123'),
];
$attribution = ['client_id' => '123.456', 'session_id' => '1721640000', 'session_started_at' => '2026-07-22T09:30:00+00:00', 'msclkid' => 'safe-click-id', 'gclid' => 'safe-google-click'];
$consent = ['analytics_storage' => 'granted', 'ad_storage' => 'denied', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied'];
$event = CF7AIV\Delivery\EnvelopeBuilder::build($item, $mapping, $attribution, $consent, true);
$envelope = $event->toArray();
$serialized = json_encode($envelope, JSON_THROW_ON_ERROR);
cf7aiv_delivery_assert($event->eventId === CF7AIV\Delivery\EnvelopeBuilder::build($item, $mapping, $attribution, $consent, true)->eventId, 'Event IDs must be stable for item + mapping version + event.');
cf7aiv_delivery_assert($event->idempotencyKey === hash('sha256', '42|high-ga4-v2|2|qualify_lead'), 'Envelope must expose the deterministic idempotency key.');
foreach (['PRIVATE enquiry text', 'Ada Private', 'ada@example.test'] as $forbidden) { cf7aiv_delivery_assert(! str_contains($serialized, $forbidden), 'Envelope must never contain enquiry text or raw identity.'); }
cf7aiv_delivery_assert($envelope['context'] === ['form_id' => 17, 'source_type' => 'flamingo'] && $envelope['consent'] === $consent, 'Envelope must retain only non-identifying context and actual consent.');
cf7aiv_delivery_assert($envelope['user_data']['email_sha256'] === $item['email_hash'], 'Already-hashed identity may be included only when separately requested.');
cf7aiv_delivery_assert(CF7AIV\Delivery\EnvelopeBuilder::build($item, $mapping, $attribution, $consent, false)->userData === [], 'Enhanced hashes must remain off by default.');

$respectDenied = CF7AIV\Delivery\ConsentPolicy::evaluate('respect', $consent);
cf7aiv_delivery_assert(! $respectDenied->permitted && $respectDenied->actual['ad_storage'] === 'denied', 'Respect mode must block denied/unknown applicable consent.');
$always = CF7AIV\Delivery\ConsentPolicy::evaluate('always_actual', $consent);
cf7aiv_delivery_assert($always->permitted && $always->actual['ad_storage'] === 'denied', 'Always-actual may send but must preserve restrictive actual consent.');
$invalidOverride = false;
try { CF7AIV\Delivery\ConsentPolicy::evaluate('legal_basis_override', $consent, ['administrator_id' => 7, 'date' => '', 'reason' => '']); } catch (InvalidArgumentException) { $invalidOverride = true; }
cf7aiv_delivery_assert($invalidOverride, 'Legal-basis override must require administrator ID, date, and a non-empty reason.');
$override = CF7AIV\Delivery\ConsentPolicy::evaluate('legal_basis_override', $consent, ['administrator_id' => 7, 'date' => '2026-07-22T10:06:00Z', 'reason' => 'Documented statutory basis']);
cf7aiv_delivery_assert($override->permitted && $override->actual['ad_user_data'] === 'denied' && $override->audit['administrator_id'] === 7, 'Legal-basis override must preserve actual consent and its audit record.');

$gaLiveTransport = new CF7AIVDeliveryTransport([new CF7AIV\Infrastructure\HttpResponse(204, '')]);
$ga = new CF7AIV\Destinations\GA4Destination($gaLiveTransport, 'G-TEST', 'ga-secret', false, false, false, strtotime('2026-07-22T10:10:00Z'));
$gaResult = $ga->send($event);
$gaCall = $gaLiveTransport->requests[0];
$gaBody = json_decode($gaCall['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
cf7aiv_delivery_assert($gaCall['url'] === 'https://www.google-analytics.com/mp/collect?measurement_id=G-TEST&api_secret=ga-secret', 'GA4 live must use the Measurement Protocol collect endpoint.');
cf7aiv_delivery_assert($gaBody['client_id'] === '123.456' && $gaBody['events'][0]['name'] === 'qualify_lead' && $gaBody['events'][0]['params']['event_id'] === $event->eventId, 'GA4 must include client/session attribution and stable event ID.');
cf7aiv_delivery_assert($gaBody['events'][0]['params']['engagement_time_msec'] === 1 && ! isset($gaBody['validation_behavior']), 'GA4 live collection must include Realtime engagement context and must not enable strict development validation.');
cf7aiv_delivery_assert($gaBody['consent']['ad_user_data'] === 'DENIED' && ! isset($gaBody['user_data']), 'GA4 must send actual consent and keep enhanced data independently off.');
cf7aiv_delivery_assert($gaResult->successful && $gaResult->warnings === [], 'Fresh GA4 session delivery must return success without timing warnings.');

$gaDebugTransport = new CF7AIVDeliveryTransport([new CF7AIV\Infrastructure\HttpResponse(200, '{"validationMessages":[]}')]);
$grantedConsent = array_fill_keys(array_keys($consent), 'granted');
$grantedEvent = CF7AIV\Delivery\EnvelopeBuilder::build($item, $mapping, $attribution, $grantedConsent, true);
$debugResult = (new CF7AIV\Destinations\GA4Destination($gaDebugTransport, 'G-TEST', 'ga-secret', true, true, false, strtotime('2026-07-22T10:10:00Z')))->send($grantedEvent);
$debugBody = json_decode($gaDebugTransport->requests[0]['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
cf7aiv_delivery_assert(str_starts_with($gaDebugTransport->requests[0]['url'], 'https://www.google-analytics.com/debug/mp/collect?') && isset($debugBody['user_data']['sha256_email_address']), 'GA4 debug validation must use the debug endpoint and separately enabled hashes.');
cf7aiv_delivery_assert(($debugBody['validation_behavior'] ?? '') === 'ENFORCE_RECOMMENDATIONS' && $debugBody['events'][0]['params']['engagement_time_msec'] === 1, 'GA4 debug validation must enforce Google recommendations and remain observable in Realtime.');
cf7aiv_delivery_assert($debugResult->successful, 'GA4 debug response with no validation messages must pass before live enablement.');

$oldItem = $item; $oldItem['created_at'] = '2026-07-18T10:00:00+00:00';
$oldAttribution = $attribution; $oldAttribution['session_started_at'] = '2026-07-18T09:30:00+00:00';
$oldEvent = CF7AIV\Delivery\EnvelopeBuilder::build($oldItem, $mapping, $oldAttribution, $consent, false);
$oldGaTransport = new CF7AIVDeliveryTransport([new CF7AIV\Infrastructure\HttpResponse(204, '')]);
$oldResult = (new CF7AIV\Destinations\GA4Destination($oldGaTransport, 'G-TEST', 'secret', false, false, false, strtotime('2026-07-22T10:10:00Z')))->send($oldEvent);
$oldBody = json_decode($oldGaTransport->requests[0]['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
cf7aiv_delivery_assert(count($oldResult->warnings) === 2 && ! isset($oldBody['timestamp_micros']), 'GA4 must warn beyond 24h session attribution and omit timestamps beyond the 72h Ads-export limit.');

$serverGtmTransport = new CF7AIVDeliveryTransport([new CF7AIV\Infrastructure\HttpResponse(204, '')]);
$serverGtm = new CF7AIV\Destinations\ServerGtmDestination(
	$serverGtmTransport,
	'https://metrics.example.test/',
	'G-TEST',
	'sgtm-secret',
	true,
	false,
	strtotime('2026-07-22T10:10:00Z')
);
$serverGtmResult = $serverGtm->send($grantedEvent);
$serverGtmCall = $serverGtmTransport->requests[0];
$serverGtmBody = json_decode($serverGtmCall['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
$serverGtmParams = $serverGtmBody['events'][0]['params'];
cf7aiv_delivery_assert($serverGtm->key() === 'server_gtm', 'Existing-sGTM delivery must expose a stable destination key for independent tier mappings.');
cf7aiv_delivery_assert($serverGtmCall['url'] === 'https://metrics.example.test/mp/collect?measurement_id=G-TEST&api_secret=sgtm-secret', 'Existing-sGTM delivery must send GA4 Measurement Protocol to the configured tagging server rather than Google directly.');
cf7aiv_delivery_assert(
	$serverGtmBody['client_id'] === '123.456'
	&& $serverGtmParams['session_id'] === '1721640000'
	&& $serverGtmBody['events'][0]['name'] === 'qualify_lead'
	&& $serverGtmParams['event_id'] === $grantedEvent->eventId
	&& $serverGtmParams['transaction_id'] === $grantedEvent->eventId
	&& $serverGtmParams['value'] === 125.5
	&& $serverGtmParams['currency'] === 'GBP',
	'Existing-sGTM delivery must provide the client/session context, native event fields, and a stable transaction ID required for deduplication.'
);
cf7aiv_delivery_assert(
	$serverGtmParams['gclid'] === 'safe-google-click'
	&& ! isset($serverGtmParams['msclkid'])
	&& $serverGtmBody['consent']['ad_user_data'] === 'GRANTED'
	&& isset($serverGtmBody['user_data']['sha256_email_address'], $serverGtmBody['user_data']['sha256_phone_number']),
	'Existing-sGTM delivery must expose Google click identifiers and actual consent while keeping Microsoft attribution out and enhanced matching independently consent-gated.'
);
cf7aiv_delivery_assert($serverGtmResult->successful && $serverGtmResult->warnings === [], 'A fresh accepted sGTM request must report success without timing warnings.');

$serverGtmDryTransport = new CF7AIVDeliveryTransport();
$serverGtmDry = (new CF7AIV\Destinations\ServerGtmDestination(
	$serverGtmDryTransport,
	'https://metrics.example.test',
	'G-TEST',
	'sgtm-super-secret',
	false,
	true,
	strtotime('2026-07-22T10:10:00Z')
))->send($event);
cf7aiv_delivery_assert(
	$serverGtmDry->dryRun
	&& ! $serverGtmDry->successful
	&& $serverGtmDryTransport->requests === []
	&& ! str_contains(json_encode($serverGtmDry->payload, JSON_THROW_ON_ERROR), 'sgtm-super-secret'),
	'Existing-sGTM dry-run must perform no network request and must redact its API secret.'
);
$insecureServerGtmRejected = false;
try {
	(new CF7AIV\Destinations\ServerGtmDestination(new CF7AIVDeliveryTransport(), 'http://metrics.example.test', 'G-TEST', 'secret'))->send($event);
} catch (InvalidArgumentException) {
	$insecureServerGtmRejected = true;
}
cf7aiv_delivery_assert($insecureServerGtmRejected, 'Existing-sGTM delivery must enforce HTTPS.');

$msTransport = new CF7AIVDeliveryTransport([new CF7AIV\Infrastructure\HttpResponse(200, '{"eventId":"remote-1"}')]);
$msResult = (new CF7AIV\Destinations\MicrosoftUetDestination($msTransport, '12345', 'uet-token', true, false))->send($event);
$msCall = $msTransport->requests[0]; $msBody = json_decode($msCall['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
cf7aiv_delivery_assert($msCall['url'] === 'https://capi.uet.microsoft.com/v1/12345/events' && $msCall['arguments']['headers']['Authorization'] === 'Bearer uet-token', 'Microsoft UET CAPI must use the tag endpoint and bearer authorization.');
cf7aiv_delivery_assert($msBody['eventType'] === 'custom' && $msBody['eventId'] === $event->eventId && $msBody['userData']['msclkid'] === 'safe-click-id', 'Microsoft event must include stable ID, custom event, and click identifier.');
cf7aiv_delivery_assert($msBody['adStorageConsent'] === 'D' && ! isset($msBody['userData']['em'], $msBody['userData']['ph']), 'Microsoft must transmit actual denied consent and suppress hashes without user-data consent.');
cf7aiv_delivery_assert($msResult->remoteId === 'remote-1', 'Microsoft response IDs must be retained.');
$msGrantedTransport = new CF7AIVDeliveryTransport([new CF7AIV\Infrastructure\HttpResponse(204, '')]);
(new CF7AIV\Destinations\MicrosoftUetDestination($msGrantedTransport, '12345', 'uet-token', true, false))->send($grantedEvent);
$msGrantedBody = json_decode($msGrantedTransport->requests[0]['arguments']['body'], true, 64, JSON_THROW_ON_ERROR);
cf7aiv_delivery_assert(isset($msGrantedBody['userData']['em'], $msGrantedBody['userData']['ph']), 'Microsoft enhanced hashes require both separate enablement and granted user-data consent.');

$webhookTransport = new CF7AIVDeliveryTransport([new CF7AIV\Infrastructure\HttpResponse(204, '')]);
$webhook = new CF7AIV\Destinations\WebhookDestination($webhookTransport, 'https://hooks.example.test/conversion', 'webhook-secret', false, 1721643000);
$webhookResult = $webhook->send($event); $webhookCall = $webhookTransport->requests[0];
$expectedSignature = 'sha256=' . hash_hmac('sha256', '1721643000.' . $webhookCall['arguments']['body'], 'webhook-secret');
cf7aiv_delivery_assert($webhookCall['arguments']['headers']['X-CF7AIV-Event-ID'] === $event->eventId && $webhookCall['arguments']['headers']['X-CF7AIV-Timestamp'] === '1721643000' && $webhookCall['arguments']['headers']['X-CF7AIV-Signature'] === $expectedSignature, 'Webhook must HMAC-sign canonical timestamp + body for replay verification.');
cf7aiv_delivery_assert($webhookResult->successful, 'A 204 webhook response must succeed.');
$insecureRejected = false;
try { (new CF7AIV\Destinations\WebhookDestination(new CF7AIVDeliveryTransport(), 'http://hooks.example.test', 'secret'))->send($event); } catch (InvalidArgumentException) { $insecureRejected = true; }
cf7aiv_delivery_assert($insecureRejected, 'Webhook must enforce HTTPS by default.');

$dryTransport = new CF7AIVDeliveryTransport();
$dry = (new CF7AIV\Destinations\GA4Destination($dryTransport, 'G-TEST', 'super-secret', false, false, true, strtotime('2026-07-22T10:10:00Z')))->send($event);
cf7aiv_delivery_assert($dry->dryRun && ! $dry->successful && $dryTransport->requests === [] && ! str_contains(json_encode($dry->payload, JSON_THROW_ON_ERROR), 'super-secret'), 'Dry-run must return exact sanitized payload without network and never report delivered.');

foreach ([200 => [true, false], 204 => [true, false], 400 => [false, false], 401 => [false, false], 403 => [false, false], 408 => [false, true], 425 => [false, true], 429 => [false, true], 500 => [false, true], 503 => [false, true]] as $status => $expected) {
	$result = CF7AIV\Delivery\ResponseClassifier::classify(new CF7AIV\Infrastructure\HttpResponse($status, '{"error":"token=secret raw@example.test"}'));
	cf7aiv_delivery_assert([$result->successful, $result->retryable] === $expected, "HTTP {$status} must have the correct delivery retry classification.");
	cf7aiv_delivery_assert(! str_contains((string) $result->error, 'secret') && ! str_contains((string) $result->error, 'raw@example.test'), 'Recorded response errors must be sanitized.');
}
$timeout = CF7AIV\Delivery\ResponseClassifier::transportFailure(new RuntimeException('Connection timed out with token=secret and raw@example.test'));
cf7aiv_delivery_assert($timeout->retryable && $timeout->error === 'Destination request timed out.', 'Timeouts must be transient and sanitize exception details.');
cf7aiv_delivery_assert(CF7AIV\Delivery\RetryPolicy::delay(1) === 60 && CF7AIV\Delivery\RetryPolicy::delay(4) === 480 && CF7AIV\Delivery\RetryPolicy::delay(20) === 3600, 'Retries must use bounded exponential delays.');

final class CF7AIVDeliveryMemoryStore implements CF7AIV\Delivery\DeliveryStoreInterface {
	public array $records = []; public array $attempts = []; public array $paused = [];
	public function claim(int $itemId, CF7AIV\Delivery\DeliveryMapping $mapping, CF7AIV\Domain\ConversionEvent $event): array {
		$key = $event->idempotencyKey;
		if (! isset($this->records[$key])) { $this->records[$key] = ['id' => count($this->records) + 1, 'state' => 'pending', 'attempts' => 0, 'destination_key' => $mapping->destinationKey]; }
		$acquired = in_array($this->records[$key]['state'], ['pending', 'retrying', 'dry_run'], true);
		if ($acquired) { $this->records[$key]['state'] = 'sending'; $this->records[$key]['lease_token'] = 'memory-lease-' . $this->records[$key]['id']; }
		return $this->records[$key] + ['acquired' => $acquired];
	}
	public function record(int $deliveryId, CF7AIV\Domain\DeliveryResult $result, string $leaseToken = ''): int {
		foreach ($this->records as &$record) { if ($record['id'] === $deliveryId) { $record['attempts']++; $record['state'] = $result->dryRun ? 'dry_run' : ($result->successful ? 'delivered' : ($result->retryable ? 'retrying' : 'failed')); $this->attempts[] = [$deliveryId, $result]; return $record['attempts']; } }
		return 0;
	}
	public function pause(string $destinationKey, string $sanitizedReason): void { $this->paused[$destinationKey] = $sanitizedReason; }
	public function permanentFailures(string $destinationKey): int { return count(array_filter($this->attempts, static fn (array $attempt): bool => ! $attempt[1]->successful && ! $attempt[1]->retryable && ! $attempt[1]->dryRun)); }
	public function isPaused(string $destinationKey): bool { return isset($this->paused[$destinationKey]); }
}
final class CF7AIVCountingDestination implements CF7AIV\Contracts\DestinationInterface {
	public int $calls = 0;
	public ?CF7AIV\Domain\ConversionEvent $lastEvent = null;
	public function __construct(private readonly string $destinationKey, private readonly CF7AIV\Domain\DeliveryResult $result) {}
	public function key(): string { return $this->destinationKey; }
	public function send(CF7AIV\Domain\ConversionEvent $event): CF7AIV\Domain\DeliveryResult { $this->calls++; $this->lastEvent = $event; return $this->result; }
}
final class CF7AIVApprovalAuditWpdb {
	public string $prefix = 'wp_';
	public array $queries = [];
	/** @param array<string,mixed> $audit */
	public function __construct(private readonly array $audit) {}
	public function prepare(string $sql, mixed ...$args): string {
		foreach ($args as $arg) {
			$replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
			$sql = preg_replace('/%[ds]/', $replacement, $sql, 1) ?? $sql;
		}
		return $sql;
	}
	public function get_var(string $sql): mixed {
		$this->queries[] = $sql;
		foreach ([
			"dispatch_approval_id='" . $this->audit['dispatch_approval_id'] . "'",
			'item_id=' . $this->audit['item_id'],
			'administrator_id=' . $this->audit['administrator_id'],
			"action='approve_dispatch'",
			"corrected_tier='" . $this->audit['corrected_tier'] . "'",
			"reviewed_outcome_id='" . $this->audit['reviewed_outcome_id'] . "'",
			"status='accepted'",
		] as $required) {
			if (! str_contains($sql, $required)) { return null; }
		}
		return $this->audit['dispatch_approval_id'];
	}
}

$gaConfig = ['destinations' => ['ga4' => ['enabled' => true, 'dry_run' => true, 'measurement_id' => 'G-TEST']], 'consent' => ['mode' => 'always_actual'], 'privacy' => ['enhanced_matching' => false]];
$testCredentials = static fn (string $name): string => 'test-credential-for-' . $name;
$store = new CF7AIVDeliveryMemoryStore(); $queued = [];
$successDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true, false, 'remote'));
$worker = new CF7AIV\Delivery\DeliveryWorker(
	$store,
	static fn (int $id): ?array => $id === 42 ? $item : null,
	static fn (string $tier): array => [$mapping],
	static fn (): array => ['ga4' => $successDestination],
	static fn (): array => $gaConfig,
	static fn (array $row): array => [$attribution, $consent],
	static function (string $hook, array $args, int $delay) use (&$queued): void { $queued[] = compact('hook', 'args', 'delay'); },
	$testCredentials,
);
$worker->process(42); $worker->process(42);
cf7aiv_delivery_assert($successDestination->calls === 1 && count($store->records) === 1, 'Repeated workers must return existing delivered records and never upload twice.');
cf7aiv_delivery_assert(in_array(CF7AIV\Hooks::AFTER_DELIVERY, array_column($GLOBALS['cf7aiv_fired_actions'], 0), true), 'Worker must fire cf7aiv_after_delivery after a recorded attempt.');
$blockedStore = new CF7AIVDeliveryMemoryStore(); $blockedDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
$blockedWorker = new CF7AIV\Delivery\DeliveryWorker($blockedStore, static fn (): array => array_merge($item, ['is_shadow' => 1]), static fn (): array => [$mapping], static fn (): array => ['ga4' => $blockedDestination], static fn (): array => $gaConfig, static fn (): array => [$attribution, $consent]);
$blockedWorker->process(42);
cf7aiv_delivery_assert($blockedDestination->calls === 0 && $blockedStore->records === [], 'Delivery worker must independently refuse shadow-origin items even when directly invoked by a crafted or stale queue action.');

$directStore = new CF7AIVDeliveryMemoryStore();
$directDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
$workerFor = static function (array $candidate, array $automation = [], ?callable $manualApproval = null) use ($directStore, $mapping, $directDestination, $gaConfig, $attribution, $consent, $testCredentials): CF7AIV\Delivery\DeliveryWorker {
	return new CF7AIV\Delivery\DeliveryWorker(
		$directStore,
		static fn (): array => $candidate,
		static fn (): array => [$mapping],
		static fn (): array => ['ga4' => $directDestination],
		static fn (): array => array_replace_recursive($gaConfig, ['automation' => $automation]),
		static fn (): array => [$attribution, $consent],
		null,
		$testCredentials,
		$manualApproval,
	);
};
foreach (['needs_review', 'not_eligible', '', null] as $state) {
	$workerFor(array_merge($item, ['decision_state' => $state]))->process(42);
}
cf7aiv_delivery_assert($directDestination->calls === 0 && $directStore->records === [], 'Delivery worker must independently reject every non-Strong state.');
$workerFor(array_merge($item, [
	'decision_state' => 'strong_match',
	'verification_state' => 'disagreed',
]), ['verification_mode' => 'automatic_candidates'])->process(42);
cf7aiv_delivery_assert($directDestination->calls === 0 && $directStore->records === [], 'Configured second-pass disagreement must block direct delivery.');

$forgedManual = array_merge($item, [
	'decision_state' => 'needs_review',
	'review_state' => 'approved_dispatch',
	'outcome_id' => 'primary_opportunity',
	'reviewed_tier_id' => 'high_value_care',
	'reviewed_outcome_id' => 'primary_opportunity',
	'reviewed_by' => 77,
	'reviewed_at' => '2026-07-22 12:00:00',
	'dispatch_approval_id' => str_repeat('b', 64),
]);
$workerFor($forgedManual)->process(42);
cf7aiv_delivery_assert($directDestination->calls === 0 && $directStore->records === [], 'Manual delivery fields without the separately persisted review audit must not forge dispatch eligibility.');
$workerFor($forgedManual, [], static fn (array $candidate): bool => (int) ($candidate['reviewed_by'] ?? 0) === 77)->process(42);
cf7aiv_delivery_assert($directDestination->calls === 1 && count($directStore->records) === 1, 'An audited administrator approve-and-dispatch may deliver a live Needs review item.');

$manualAudit = [
	'dispatch_approval_id' => $forgedManual['dispatch_approval_id'],
	'item_id' => 42,
	'administrator_id' => 77,
	'corrected_tier' => 'high_value_care',
	'reviewed_outcome_id' => 'primary_opportunity',
];
$GLOBALS['wpdb'] = new CF7AIVApprovalAuditWpdb($manualAudit);
$manualRetryStore = new CF7AIVDeliveryMemoryStore(); $manualRetryQueue = [];
$manualRetryDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(false, true, null, 'Destination returned HTTP 429.'));
$manualRetryWorker = new CF7AIV\Delivery\DeliveryWorker(
	$manualRetryStore,
	static fn (): array => $forgedManual,
	static fn (): array => [$mapping],
	static fn (): array => ['ga4' => $manualRetryDestination],
	static fn (): array => $gaConfig,
	static fn (): array => [$attribution, $consent],
	static function (string $hook, array $args, int $delay) use (&$manualRetryQueue): void { $manualRetryQueue[] = compact('hook', 'args', 'delay'); },
	$testCredentials,
);
$manualRetryWorker->process(42);
$manualRetryWorker->process(42);
cf7aiv_delivery_assert($manualRetryDestination->calls === 2 && count($manualRetryQueue) === 2, 'The exact immutable approval audit must remain valid for a claimed delivery retry.');
cf7aiv_delivery_assert(count($GLOBALS['wpdb']->queries) === 2 && str_contains($GLOBALS['wpdb']->queries[0], 'dispatch_approval_id'), 'Every manual delivery attempt must verify the exact approval ID, item, action, administrator, tier, and outcome.');
$mismatchedApprovalStore = new CF7AIVDeliveryMemoryStore();
$mismatchedApprovalDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
(new CF7AIV\Delivery\DeliveryWorker(
	$mismatchedApprovalStore,
	static fn (): array => array_merge($forgedManual, ['dispatch_approval_id' => str_repeat('c', 64)]),
	static fn (): array => [$mapping],
	static fn (): array => ['ga4' => $mismatchedApprovalDestination],
	static fn (): array => $gaConfig,
	static fn (): array => [$attribution, $consent],
	null,
	$testCredentials,
))->process(42);
cf7aiv_delivery_assert($mismatchedApprovalDestination->calls === 0 && $mismatchedApprovalStore->records === [], 'A different or forged approval ID must fail closed even when every other review field matches.');

foreach (['is_shadow', 'is_calibration', 'is_historical'] as $originFlag) {
	$originStore = new CF7AIVDeliveryMemoryStore();
	$originDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
	$originWorker = new CF7AIV\Delivery\DeliveryWorker(
		$originStore,
		static fn (): array => array_merge($forgedManual, [$originFlag => 1]),
		static fn (): array => [$mapping],
		static fn (): array => ['ga4' => $originDestination],
		static fn (): array => $gaConfig,
		static fn (): array => [$attribution, $consent],
		null,
		$testCredentials,
		static fn (): bool => true,
	);
	$originWorker->process(42);
	cf7aiv_delivery_assert($originDestination->calls === 0 && $originStore->records === [], "Audited manual review must never bypass {$originFlag} origin safety.");
}

$retryStore = new CF7AIVDeliveryMemoryStore(); $retryQueue = [];
$retryDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(false, true, null, 'Destination returned HTTP 429.'));
$retryWorker = new CF7AIV\Delivery\DeliveryWorker($retryStore, static fn (): array => $item, static fn (): array => [$mapping], static fn (): array => ['ga4' => $retryDestination], static fn (): array => $gaConfig, static fn (): array => [$attribution, $consent], static function (string $hook, array $args, int $delay) use (&$retryQueue): void { $retryQueue[] = compact('hook', 'args', 'delay'); }, $testCredentials);
$retryWorker->process(42);
cf7aiv_delivery_assert($retryQueue[0]['hook'] === 'cf7aiv_deliver_item' && $retryQueue[0]['delay'] === 60, 'Transient delivery failures must requeue with the bounded retry schedule.');

$pauseStore = new CF7AIVDeliveryMemoryStore();
$failedDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(false, false, null, 'Destination returned HTTP 401.'));
$pauseWorker = new CF7AIV\Delivery\DeliveryWorker($pauseStore, static fn (): array => $item, static fn (): array => [$mapping], static fn (): array => ['ga4' => $failedDestination], static fn (): array => $gaConfig + ['delivery' => ['pause_threshold' => 2]], static fn (): array => [$attribution, $consent], null, $testCredentials);
$pauseWorker->process(42);
$mapping2 = CF7AIV\Delivery\DeliveryMapping::fromArray(['key' => 'high-ga4-v3', 'version' => '3', 'tier_id' => 'high_value_care', 'destination_key' => 'ga4', 'event_name' => 'qualify_lead', 'value' => 125.5, 'currency' => 'GBP', 'status' => 'published', 'enabled' => true]);
$pauseWorker2 = new CF7AIV\Delivery\DeliveryWorker($pauseStore, static fn (): array => $item, static fn (): array => [$mapping2], static fn (): array => ['ga4' => $failedDestination], static fn (): array => $gaConfig + ['delivery' => ['pause_threshold' => 2]], static fn (): array => [$attribution, $consent], null, $testCredentials);
$pauseWorker2->process(42);
cf7aiv_delivery_assert($pauseStore->isPaused('ga4') && ! str_contains($pauseStore->paused['ga4'], 'token'), 'Repeated permanent failures must pause the destination with a sanitized reason.');

$gaOnlyStore = new CF7AIVDeliveryMemoryStore(); $gaOnlyDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
$gaOnlyWorker = new CF7AIV\Delivery\DeliveryWorker($gaOnlyStore, static fn (): array => $item, static fn (): array => [$mapping], static fn (): array => ['ga4' => $gaOnlyDestination], static fn (): array => ['destinations' => ['ga4' => ['enabled' => true, 'dry_run' => true, 'measurement_id' => 'G-TEST']], 'consent' => ['mode' => 'respect']], static fn (): array => [['client_id' => '123.456'], ['analytics_storage' => 'granted', 'ad_storage' => 'denied', 'ad_user_data' => 'denied']], null, $testCredentials);
$gaOnlyWorker->process(42);
cf7aiv_delivery_assert($gaOnlyDestination->calls === 1, 'GA4-only delivery must use analytics consent and client_id without requiring advertising consent or identity hashes.');

$gaMissingStore = new CF7AIVDeliveryMemoryStore(); $gaMissingDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
(new CF7AIV\Delivery\DeliveryWorker($gaMissingStore, static fn (): array => $item, static fn (): array => [$mapping], static fn (): array => ['ga4' => $gaMissingDestination], static fn (): array => $gaConfig, static fn (): array => [[], $consent], null, $testCredentials))->process(42);
cf7aiv_delivery_assert($gaMissingDestination->calls === 0 && $gaMissingStore->records === [], 'GA4 must not claim or send without its required client identifier.');

$msMapping = CF7AIV\Delivery\DeliveryMapping::fromArray(['key' => 'high-ms-v1', 'version' => '1', 'tier_id' => 'high_value_care', 'destination_key' => 'microsoft_uet', 'event_name' => 'qualified_lead', 'value' => 125.5, 'currency' => 'GBP', 'status' => 'published', 'enabled' => true]);
$msStore = new CF7AIVDeliveryMemoryStore(); $msDestination = new CF7AIVCountingDestination('microsoft_uet', new CF7AIV\Domain\DeliveryResult(true));
(new CF7AIV\Delivery\DeliveryWorker($msStore, static fn (): array => $item, static fn (): array => [$msMapping], static fn (): array => ['microsoft_uet' => $msDestination], static fn (): array => ['destinations' => ['microsoft_uet' => ['enabled' => true, 'dry_run' => true, 'tag_id' => '12345']], 'consent' => ['mode' => 'respect']], static fn (): array => [['msclkid' => '0123456789abcdef0123456789abcdef'], ['analytics_storage' => 'denied', 'ad_storage' => 'granted', 'ad_user_data' => 'denied']], null, $testCredentials))->process(42);
cf7aiv_delivery_assert($msDestination->calls === 1, 'Microsoft-only delivery must use advertising consent and a Microsoft click identifier without requiring analytics consent.');

$overrideStore = new CF7AIVDeliveryMemoryStore(); $overrideDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
$overrideSettings = $gaConfig; $overrideSettings['consent'] = ['mode' => 'legal_basis_override', 'override' => ['administrator_id' => 77, 'date' => '2026-07-22T10:00:00+00:00', 'reason' => 'Documented legitimate interest assessment.']];
(new CF7AIV\Delivery\DeliveryWorker($overrideStore, static fn (): array => $item, static fn (): array => [$mapping], static fn (): array => ['ga4' => $overrideDestination], static fn (): array => $overrideSettings, static fn (): array => [['client_id' => '123.456'], ['analytics_storage' => 'denied', 'ad_storage' => 'denied', 'ad_user_data' => 'denied']], null, $testCredentials))->process(42);
cf7aiv_delivery_assert($overrideDestination->calls === 1 && ($overrideDestination->lastEvent?->audit['administrator_id'] ?? 0) === 77, 'A complete legal-basis override must be evaluated per destination and preserve its audit in the event.');

$badConfigStore = new CF7AIVDeliveryMemoryStore(); $badConfigDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
(new CF7AIV\Delivery\DeliveryWorker($badConfigStore, static fn (): array => $item, static fn (): array => [$mapping], static fn (): array => ['ga4' => $badConfigDestination], static fn (): array => ['destinations' => ['ga4' => ['enabled' => true, 'dry_run' => true, 'measurement_id' => '']], 'consent' => ['mode' => 'always_actual']], static fn (): array => [['client_id' => '123.456'], $consent]))->process(42);
cf7aiv_delivery_assert($badConfigDestination->calls === 0 && $badConfigStore->records === [], 'Delivery must validate destination configuration before claiming a delivery.');

$missingCredentialStore = new CF7AIVDeliveryMemoryStore(); $missingCredentialDestination = new CF7AIVCountingDestination('ga4', new CF7AIV\Domain\DeliveryResult(true));
(new CF7AIV\Delivery\DeliveryWorker($missingCredentialStore, static fn (): array => $item, static fn (): array => [$mapping], static fn (): array => ['ga4' => $missingCredentialDestination], static fn (): array => $gaConfig, static fn (): array => [['client_id' => '123.456'], $consent]))->process(42);
cf7aiv_delivery_assert($missingCredentialDestination->calls === 0 && $missingCredentialStore->records === [], 'Credential preflight must refuse even dry-run mappings before claim when the concrete destination would reject an empty secret.');

cf7aiv_delivery_assert(isset($GLOBALS['cf7aiv_test_actions']['cf7aiv_evaluate_delivery'], $GLOBALS['cf7aiv_test_actions']['cf7aiv_deliver_item']), 'Delivery hooks must integrate classification evaluation and retry workers.');
cf7aiv_delivery_assert(isset($GLOBALS['cf7aiv_test_filters']['cf7aiv_destinations']), 'Built-in destinations must register through the public destination filter.');

echo "PASS DeliveryTest\n";
