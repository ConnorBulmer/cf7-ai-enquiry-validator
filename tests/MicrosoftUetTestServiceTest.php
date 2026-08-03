<?php
/**
 * Standalone controlled Microsoft UET test-service tests.
 * Run: php tests/MicrosoftUetTestServiceTest.php
 */

declare(strict_types=1);

function cf7aiv_microsoft_test_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$GLOBALS['cf7aiv_test_actions'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function as_enqueue_async_action(): int { return 1; }

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'microsoft-test-auth-key');
define('SECURE_AUTH_KEY', 'microsoft-test-secure-auth-key');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

final class CF7AIVMicrosoftTestTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	public array $requests = [];
	public function __construct(private array $responses) {}
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->requests[] = compact('url', 'arguments');
		$response = array_shift($this->responses);
		if ($response instanceof Throwable) {
			throw $response;
		}
		return $response instanceof CF7AIV\Infrastructure\HttpResponse
			? $response
			: new CF7AIV\Infrastructure\HttpResponse(500, '');
	}
}

$settings = CF7AIV\Infrastructure\Settings::withDefaults([
	'automation' => ['mode' => 'shadow'],
	'destinations' => [
		'microsoft_uet' => [
			'enabled' => false,
			'tag_id' => 'controlled-tag',
			'dry_run' => true,
		],
	],
]);
$settingsBefore = serialize($settings);

$refusedTransport = new CF7AIVMicrosoftTestTransport([]);
$refusedService = new CF7AIV\Integration\MicrosoftUetTestService(
	$refusedTransport,
	static fn (): array => $settings,
	static fn (string $name): string => $name === 'microsoft_uet_token' ? 'controlled-token' : '',
);
$refused = false;
try {
	$refusedService->transport('yes');
} catch (InvalidArgumentException $error) {
	$refused = str_contains($error->getMessage(), 'SEND_ZERO_VALUE_MICROSOFT_TEST');
}
cf7aiv_microsoft_test_assert($refused && $refusedTransport->requests === [], 'Exact confirmation must be required before Microsoft transport.');

$transport = new CF7AIVMicrosoftTestTransport([
	new CF7AIV\Infrastructure\HttpResponse(202, '{}'),
]);
$service = new CF7AIV\Integration\MicrosoftUetTestService(
	$transport,
	static fn (): array => $settings,
	static fn (string $name): string => $name === 'microsoft_uet_token' ? 'controlled-token' : '',
	static fn (): int => 1785405600,
	static fn (): string => 'cf7aiv-microsoft-test-fixed',
);
$receipt = $service->transport('SEND_ZERO_VALUE_MICROSOFT_TEST');
$request = $transport->requests[0];
$body = json_decode($request['arguments']['body'], true, 32, JSON_THROW_ON_ERROR);
$event = $body['data'][0] ?? [];
cf7aiv_microsoft_test_assert(
	count($transport->requests) === 1
	&& $request['url'] === 'https://capi.uet.microsoft.com/v1/controlled-tag/events'
	&& $request['arguments']['headers']['Authorization'] === 'Bearer controlled-token',
	'The confirmed Microsoft test must send exactly one authenticated request to the configured tag endpoint.',
);
cf7aiv_microsoft_test_assert(
	($body['continueOnValidationError'] ?? null) === false
	&& $event['eventName'] === 'cf7aiv_transport_test'
	&& $event['eventId'] === 'cf7aiv-microsoft-test-fixed'
	&& is_numeric($event['customData']['value'])
	&& (float) $event['customData']['value'] === 0.0
	&& $event['customData']['currency'] === 'GBP'
	&& $event['customData']['transactionId'] === $event['eventId']
	&& $event['adStorageConsent'] === 'D'
	&& ($event['userData'] ?? []) === [],
	'The controlled Microsoft event must be zero-value, synthetic, consent-denied, and identifier-free.',
);
$serializedBody = json_encode($body, JSON_THROW_ON_ERROR);
foreach (['msclkid', 'email', 'phone', 'clientUserAgent', 'clientIpAddress', 'anonymousId', 'PRIVATE'] as $forbidden) {
	cf7aiv_microsoft_test_assert(! str_contains($serializedBody, $forbidden), "Controlled Microsoft payload must exclude {$forbidden}.");
}
$serializedReceipt = json_encode($receipt, JSON_THROW_ON_ERROR);
cf7aiv_microsoft_test_assert(
	$receipt['mode'] === 'transport'
	&& $receipt['safety_profile'] === 'zero_value_synthetic_consent_denied'
	&& $receipt['event_name'] === 'cf7aiv_transport_test'
	&& $receipt['event_id'] === 'cf7aiv-microsoft-test-fixed'
	&& $receipt['successful'] === true
	&& $receipt['retryable'] === false
	&& $receipt['live_request_sent'] === true,
	'The Microsoft test receipt must report its sanitized transport outcome.',
);
cf7aiv_microsoft_test_assert(
	! str_contains($serializedReceipt, 'controlled-token')
	&& ! str_contains($serializedReceipt, 'controlled-tag'),
	'The Microsoft test receipt must not expose the configured token or tag ID.',
);
cf7aiv_microsoft_test_assert(serialize($settings) === $settingsBefore, 'Controlled Microsoft testing must not mutate production settings.');

