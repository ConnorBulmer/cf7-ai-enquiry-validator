<?php
/**
 * Standalone isolated pipeline self-test tests.
 * Run: php tests/PipelineSelfTestTest.php
 */

declare(strict_types=1);

function cf7aiv_pipeline_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$GLOBALS['cf7aiv_test_actions'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['cf7aiv_test_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function do_action(string $hook, mixed ...$arguments): void {}
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed { return $value; }
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function as_enqueue_async_action(): int { return 1; }

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'pipeline-test-auth-key');
define('SECURE_AUTH_KEY', 'pipeline-test-secure-auth-key');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

final class CF7AIVPipelineProvider implements CF7AIV\Contracts\AIProviderInterface {
	public ?CF7AIV\Domain\ClassificationRequest $request = null;
	public function key(): string { return 'controlled-provider'; }
	public function classify(CF7AIV\Domain\ClassificationRequest $request): CF7AIV\Domain\ClassificationResult {
		$this->request = $request;
		return CF7AIV\Classification\ResultValidator::fromArray([
			'outcome_id' => 'primary_opportunity',
			'tier_id' => 'primary_opportunity',
			'reason_code' => 'qualified',
			'model_confidence' => 0.96,
			'evidence' => [
				['dimension' => 'service_fit', 'code' => 'service_fit', 'field_key' => 'message', 'excerpt' => 'customer acquisition strategy'],
				['dimension' => 'commercial_intent', 'code' => 'commercial_intent', 'field_key' => 'message', 'excerpt' => 'would like to book'],
				['dimension' => 'specificity', 'code' => 'specific_need', 'field_key' => 'message', 'excerpt' => 'customer acquisition strategy'],
				['dimension' => 'readiness', 'code' => 'readiness_signal', 'field_key' => 'message', 'excerpt' => 'next week'],
				['dimension' => 'requested_action', 'code' => 'requested_action', 'field_key' => 'message', 'excerpt' => 'book a discovery call'],
			],
			'missing_evidence' => [],
			'exclusion_codes' => [],
			'ambiguity_flags' => [],
			'summary' => 'Prospect requests a discovery call for a defined growth project.',
		], $request);
	}
}

$provider = new CF7AIVPipelineProvider();
$service = new CF7AIV\Integration\PipelineSelfTest($provider, static fn (): int => 1785405600);
$result = $service->run();
$serializedRequest = json_encode([
	'text' => $provider->request?->redactedText,
	'context' => $provider->request?->context,
], JSON_THROW_ON_ERROR);

cf7aiv_pipeline_assert(
	$result['successful'] === true
	&& $result['isolated'] === true
	&& $result['production_state_touched'] === false,
	'The synthetic pipeline must complete while remaining isolated from production state.',
);
cf7aiv_pipeline_assert(
	$provider->request instanceof CF7AIV\Domain\ClassificationRequest
	&& $provider->request->policySchema === CF7AIV\Classification\PolicyValidator::SCHEMA_V2
	&& array_keys($provider->request->context) === ['message', 'subject']
	&& ! str_contains($serializedRequest, 'Ada Private')
	&& ! str_contains($serializedRequest, 'ada@example.test')
	&& ! str_contains($serializedRequest, 'test-click-id'),
	'The real context builder must send only whitelisted, non-identifying fields to the AI boundary.',
);
cf7aiv_pipeline_assert(
	$result['classification']['tier_id'] === 'primary_opportunity'
	&& $result['classification']['outcome_id'] === 'primary_opportunity'
	&& $result['evidence_state'] === 'strong_match'
	&& $result['eligible'] === true,
	'The fixed schema, grounded evidence evaluator, and deterministic eligibility gates must accept the unambiguous synthetic opportunity.',
);
cf7aiv_pipeline_assert(
	$result['destination_calls'] === 1
	&& $result['delivery_records'] === 1
	&& $result['idempotent'] === true
	&& $result['envelope']['event_name'] === 'cf7aiv_pipeline_test'
	&& $result['envelope']['value'] === 0.0
	&& $result['envelope']['currency'] === 'GBP',
	'The real delivery worker must build and deliver one zero-value normalized event even when evaluated twice.',
);
$serializedEnvelope = json_encode($result['envelope'], JSON_THROW_ON_ERROR);
cf7aiv_pipeline_assert(
	! str_contains($serializedEnvelope, 'Ada Private')
	&& ! str_contains($serializedEnvelope, 'ada@example.test')
	&& ! str_contains($serializedEnvelope, 'customer acquisition strategy')
	&& ! str_contains($serializedEnvelope, 'test-click-id')
	&& $result['envelope']['consent']['ad_user_data'] === 'denied',
	'The conversion envelope must contain no enquiry text, raw identity, or click ID and must preserve denied consent.',
);

echo "PASS PipelineSelfTestTest\n";
