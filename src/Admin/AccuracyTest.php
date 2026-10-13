<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Classification\ClassificationHooks;
use CF7AIV\Classification\ContextBuilder;
use CF7AIV\Classification\PolicyValidator;
use CF7AIV\Classification\RequestFactory;
use CF7AIV\Contracts\AIProviderInterface;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Infrastructure\Settings;
use CF7AIV\Providers\OpenAIProvider;
use InvalidArgumentException;
use Throwable;

/**
 * Re-run enquiries people have already reviewed against a policy and AI setup,
 * and compare the result with what production predicted for the same enquiries.
 *
 * Without this, every change of policy, model or reasoning level was a guess
 * that could only be judged weeks later from new reviews. A test answers before
 * anything is published: same enquiries, same human verdicts, two sets of
 * predictions.
 *
 * Privacy: only reviewed live enquiries are used, and each is sent with no field
 * the AI was not already given when it was first classified - the tested
 * policy's whitelist is narrowed to the whitelist of the policy that originally
 * classified it.
 *
 * One run at a time. Its jobs form a chain, each classifying one enquiry and
 * queueing the next, so a single writer updates the run and no two jobs race
 * on it. Starting a new run supersedes the old one; its remaining jobs stop.
 */
final class AccuracyTest {
	public const OPTION = 'cf7aiv_accuracy_test';
	public const JOB = 'cf7aiv_accuracy_test_job';
	public const SIZES = [25, 50, 100];

	/** @var callable(int,string):list<array<string,mixed>> */ private $reviewedItems;
	/** @var callable(int):?array<string,mixed> */ private $itemLoader;
	/** @var callable(int):?array<string,mixed> */ private $policyLoader;
	/** @var callable(array<string,mixed>):?array<string,mixed> */ private $fieldLoader;
	/** @var callable(string,string,string):AIProviderInterface */ private $providerFactory;
	/** @var callable(string):bool */ private $enqueue;

	public function __construct(
		?callable $reviewedItems = null,
		?callable $itemLoader = null,
		?callable $policyLoader = null,
		?callable $fieldLoader = null,
		?callable $providerFactory = null,
		?callable $enqueue = null,
	) {
		$this->reviewedItems = $reviewedItems ?? self::loadReviewedItems(...);
		$this->itemLoader = $itemLoader ?? self::loadItem(...);
		$this->policyLoader = $policyLoader ?? self::loadPolicy(...);
		$this->fieldLoader = $fieldLoader ?? self::loadFields(...);
		$this->providerFactory = $providerFactory ?? self::buildProvider(...);
		$this->enqueue = $enqueue ?? static fn (string $runId): bool => Queue::accepted(Queue::enqueue(self::JOB, [$runId]));
	}

	/**
	 * @param array<string,mixed> $input policy_id, provider, model, reasoning, size, confirm
	 * @return array<string,mixed> The started run.
	 */
	public function start(array $input, int $administratorId): array {
		if (empty($input['confirm'])) {
			throw new InvalidArgumentException('Confirm that the test may send reviewed enquiries to the AI provider again.');
		}
		$policy = ($this->policyLoader)((int) ($input['policy_id'] ?? 0));
		if ($policy === null) { throw new InvalidArgumentException('Choose a saved draft or published policy to test.'); }
		$provider = (string) ($input['provider'] ?? '');
		if (! in_array($provider, ['deepseek', 'openai'], true)) { throw new InvalidArgumentException('Choose DeepSeek or OpenAI.'); }
		$model = substr((string) preg_replace('/[^a-zA-Z0-9._:\-]/', '', (string) ($input['model'] ?? '')), 0, 100);
		if ($model === '') { throw new InvalidArgumentException('Enter the model to test.'); }
		$reasoning = (string) ($input['reasoning'] ?? 'off');
		$options = $provider === 'openai' ? OpenAIProvider::reasoningOptions($model) : ['off', 'standard', 'deep'];
		if (! in_array($reasoning, $options, true)) { $reasoning = $options[0]; }
		$size = (int) ($input['size'] ?? 25);
		if (! in_array($size, self::SIZES, true)) { $size = self::SIZES[0]; }

		$items = [];
		foreach (($this->reviewedItems)($size, (string) ($policy['policy_key'] ?? '')) as $row) {
			$items[] = [
				'id' => (int) ($row['id'] ?? 0),
				'policy_id' => (int) ($row['policy_id'] ?? 0),
				'predicted_tier_id' => (string) ($row['predicted_tier_id'] ?? ''),
				'predicted_outcome_id' => (string) ($row['predicted_outcome_id'] ?? ''),
				'reviewed_tier_id' => (string) ($row['reviewed_tier_id'] ?? ''),
				'reviewed_outcome_id' => (string) ($row['reviewed_outcome_id'] ?? ''),
			];
		}
		$items = array_values(array_filter($items, static fn (array $item): bool => $item['id'] > 0 && $item['reviewed_tier_id'] !== '' && $item['reviewed_outcome_id'] !== ''));
		if ($items === []) { throw new InvalidArgumentException('There are no reviewed enquiries classified under this policy key yet. Review some in the Review Queue first.'); }

		$run = [
			'id' => bin2hex(random_bytes(8)),
			'state' => 'running',
			'administrator_id' => $administratorId,
			'started_at' => gmdate(DATE_ATOM),
			'completed_at' => '',
			'policy' => ['id' => (int) $policy['id'], 'key' => (string) ($policy['policy_key'] ?? ''), 'version' => (string) ($policy['version'] ?? ''), 'status' => (string) ($policy['status'] ?? '')],
			'profile' => ['provider' => $provider, 'model' => $model, 'reasoning' => $reasoning],
			'items' => $items,
			'next' => 0,
			'results' => [],
			'error' => '',
		];
		self::write($run);
		if (! ($this->enqueue)($run['id'])) {
			$run['state'] = 'stalled';
			$run['error'] = 'The background queue refused the job, so the test did not start. Check Tools > Scheduled Actions, then try again.';
			self::write($run);
		}
		return $run;
	}

