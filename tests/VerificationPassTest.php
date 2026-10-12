<?php
/**
 * Standalone optional-verification tests. Run: php tests/VerificationPassTest.php
 */

declare(strict_types=1);

function verify_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

function plugin_dir_url(string $file): string {
	return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/';
}

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

final class CF7AIVVerificationTransport implements CF7AIV\Infrastructure\HttpTransportInterface {
	/** @var list<CF7AIV\Infrastructure\HttpResponse> */ public array $responses;
	/** @var list<array<string,mixed>> */ public array $requests = [];
	public function __construct(array $responses) { $this->responses = $responses; }
	public function post(string $url, array $arguments): CF7AIV\Infrastructure\HttpResponse {
		$this->requests[] = $arguments;
		$response = array_shift($this->responses);
		if (! $response instanceof CF7AIV\Infrastructure\HttpResponse) { throw new RuntimeException('Missing provider response.'); }
		return $response;
	}
}

$request = new CF7AIV\Domain\ClassificationRequest(
	'candidate-1',
	'agency-v2',
	'message: We need a website rebuild soon.',
	['message' => 'We need a website rebuild soon.'],
	'Classify this redacted enquiry.',
	['website_rebuild'],
	['primary_opportunity'],
	['service_fit' => 'service_fit', 'commercial_intent' => 'commercial_intent', 'specific_need' => 'specificity'],
	CF7AIV\Classification\PolicyValidator::SCHEMA_V2,
);
$providerResult = json_encode([
	'outcome_id' => 'primary_opportunity',
	'tier_id' => 'website_rebuild',
	'reason_code' => 'qualified',
	'model_confidence' => 0.8,
	'evidence' => [
		['dimension' => 'service_fit', 'code' => 'service_fit', 'field_key' => 'message', 'excerpt' => 'website rebuild'],
		['dimension' => 'commercial_intent', 'code' => 'commercial_intent', 'field_key' => 'message', 'excerpt' => 'We need'],
		['dimension' => 'specificity', 'code' => 'specific_need', 'field_key' => 'message', 'excerpt' => 'website rebuild'],
	],
	'missing_evidence' => [],
	'exclusion_codes' => [],
	'ambiguity_flags' => [],
	'summary' => 'Specific prospective project enquiry.',
], JSON_THROW_ON_ERROR);
$transport = new CF7AIVVerificationTransport([
	new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
		'choices' => [['message' => ['content' => $providerResult]]],
		'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'completion_tokens_details' => ['reasoning_tokens' => 0]],
	], JSON_THROW_ON_ERROR)),
	new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
		'choices' => [['message' => ['content' => $providerResult]]],
		'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 30, 'completion_tokens_details' => ['reasoning_tokens' => 12]],
	], JSON_THROW_ON_ERROR)),
]);
$provider = new CF7AIV\Providers\DeepSeekProvider($transport, 'deep-secret');
$provider->classify($request);
$provider->classifyWithReasoning($request);
$first = json_decode((string) $transport->requests[0]['body'], true, 32, JSON_THROW_ON_ERROR);
$second = json_decode((string) $transport->requests[1]['body'], true, 32, JSON_THROW_ON_ERROR);
verify_assert(($first['thinking']['type'] ?? '') === 'disabled', 'Normal DeepSeek classification must remain economical.');
verify_assert(($second['thinking']['type'] ?? '') === 'enabled', 'Optional verification must enable deeper reasoning.');
verify_assert(($second['max_tokens'] ?? 0) >= 1600, 'Reasoning mode needs enough room for reasoning and JSON output.');
verify_assert(! array_key_exists('temperature', $second), 'Reasoning mode must omit temperature for API compatibility.');
verify_assert($provider instanceof CF7AIV\Contracts\VerificationCapableProviderInterface, 'DeepSeek must advertise its optional verification capability.');
verify_assert(! (new CF7AIV\Providers\OpenAIProvider(new CF7AIVVerificationTransport([]), 'open-secret')) instanceof CF7AIV\Contracts\VerificationCapableProviderInterface, 'OpenAI must not advertise an untested reasoning contract.');