$missingConfiguration = false;
try {
	(new CF7AIV\Integration\MicrosoftUetTestService(
		new CF7AIVMicrosoftTestTransport([]),
		static fn (): array => CF7AIV\Infrastructure\Settings::defaults(),
		static fn (string $name): string => '',
	))->transport('SEND_ZERO_VALUE_MICROSOFT_TEST');
} catch (InvalidArgumentException $error) {
	$missingConfiguration = str_contains($error->getMessage(), 'tag ID and authentication key');
}
cf7aiv_microsoft_test_assert($missingConfiguration, 'Microsoft transport testing must fail closed when configuration is incomplete.');

$emptyEventId = false;
try {
	(new CF7AIV\Integration\MicrosoftUetTestService(
		new CF7AIVMicrosoftTestTransport([]),
		static fn (): array => $settings,
		static fn (string $name): string => 'controlled-token',
		static fn (): int => 1785405600,
		static fn (): string => '',
	))->transport('SEND_ZERO_VALUE_MICROSOFT_TEST');
} catch (InvalidArgumentException $error) {
	$emptyEventId = str_contains($error->getMessage(), 'cannot be empty');
}
cf7aiv_microsoft_test_assert($emptyEventId, 'Microsoft transport testing must refuse an empty synthetic event ID.');

foreach ([
	[400, false],
	[401, false],
	[429, true],
	[500, true],
] as [$status, $retryable]) {
	$failureTransport = new CF7AIVMicrosoftTestTransport([
		new CF7AIV\Infrastructure\HttpResponse($status, '{"errors":[{"code":"Rejected","message":"token=controlled-token private@example.test"}]}'),
	]);
	$failureReceipt = (new CF7AIV\Integration\MicrosoftUetTestService(
		$failureTransport,
		static fn (): array => $settings,
		static fn (string $name): string => 'controlled-token',
		static fn (): int => 1785405600,
		static fn (): string => 'cf7aiv-microsoft-test-fixed',
	))->transport('SEND_ZERO_VALUE_MICROSOFT_TEST');
	$failureJson = json_encode($failureReceipt, JSON_THROW_ON_ERROR);
	cf7aiv_microsoft_test_assert(
		$failureReceipt['successful'] === false
		&& $failureReceipt['retryable'] === $retryable
		&& $failureReceipt['live_request_sent'] === true,
		"Microsoft HTTP {$status} must produce the expected safe transport-test receipt.",
	);
	cf7aiv_microsoft_test_assert(! str_contains($failureJson, 'controlled-token') && ! str_contains($failureJson, 'private@example.test'), 'Microsoft failure receipts must redact response secrets and identity.');
}

$malformedReceipt = (new CF7AIV\Integration\MicrosoftUetTestService(
	new CF7AIVMicrosoftTestTransport([new CF7AIV\Infrastructure\HttpResponse(200, '{not-json')]),
	static fn (): array => $settings,
	static fn (string $name): string => 'controlled-token',
	static fn (): int => 1785405600,
	static fn (): string => 'cf7aiv-microsoft-test-fixed',
))->transport('SEND_ZERO_VALUE_MICROSOFT_TEST');
cf7aiv_microsoft_test_assert($malformedReceipt['successful'] === true, 'An optional malformed Microsoft success body must not crash controlled testing.');

$timeoutReceipt = (new CF7AIV\Integration\MicrosoftUetTestService(
	new CF7AIVMicrosoftTestTransport([new RuntimeException('Timed out with token=controlled-token private@example.test')]),
	static fn (): array => $settings,
	static fn (string $name): string => 'controlled-token',
	static fn (): int => 1785405600,
	static fn (): string => 'cf7aiv-microsoft-test-fixed',
))->transport('SEND_ZERO_VALUE_MICROSOFT_TEST');
cf7aiv_microsoft_test_assert($timeoutReceipt['successful'] === false && $timeoutReceipt['retryable'] === true, 'Microsoft transport timeouts must be safely retryable.');
cf7aiv_microsoft_test_assert(! str_contains(json_encode($timeoutReceipt, JSON_THROW_ON_ERROR), 'controlled-token'), 'Microsoft timeout receipts must not expose exception details.');

echo "PASS MicrosoftUetTestServiceTest\n";
