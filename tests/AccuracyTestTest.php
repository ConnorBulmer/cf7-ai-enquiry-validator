<?php
/**
 * Accuracy test: re-running reviewed enquiries against a policy and AI setup.
 * Run: php tests/AccuracyTestTest.php
 */
declare(strict_types=1);

function accuracy_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException('FAIL: ' . $message); } }

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'accuracy-test-auth-key');
define('SECURE_AUTH_KEY', 'accuracy-test-secure-auth-key');
$GLOBALS['accuracy_options'] = [];
function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed { return $value; }
function plugin_dir_url(string $file): string { return 'https://example.test/plugin/'; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['accuracy_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, bool $autoload = false): bool { $GLOBALS['accuracy_options'][$name] = $value; return true; }
function as_enqueue_async_action(): int { return 1; }

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

use CF7AIV\Admin\AccuracyTest;
use CF7AIV\Domain\ClassificationRequest;
use CF7AIV\Domain\ClassificationResult;

$starter = CF7AIV\Classification\StarterPolicy::definition();
$original = $starter; $original['ai_whitelist'] = ['message'];
$policies = [
	1 => ['id' => 1, 'policy_key' => 'global', 'version' => 'live', 'status' => 'published', 'definition' => CF7AIV\Classification\PolicyValidator::import($original), 'compiled_prompt' => 'Production prompt.'],
	2 => ['id' => 2, 'policy_key' => 'global', 'version' => 'draft-2', 'status' => 'draft', 'definition' => CF7AIV\Classification\PolicyValidator::import($starter), 'compiled_prompt' => 'Draft prompt.'],
];
$reviewed = [
	['id' => 11, 'policy_id' => 1, 'predicted_tier_id' => 'care_information', 'predicted_outcome_id' => 'secondary_opportunity', 'reviewed_tier_id' => 'residential_care', 'reviewed_outcome_id' => 'primary_opportunity'],
	['id' => 12, 'policy_id' => 1, 'predicted_tier_id' => 'spam', 'predicted_outcome_id' => 'spam', 'reviewed_tier_id' => 'spam', 'reviewed_outcome_id' => 'spam'],
	['id' => 13, 'policy_id' => 1, 'predicted_tier_id' => 'spam', 'predicted_outcome_id' => 'spam', 'reviewed_tier_id' => 'spam', 'reviewed_outcome_id' => 'spam'],
];
final class AccuracyFakeProvider implements CF7AIV\Contracts\AIProviderInterface {
	/** @var list<ClassificationRequest> */ public array $requests = [];
	public function key(): string { return 'fake'; }
	public function classify(ClassificationRequest $request): ClassificationResult {
		$this->requests[] = $request;
		return $request->itemId === '11'
			? (new ClassificationResult('residential_care', 'qualified', 0.9, 'Care enquiry.', [], 'primary_opportunity'))->withProviderUsage(['input_tokens' => 100, 'output_tokens' => 20])
			: (new ClassificationResult('spam', 'spam', 0.9, 'Spam.', [], 'spam'))->withProviderUsage(['input_tokens' => 50, 'output_tokens' => 10]);
	}
}
$provider = new AccuracyFakeProvider();
$profiles = [];
$queued = [];
$askedKeys = [];
$enqueueWorks = true;
$service = new AccuracyTest(
	static function (int $limit, string $policyKey) use ($reviewed, &$askedKeys): array { $askedKeys[] = $policyKey; return array_slice($reviewed, 0, $limit); },
	static fn (int $id): ?array => $id === 13 ? null : ['id' => $id, 'policy_id' => 1, 'source_type' => 'test', 'source_reference' => 'r' . $id],
	static fn (int $id): ?array => $policies[$id] ?? null,
	static fn (array $item): ?array => ['message' => 'Enquiry ' . $item['id'] . ' about residential care.', 'subject' => 'Never sent before', 'your-email' => 'private@example.test'],
	static function (string $name, string $model, string $reasoning) use ($provider, &$profiles): CF7AIV\Contracts\AIProviderInterface { $profiles[] = [$name, $model, $reasoning]; return $provider; },
	static function (string $runId) use (&$queued, &$enqueueWorks): bool { $queued[] = $runId; return $enqueueWorks; },
);

// ─── Starting needs consent and something to test ───────────────────────────
$refused = false;
try { $service->start(['policy_id' => 2, 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'reasoning' => 'deep', 'size' => 25], 1); } catch (InvalidArgumentException) { $refused = true; }
accuracy_assert($refused, 'A test must not start without confirming that enquiries are sent again.');
$noItems = new AccuracyTest(static fn (int $limit, string $policyKey): array => [], null, static fn (int $id): ?array => $policies[$id] ?? null, null, null, static fn (string $id): bool => true);
$refused = false;
try { $noItems->start(['policy_id' => 2, 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'reasoning' => 'off', 'size' => 25, 'confirm' => '1'], 1); } catch (InvalidArgumentException $error) { $refused = str_contains($error->getMessage(), 'no reviewed enquiries'); }
accuracy_assert($refused, 'A test with nothing reviewed must say so.');

$run = $service->start(['policy_id' => 2, 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'reasoning' => 'deep', 'size' => 25, 'confirm' => '1'], 7);
accuracy_assert($run['state'] === 'running' && count($run['items']) === 3 && $queued === [$run['id']], 'Starting must snapshot the reviewed enquiries and queue the first job.');
accuracy_assert(end($askedKeys) === 'global', 'Only enquiries production classified under the same policy key may be used, since production chooses the policy by form.');
accuracy_assert(AccuracyTest::current()['id'] === $run['id'], 'The run must be stored.');

// ─── Each job classifies one enquiry and queues the next ────────────────────
$service->process($run['id']);
accuracy_assert(count($provider->requests) === 1 && count($queued) === 2, 'One job must classify one enquiry and queue the next.');
$context = $provider->requests[0]->context;
accuracy_assert(array_keys($context) === ['message'], 'Only fields the AI was already given when first classified may be sent: the draft\'s wider whitelist is narrowed to the original one.');
accuracy_assert($provider->requests[0]->systemPrompt === 'Draft prompt.', 'The tested policy\'s prompt must be used.');
accuracy_assert($profiles[0] === ['deepseek', 'deepseek-flash', 'deep'], 'The chosen provider, model and reasoning level must be used.');

// A superseded or unknown run does nothing.
$service->process('not-the-current-run');
accuracy_assert(count($provider->requests) === 1, 'A job for a replaced run must stop.');

$service->process($run['id']);
$service->process($run['id']);
$finished = AccuracyTest::current();
accuracy_assert($finished['state'] === 'complete' && $finished['completed_at'] !== '', 'The run must complete after its last enquiry.');
accuracy_assert(isset($finished['results']['13']['error']) && str_contains($finished['results']['13']['error'], 'deleted'), 'An enquiry that cannot be classified must record why and not stop the run.');
accuracy_assert(count($queued) === 3, 'No job may be queued after the last enquiry.');
$service->process($run['id']);
accuracy_assert(count($provider->requests) === 2, 'A complete run must not classify again.');

// ─── The report compares like with like ─────────────────────────────────────
$report = AccuracyTest::report($finished, [1 => $policies[1]['definition']], $policies[2]['definition']);
accuracy_assert($report['done'] === 3 && $report['total'] === 3 && count($report['errors']) === 1, 'The report must count progress and errors.');
accuracy_assert($report['baseline']['reviewed'] === 2 && $report['test']['reviewed'] === 2, 'An enquiry the test could not classify must be left out of both columns.');
accuracy_assert(abs($report['baseline']['exact_agreement'] - 0.5) < 0.0001 && $report['baseline']['missed_leads'] === 1, 'Production must be scored on its own predictions.');
accuracy_assert(abs($report['test']['exact_agreement'] - 1.0) < 0.0001 && $report['test']['missed_leads'] === 0, 'The test must be scored on its new predictions.');
accuracy_assert($report['tokens'] === 180, 'The report must total the tokens the test used.');

// ─── Queue refusal and cancellation ─────────────────────────────────────────
$enqueueWorks = false;
$stalled = $service->start(['policy_id' => 2, 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'reasoning' => 'deep', 'size' => 25, 'confirm' => '1'], 7);
accuracy_assert($stalled['state'] === 'stalled' && AccuracyTest::current()['state'] === 'stalled' && str_contains(AccuracyTest::current()['error'], 'queue refused'), 'A test the queue refuses must say so instead of waiting forever.');
$enqueueWorks = true;
$cancellable = $service->start(['policy_id' => 2, 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'reasoning' => 'nonsense', 'size' => 999, 'confirm' => '1'], 7);
accuracy_assert($cancellable['profile']['reasoning'] === 'off', 'An unknown reasoning level must fall back to a valid one.');
AccuracyTest::cancel();
accuracy_assert(AccuracyTest::current()['state'] === 'cancelled', 'A running test must be cancellable.');
$before = count($provider->requests);
$service->process($cancellable['id']);
accuracy_assert(count($provider->requests) === $before, 'A cancelled test must not classify again.');

echo "PASS AccuracyTestTest\n";