$retryTransport = new CF7AIVVerificationTransport([
	new CF7AIV\Infrastructure\HttpResponse(200, '{malformed'),
	new CF7AIV\Infrastructure\HttpResponse(200, json_encode([
		'choices' => [['message' => ['content' => $providerResult]]],
		'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 4],
	], JSON_THROW_ON_ERROR)),
]);
$retryResult = (new CF7AIV\Providers\DeepSeekProvider($retryTransport, 'deep-secret'))->classify($request);
verify_assert($retryResult->providerUsage === ['input_tokens' => 7, 'output_tokens' => 4, 'reasoning_tokens' => 0, 'calls' => 2], 'Malformed-response retries must preserve HTTP call-count telemetry while storing no provider body.');

final class CF7AIVVerificationPolicyStore implements CF7AIV\Classification\PolicyStoreInterface {
	/** @var array<string,array<string,mixed>> */ public array $records = [];
	public function save(array $record): int { $this->records[$record['policy_key'] . ':' . $record['version']] = $record; return count($this->records); }
	public function publish(string $policyKey, string $version): bool {
		$key = $policyKey . ':' . $version;
		if (! isset($this->records[$key])) { return false; }
		$this->records[$key]['status'] = 'published';
		return true;
	}
	public function published(array $policyKeys): ?array {
		foreach ($policyKeys as $policyKey) {
			foreach (array_reverse($this->records) as $record) {
				if ($record['policy_key'] === $policyKey && $record['status'] === 'published') { return $record; }
			}
		}
		return null;
	}
}

final class CF7AIVVerificationItemStore implements CF7AIV\Classification\ClassificationItemStoreInterface {
	public array $success = [];
	public array $failures = [];
	public function __construct(public array $item) {}
	public function get(int $itemId): ?array { return (int) $this->item['id'] === $itemId ? $this->item : null; }
	public function linkSource(int $itemId, string $sourceType, string $sourceReference): void {}
	public function succeed(int $itemId, CF7AIV\Domain\ClassificationResult $result, array $policy, CF7AIV\Classification\EvidenceDecision $evidenceDecision, string $verificationState, string $reviewState, bool $isShadow, bool $replacePrediction = false): bool {
		$this->success = compact('itemId', 'result', 'policy', 'evidenceDecision', 'verificationState', 'reviewState', 'isShadow', 'replacePrediction');
		return true;
	}
	public function fail(int $itemId, string $message, bool $transient, bool $replacePrediction = false): int|false { $this->failures[] = compact('itemId', 'message', 'transient', 'replacePrediction'); return count($this->failures); }
}

final class CF7AIVVerificationSource implements CF7AIV\Contracts\SubmissionSourceInterface {
	public function key(): string { return 'test'; }
	public function get(string $reference): ?array {
		return ['source' => 'test', 'reference' => $reference, 'form_id' => 5, 'fields' => ['message' => 'We need a website rebuild soon.'], 'is_historical' => false, 'is_calibration' => false];
	}
	public function iterate(array $arguments = []): iterable { return []; }
}

final class CF7AIVVerificationProvider implements CF7AIV\Contracts\VerificationCapableProviderInterface {
	public int $normalCalls = 0;
	public int $reasoningCalls = 0;
	public function __construct(
		private readonly CF7AIV\Domain\ClassificationResult $first,
		private readonly CF7AIV\Domain\ClassificationResult|Throwable $second,
	) {}
	public function key(): string { return 'verify'; }
	public function classify(CF7AIV\Domain\ClassificationRequest $request): CF7AIV\Domain\ClassificationResult { $this->normalCalls++; return $this->first; }
	public function classifyWithReasoning(CF7AIV\Domain\ClassificationRequest $request): CF7AIV\Domain\ClassificationResult {
		$this->reasoningCalls++;
		if ($this->second instanceof Throwable) { throw $this->second; }
		return $this->second;
	}
}

final class CF7AIVUnsupportedVerificationProvider implements CF7AIV\Contracts\AIProviderInterface {
	public int $calls = 0;
	public function __construct(private readonly CF7AIV\Domain\ClassificationResult $result) {}
	public function key(): string { return 'unsupported'; }
	public function classify(CF7AIV\Domain\ClassificationRequest $request): CF7AIV\Domain\ClassificationResult { $this->calls++; return $this->result; }
}