	/** Classify the next enquiry of a run, then queue the one after. */
	public function process(string $runId): void {
		$run = self::current();
		if ($run === null || ($run['id'] ?? '') !== $runId || ($run['state'] ?? '') !== 'running') { return; }
		$index = (int) ($run['next'] ?? 0);
		$snapshot = $run['items'][$index] ?? null;
		if (! is_array($snapshot)) {
			$run['state'] = 'complete';
			$run['completed_at'] = gmdate(DATE_ATOM);
			self::write($run);
			return;
		}
		$outcome = $this->classifyOne((int) $snapshot['id'], (int) $run['policy']['id'], (array) $run['profile']);

		// Re-read past the request cache: a new run or a cancel may have landed
		// in another request while the call was out, and writing back the copy
		// read before the call would undo it.
		$latest = self::current(true);
		if ($latest === null || ($latest['id'] ?? '') !== $runId || ($latest['state'] ?? '') !== 'running') { return; }
		$latest['results'][(string) $snapshot['id']] = $outcome;
		$latest['next'] = $index + 1;
		$done = $latest['next'] >= count((array) $latest['items']);
		if ($done) {
			$latest['state'] = 'complete';
			$latest['completed_at'] = gmdate(DATE_ATOM);
		}
		self::write($latest);
		if (! $done && ! ($this->enqueue)($runId)) {
			$latest['state'] = 'stalled';
			$latest['error'] = 'The background queue refused the next job. Start the test again to finish it.';
			self::write($latest);
		}
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed> tier/outcome, or error.
	 */
	private function classifyOne(int $itemId, int $policyId, array $profile): array {
		try {
			$item = ($this->itemLoader)($itemId);
			if ($item === null) { return ['error' => 'The enquiry has been deleted.']; }
			$original = ($this->policyLoader)((int) ($item['policy_id'] ?? 0));
			$tested = ($this->policyLoader)($policyId);
			if ($original === null || $tested === null) { return ['error' => 'A policy needed for this enquiry is no longer available.']; }
			$fields = ($this->fieldLoader)($item);
			if ($fields === null) { return ['error' => 'The enquiry is no longer available from its source.']; }
			$whitelist = array_values(array_intersect(
				ContextBuilder::safeWhitelist((array) ($tested['definition']['ai_whitelist'] ?? [])),
				ContextBuilder::safeWhitelist((array) ($original['definition']['ai_whitelist'] ?? [])),
			));
			$request = RequestFactory::build((string) $itemId, (string) ($tested['version'] ?? ''), $tested['definition'], (string) ($tested['compiled_prompt'] ?? ''), $fields, $whitelist);
			$result = ($this->providerFactory)((string) $profile['provider'], (string) $profile['model'], (string) $profile['reasoning'])->classify($request);
			$usage = $result->providerUsage;
			return [
				'tier' => $result->tierId,
				'outcome' => $result->outcomeId,
				'tokens' => (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0),
			];
		} catch (Throwable $error) {
			return ['error' => DiagnosticsService::describeFailure($error)];
		}
	}

	public static function cancel(): void {
		$run = self::current();
		if ($run === null || ($run['state'] ?? '') !== 'running') { return; }
		$run['state'] = 'cancelled';
		$run['completed_at'] = gmdate(DATE_ATOM);
		self::write($run);
	}

	/** @return array<string,mixed>|null */
	public static function current(bool $fresh = false): ?array {
		if (! function_exists('get_option')) { return null; }
		if ($fresh && function_exists('wp_cache_delete')) { wp_cache_delete(self::OPTION, 'options'); }
		$run = get_option(self::OPTION, null);
		return is_array($run) && isset($run['id'], $run['state']) ? $run : null;
	}

	/**
	 * Production's predictions and the test's, scored against the same human
	 * verdicts on the same enquiries. An enquiry the test could not classify is
	 * left out of both, so neither side is scored on enquiries the other skipped.
	 *
	 * @param array<string,mixed> $run
	 * @param array<int,array<string,mixed>> $definitions Original policy definitions by id.
	 * @param array<string,mixed> $testedDefinition
	 * @return array{done:int,total:int,errors:list<string>,tokens:int,baseline:array<string,mixed>,test:array<string,mixed>}
	 */
	public static function report(array $run, array $definitions, array $testedDefinition): array {
		$baselineRows = [];
		$testRows = [];
		$errors = [];
		$tokens = 0;
		$results = (array) ($run['results'] ?? []);
		foreach ((array) ($run['items'] ?? []) as $item) {
			$result = $results[(string) ($item['id'] ?? '')] ?? null;
			if (! is_array($result)) { continue; }
			if (isset($result['error'])) { $errors[] = (string) $result['error']; continue; }
			$tokens += (int) ($result['tokens'] ?? 0);
			$verdict = ['reviewed_tier_id' => $item['reviewed_tier_id'], 'reviewed_outcome_id' => $item['reviewed_outcome_id'], 'reviewed_at' => (string) ($run['started_at'] ?? 'reviewed')];
			$baselineRows[] = $verdict + ['predicted_tier_id' => $item['predicted_tier_id'], 'predicted_outcome_id' => $item['predicted_outcome_id'], 'policy_snapshot' => $definitions[(int) $item['policy_id']] ?? []];
			$testRows[] = $verdict + ['predicted_tier_id' => (string) ($result['tier'] ?? ''), 'predicted_outcome_id' => (string) ($result['outcome'] ?? ''), 'policy_snapshot' => $testedDefinition];
		}
		return [
			'done' => count($results),
			'total' => count((array) ($run['items'] ?? [])),
			'errors' => $errors,
			'tokens' => $tokens,
			'baseline' => CalibrationMetrics::fromRows($baselineRows),
			'test' => CalibrationMetrics::fromRows($testRows),
		];
	}

	/** Queue entry point. */
	public static function runJob(mixed $runId): void {
		if (is_string($runId) && $runId !== '') { (new self())->process($runId); }
	}

	/** @param array<string,mixed> $run */
	private static function write(array $run): void {
		if (function_exists('update_option')) { update_option(self::OPTION, $run, false); }
	}

	/**
	 * Reviewed live enquiries that production classified under the same policy
	 * key - "global", or one form's own policy - since production chooses the
	 * policy by form. Testing a global draft against a form that has its own
	 * policy would score a pairing production never makes.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function loadReviewedItems(int $limit, string $policyKey): array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_results')) { return []; }
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT item.id,item.policy_id,item.predicted_tier_id,item.predicted_outcome_id,item.reviewed_tier_id,item.reviewed_outcome_id FROM {$wpdb->prefix}cf7aiv_items item INNER JOIN {$wpdb->prefix}cf7aiv_policies policy ON policy.id=item.policy_id WHERE policy.policy_key=%s AND item.reviewed_at IS NOT NULL AND item.reviewed_tier_id IS NOT NULL AND item.reviewed_outcome_id IS NOT NULL AND item.predicted_tier_id IS NOT NULL AND item.is_calibration=0 AND item.is_historical=0 ORDER BY item.reviewed_at DESC LIMIT %d",
			$policyKey,
			$limit
		), ARRAY_A);
		return is_array($rows) ? $rows : [];
	}

	/** @return array<string,mixed>|null */
	private static function loadItem(int $id): ?array {
		global $wpdb;
		if (! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_row')) { return null; }
		$row = $wpdb->get_row($wpdb->prepare("SELECT id,policy_id,source_type,source_reference FROM {$wpdb->prefix}cf7aiv_items WHERE id=%d", $id), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	/** @return array<string,mixed>|null A policy row with its validated definition. */
	public static function loadPolicy(int $id): ?array {
		global $wpdb;
		if ($id <= 0 || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_row')) { return null; }
		$row = $wpdb->get_row($wpdb->prepare("SELECT id,policy_key,version,status,definition_json,compiled_prompt FROM {$wpdb->prefix}cf7aiv_policies WHERE id=%d AND status IN ('draft','published','superseded')", $id), ARRAY_A);
		if (! is_array($row)) { return null; }
		try {
			$row['definition'] = PolicyValidator::import((string) ($row['definition_json'] ?? ''));
		} catch (Throwable) {
			return null;
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>|null
	 */
	private static function loadFields(array $item): ?array {
		return ReviewContext::sourceFields($item);
	}

	private static function buildProvider(string $provider, string $model, string $reasoning): AIProviderInterface {
		$settings = Settings::withDefaults(function_exists('get_option') ? (array) get_option(Settings::OPTION, Settings::defaults()) : []);
		$providerSettings = (array) $settings['providers'];
		$providerSettings[$provider . '_model'] = $model;
		$providerSettings[$provider . '_reasoning_effort'] = $reasoning;
		return ClassificationHooks::buildProvider($provider, $providerSettings);
	}
}
