<?php
/**
 * Standalone controlled GA4 test-service tests.
 * Run: php tests/GA4TestServiceTest.php
 */

declare(strict_types=1);

function cf7aiv_ga4_test_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$GLOBALS['cf7aiv_test_actions'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function as_enqueue_async_action(): int { return 1; }

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'ga4-test-auth-key');
define('SECURE_AUTH_KEY', 'ga4-test-secure-auth-key');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

final class CF7AIVGA4TestTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	public array $requests = [];
	public function __construct(private array $responses) {}
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->requests[] = compact('url', 'arguments');
		$response = array_shift($this->responses);
		return $response instanceof CF7AIV\Infrastructure\HttpResponse
			? $response
			: new CF7AIV\Infrastructure\HttpResponse(500, '');
	}
}

$settings = CF7AIV\Infrastructure\Settings::withDefaults([
	'automation' => ['mode' => 'shadow'],
	'destinations' => [
		'ga4' => [
			'enabled' => false,
			'measurement_id' => 'G-CONTROLLED',
			'dry_run' => true,
			'live_enabled' => false,
			'debug_validated' => true,
		],
	],
]);

$validationTransport = new CF7AIVGA4TestTransport([
	new CF7AIV\Infrastructure\HttpResponse(200, '{"validationMessages":[]}'),
]);
$validationService = new CF7AIV\Integration\GA4TestService(
	$validationTransport,
	static fn (): array => $settings,
	static fn (string $name): string => $name === 'ga4_api_secret' ? 'controlled-secret' : '',
	static fn (): int => 1785405600,
	static fn (): string => 'cf7aiv-test-fixed',
);
$validation = $validationService->validate();
$validationBody = json_decode($validationTransport->requests[0]['arguments']['body'], true, 32, JSON_THROW_ON_ERROR);
cf7aiv_ga4_test_assert(
	$validation['successful'] === true
	&& $validation['mode'] === 'validate'
	&& $validation['event_id'] === 'cf7aiv-test-fixed'
	&& count($validationTransport->requests) === 1,
	'Validation mode must send exactly one controlled debug request and return its stable receipt.',
);
cf7aiv_ga4_test_assert(
	str_contains($validationTransport->requests[0]['url'], '/debug/mp/collect?')
	&& ($validationBody['validation_behavior'] ?? '') === 'ENFORCE_RECOMMENDATIONS'
	&& $validationBody['events'][0]['name'] === 'cf7aiv_transport_test'
	&& $validationBody['events'][0]['params']['value'] === 0
	&& $validationBody['events'][0]['params']['currency'] === 'GBP',
	'Validation must use strict Google validation with the dedicated zero-value test event.',
);

$refusedTransport = new CF7AIVGA4TestTransport([]);
$refusedService = new CF7AIV\Integration\GA4TestService(
	$refusedTransport,
	static fn (): array => $settings,
	static fn (string $name): string => $name === 'ga4_api_secret' ? 'controlled-secret' : '',
);
$refused = false;
try { $refusedService->transport('yes'); } catch (InvalidArgumentException $error) {
	$refused = str_contains($error->getMessage(), 'SEND_ZERO_VALUE_TEST');
}
cf7aiv_ga4_test_assert($refused && $refusedTransport->requests === [], 'Transport mode must refuse before any request unless the exact confirmation token is supplied.');

$transport = new CF7AIVGA4TestTransport([
	new CF7AIV\Infrastructure\HttpResponse(200, '{"validationMessages":[]}'),
	new CF7AIV\Infrastructure\HttpResponse(204, ''),
]);
$service = new CF7AIV\Integration\GA4TestService(
	$transport,
	static fn (): array => $settings,
	static fn (string $name): string => $name === 'ga4_api_secret' ? 'controlled-secret' : '',
	static fn (): int => 1785405600,
	static fn (): string => 'cf7aiv-test-fixed',
);
$receipt = $service->transport('SEND_ZERO_VALUE_TEST');
$liveBody = json_decode($transport->requests[1]['arguments']['body'], true, 32, JSON_THROW_ON_ERROR);
$serializedReceipt = json_encode($receipt, JSON_THROW_ON_ERROR);
cf7aiv_ga4_test_assert(
	$receipt['successful'] === true
	&& $receipt['mode'] === 'transport'
	&& $receipt['safety_profile'] === 'zero_value_synthetic_consent_denied'
	&& $receipt['preflight_successful'] === true
	&& $receipt['live_request_sent'] === true
	&& count($transport->requests) === 2
	&& str_contains($transport->requests[1]['url'], '/mp/collect?'),
	'Confirmed transport mode must pass strict preflight before sending exactly one live collection request.',
);
cf7aiv_ga4_test_assert(
	! isset($liveBody['validation_behavior'])
	&& $liveBody['client_id'] === '555.1785405600'
	&& $liveBody['events'][0]['name'] === 'cf7aiv_transport_test'
	&& $liveBody['events'][0]['params']['session_id'] === '1785405600'
	&& $liveBody['events'][0]['params']['engagement_time_msec'] === 1
	&& $liveBody['consent'] === ['ad_user_data' => 'DENIED', 'ad_personalization' => 'DENIED']
	&& ! isset($liveBody['user_data'])
	&& ! str_contains(json_encode($liveBody, JSON_THROW_ON_ERROR), 'gclid'),
	'The live test must be observable but synthetic, consent-denied, PII-free, and unrelated to click identifiers.',
);
cf7aiv_ga4_test_assert(
	! str_contains($serializedReceipt, 'controlled-secret')
	&& ! str_contains($serializedReceipt, 'G-CONTROLLED'),
	'The returned receipt must not expose the configured GA4 credential or measurement identifier.',
);
cf7aiv_ga4_test_assert(
	$settings['automation']['mode'] === 'shadow'
	&& $settings['destinations']['ga4']['dry_run'] === true
	&& $settings['destinations']['ga4']['live_enabled'] === false,
	'Controlled testing must not mutate production automation or destination lifecycle settings.',
);

$failedPreflightTransport = new CF7AIVGA4TestTransport([
	new CF7AIV\Infrastructure\HttpResponse(200, '{"validationMessages":[{"description":"invalid"}]}'),
]);
$failedPreflight = new CF7AIV\Integration\GA4TestService(
	$failedPreflightTransport,
	static fn (): array => $settings,
	static fn (string $name): string => $name === 'ga4_api_secret' ? 'controlled-secret' : '',
);
$failedReceipt = $failedPreflight->transport('SEND_ZERO_VALUE_TEST');
cf7aiv_ga4_test_assert(
	$failedReceipt['successful'] === false
	&& $failedReceipt['live_request_sent'] === false
	&& count($failedPreflightTransport->requests) === 1,
	'A failed strict preflight must prevent the live collection request.',
);

echo "PASS GA4TestServiceTest\n";