/** @param array<string,int> $usage */
function verification_result(string $tier = 'website_rebuild', string $outcome = 'primary_opportunity', array $usage = []): CF7AIV\Domain\ClassificationResult {
	$evidence = [
		new CF7AIV\Classification\EvidenceAssertion('service_fit', 'service_fit', 'message', 'website rebuild', true),
		new CF7AIV\Classification\EvidenceAssertion('commercial_intent', 'commercial_intent', 'message', 'We need', true),
		new CF7AIV\Classification\EvidenceAssertion('specificity', 'specific_need', 'message', 'website rebuild', true),
	];
	return (new CF7AIV\Domain\ClassificationResult($tier, 'qualified', 0.8, 'Safe.', [], $outcome, $evidence))->withProviderUsage($usage);
}

/** @return array{0:CF7AIVVerificationItemStore,1:list<array<string,mixed>>} */
function verification_run(
	CF7AIV\Contracts\AIProviderInterface $provider,
	CF7AIV\Classification\PolicyRepository $policies,
	string $verificationMode,
	array $itemOverrides = [],
	bool $withMapping = true,
	string $automationMode = 'live',
	?callable $duplicate = null,
): array {
	static $itemId = 100;
	$itemId++;
	$item = array_merge([
		'id' => $itemId,
		'source_type' => 'test',
		'source_reference' => (string) $itemId,
		'form_id' => 5,
		'is_calibration' => 0,
		'is_historical' => 0,
	], $itemOverrides);
	$store = new CF7AIVVerificationItemStore($item);
	$queue = [];
	$worker = new CF7AIV\Classification\ClassificationWorker(
		$store,
		$policies,
		static fn (): array => ['test' => new CF7AIVVerificationSource()],
		static fn (): array => [$provider->key() => $provider],
		static fn (): array => ['automation' => ['mode' => $automationMode, 'verification_mode' => $verificationMode], 'providers' => ['selected' => $provider->key()]],
		static function (string $hook, array $arguments, int $delay) use (&$queue): void { $queue[] = compact('hook', 'arguments', 'delay'); },
		static fn (string $tierId): array => $withMapping && $tierId === 'website_rebuild'
			? [new CF7AIV\Delivery\DeliveryMapping('agency-map', 'v1', $tierId, 'ga4', 'qualify_lead', 1.0, 'GBP', 'published', true)]
			: [],
		duplicateResolver: $duplicate ?? static fn (): bool => false,
	);
	$worker->process($itemId);
	return [$store, $queue];
}

$policyStore = new CF7AIVVerificationPolicyStore();
$policies = new CF7AIV\Classification\PolicyRepository($policyStore);
$agency = (new CF7AIV\Classification\PolicyPackRegistry())->get('agency');
verify_assert(is_array($agency), 'Verification worker tests require the agency policy pack.');
$policies->saveDraft('global', 'agency-v2', $agency);
$policies->publish('global', 'agency-v2');

$firstResult = verification_result(usage: ['input_tokens' => 10, 'output_tokens' => 5, 'reasoning_tokens' => 0, 'calls' => 1]);
$agreeResult = verification_result(usage: ['input_tokens' => 12, 'output_tokens' => 8, 'reasoning_tokens' => 3, 'calls' => 1]);

$offProvider = new CF7AIVVerificationProvider($firstResult, $agreeResult);
[$offStore, $offQueue] = verification_run($offProvider, $policies, 'off');
verify_assert($offProvider->normalCalls === 1 && $offProvider->reasoningCalls === 0, 'Verification off must make exactly one provider call.');
verify_assert(($offStore->success['verificationState'] ?? '') === 'off', 'Verification off must persist the off state.');
verify_assert(($offQueue[0]['hook'] ?? '') === 'cf7aiv_evaluate_delivery', 'Turning verification off must preserve ordinary eligible delivery routing.');

$agreeProvider = new CF7AIVVerificationProvider($firstResult, $agreeResult);
[$agreeStore, $agreeQueue] = verification_run($agreeProvider, $policies, 'automatic_candidates');
verify_assert($agreeProvider->normalCalls === 1 && $agreeProvider->reasoningCalls === 1, 'An automatic, otherwise eligible Strong match must receive exactly one verification pass.');
verify_assert(($agreeStore->success['verificationState'] ?? '') === 'passed', 'Independent agreement must persist passed.');
verify_assert(($agreeStore->success['reviewState'] ?? '') === 'eligible' && ($agreeQueue[0]['hook'] ?? '') === 'cf7aiv_evaluate_delivery', 'Passed verification must preserve eligible delivery routing.');
$storedAgreement = $agreeStore->success['result'] ?? null;
verify_assert($storedAgreement instanceof CF7AIV\Domain\ClassificationResult
	&& $storedAgreement->tierId === $firstResult->tierId
	&& $storedAgreement->outcomeId === $firstResult->outcomeId
	&& $storedAgreement->reasonCode === $firstResult->reasonCode
	&& $storedAgreement->summary === $firstResult->summary
	&& $storedAgreement->evidence === $firstResult->evidence, 'The displayed classification must remain the first-pass result.');
verify_assert($agreeStore->success['result']->providerUsage === ['input_tokens' => 22, 'output_tokens' => 13, 'reasoning_tokens' => 3, 'calls' => 2], 'Verification must merge only normalized usage counters into the first result.');

$disagreeProvider = new CF7AIVVerificationProvider($firstResult, verification_result('retained_marketing', 'primary_opportunity', ['input_tokens' => 11, 'output_tokens' => 7, 'reasoning_tokens' => 2, 'calls' => 1]));
[$disagreeStore, $disagreeQueue] = verification_run($disagreeProvider, $policies, 'automatic_candidates');
verify_assert(($disagreeStore->success['verificationState'] ?? '') === 'disagreed', 'A different independently Strong tier must persist disagreed.');
verify_assert(($disagreeStore->success['evidenceDecision']->state ?? '') === 'needs_review' && ($disagreeStore->success['reviewState'] ?? '') === 'review', 'Disagreement must replace the effective evidence decision with Needs review.');
verify_assert($disagreeQueue === [], 'Disagreement must not enqueue delivery.');
verify_assert($disagreeStore->success['result']->tierId === 'website_rebuild', 'Disagreement must preserve the first-pass classification for display.');

$failedProvider = new CF7AIVVerificationProvider($firstResult, new CF7AIV\Providers\TransientProviderException('Verification unavailable.'));
[$failedStore, $failedQueue] = verification_run($failedProvider, $policies, 'automatic_candidates');
verify_assert(($failedStore->success['verificationState'] ?? '') === 'failed' && $failedStore->failures === [], 'A verification exception must persist failed without failing or retrying the whole classification.');
verify_assert(($failedStore->success['evidenceDecision']->state ?? '') === 'needs_review' && $failedQueue === [], 'Failed verification must route to review without any retry or delivery enqueue.');

$unsupportedProvider = new CF7AIVUnsupportedVerificationProvider($firstResult);
[$unsupportedStore, $unsupportedQueue] = verification_run($unsupportedProvider, $policies, 'automatic_candidates');
verify_assert($unsupportedProvider->calls === 1, 'An unsupported provider must not receive a second call.');
verify_assert(($unsupportedStore->success['verificationState'] ?? '') === 'unsupported' && ($unsupportedStore->success['reviewState'] ?? '') === 'review', 'An unsupported automatic candidate must persist unsupported and route to review.');
verify_assert($unsupportedQueue === [], 'Unsupported verification must not enqueue delivery.');

$notRequiredProvider = new CF7AIVVerificationProvider($firstResult, $agreeResult);
[$notRequiredStore, $notRequiredQueue] = verification_run($notRequiredProvider, $policies, 'automatic_candidates', [], false);
verify_assert($notRequiredProvider->normalCalls === 1 && $notRequiredProvider->reasoningCalls === 0, 'Automatic verification must not run unless every ordinary auto-eligibility gate passes.');
verify_assert(($notRequiredStore->success['verificationState'] ?? '') === 'not_required' && $notRequiredQueue === [], 'A non-candidate must persist not_required and remain review-only.');

verify_assert(CF7AIV\Infrastructure\Settings::VERIFICATION_STATES === ['off', 'not_required', 'passed', 'disagreed', 'failed', 'unsupported'], 'Verification persistence must use the fixed diagnostic states.');

final class CF7AIVVerificationWpdb {
	public string $prefix = 'wp_';
	public function prepare(string $sql, mixed ...$arguments): string { return $sql; }
	public function query(string $sql): bool { return true; }
	public function update(string $table, array $data, array $where): bool { return true; }
}
$GLOBALS['wpdb'] = new CF7AIVVerificationWpdb();
$invalidStateRejected = false;
try {
	(new CF7AIV\Classification\WpClassificationItemStore())->succeed(
		999,
		$firstResult,
		['id' => 1, 'version' => 'agency-v2', 'compiled_prompt_hash' => str_repeat('a', 64)],
		new CF7AIV\Classification\EvidenceDecision('strong_match', []),
		'not_run',
		'review',
		false,
	);
} catch (InvalidArgumentException) {
	$invalidStateRejected = true;
}
verify_assert($invalidStateRejected, 'The WordPress item store must reject verification states outside the fixed diagnostic contract.');

// A second enquiry from the same person must not become a second automatic
// conversion. It goes to a person, marked as a possible duplicate.
$duplicateProvider = new CF7AIVVerificationProvider($firstResult, $agreeResult);
$seen = [];
[$duplicateStore, $duplicateQueue] = verification_run($duplicateProvider, $policies, 'automatic_candidates', ['email_hash' => str_repeat('a', 64)], true, 'live', static function (int $id, array $item) use (&$seen): bool { $seen[] = $item['email_hash'] ?? ''; return true; });
verify_assert($seen === [str_repeat('a', 64)], 'The duplicate check must be given the item and its stored hash.');
verify_assert(($duplicateStore->success['evidenceDecision']->state ?? '') === 'needs_review' && ($duplicateStore->success['reviewState'] ?? '') === 'review' && $duplicateQueue === [], 'A likely duplicate must go to review instead of automatic delivery.');
verify_assert(in_array('possible_duplicate', $duplicateStore->success['result']->ambiguityFlags, true), 'A likely duplicate must be flagged so the reviewer knows why.');
verify_assert($duplicateProvider->reasoningCalls === 0, 'A likely duplicate must not spend a verification call.');
$notChecked = 0;
[, $shadowQueue] = verification_run(new CF7AIVVerificationProvider($firstResult, $agreeResult), $policies, 'off', [], true, 'shadow', static function () use (&$notChecked): bool { $notChecked++; return true; });
verify_assert($notChecked === 0, 'The duplicate check only applies to enquiries that would be sent automatically.');

// The default check only counts earlier enquiries that were sent or due to be,
// and "earlier" is strict, so same-second enquiries cannot hold each other back.
final class CF7AIVDuplicateWpdb {
	public string $prefix = 'wp_'; public string $sql = '';
	public function prepare(string $sql, mixed ...$args): string { foreach ($args as $arg) { $sql = preg_replace('/%[ds]/', is_int($arg) ? (string) $arg : "'" . $arg . "'", $sql, 1) ?? $sql; } return $sql; }
	public function get_var(string $sql): mixed { $this->sql = $sql; return 1; }
}
$GLOBALS['wpdb'] = new CF7AIVDuplicateWpdb();
$recent = new ReflectionMethod(CF7AIV\Classification\ClassificationWorker::class, 'recentEnquiryFromSamePerson');
verify_assert($recent->invoke(null, 7, ['email_hash' => str_repeat('b', 64), 'phone_hash' => null, 'created_at' => '2026-10-12 09:00:00']) === true, 'A matching earlier enquiry must be reported.');
$duplicateSql = $GLOBALS['wpdb']->sql;
verify_assert(str_contains($duplicateSql, "(item.created_at<'2026-10-12 09:00:00' OR (item.created_at='2026-10-12 09:00:00' AND item.id<7))"), 'Only strictly earlier enquiries may count.');
verify_assert(str_contains($duplicateSql, "review_state IN ('eligible','approved_dispatch')") && str_contains($duplicateSql, "delivery.state IN ('delivered','sending','retrying','pending')"), 'Only enquiries sent, or due to be sent, as conversions may count.');
$GLOBALS['wpdb']->sql = '';
verify_assert($recent->invoke(null, 8, ['email_hash' => null, 'phone_hash' => null]) === false && $GLOBALS['wpdb']->sql === '', 'Without a stored hash nothing is looked up.');
unset($GLOBALS['wpdb']);

echo "PASS VerificationPassTest\n";
