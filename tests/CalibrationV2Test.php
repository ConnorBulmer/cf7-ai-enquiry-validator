<?php
/**
 * Standalone stratified calibration tests. Run: php tests/CalibrationV2Test.php
 */

declare(strict_types=1);

function calibration_assert(bool $condition, string $message): void {
	if (! $condition) { throw new RuntimeException($message); }
}

$GLOBALS['calibration_actions'] = [];
$GLOBALS['calibration_filters'] = [];
$GLOBALS['calibration_posts'] = [];
$GLOBALS['calibration_async'] = [];

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['calibration_actions'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void { $GLOBALS['calibration_filters'][$hook][] = [$callback, $priority, $acceptedArgs]; }
function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed {
	foreach ($GLOBALS['calibration_filters'][$hook] ?? [] as [$callback]) { $value = $callback($value, ...$arguments); }
	return $value;
}
function plugin_dir_url(string $file): string { return 'https://example.test/wp-content/plugins/cf7-ai-enquiry-validator/'; }
function get_post(int $id): ?object { return $GLOBALS['calibration_posts'][$id] ?? null; }
function as_enqueue_async_action(string $hook = '', array $arguments = [], string $group = '', bool $unique = false): int { $GLOBALS['calibration_async'][] = compact('hook', 'arguments'); return count($GLOBALS['calibration_async']); }
function current_user_can(string $capability): bool { return true; }
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'nonce'; }
function get_current_user_id(): int { return 77; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function wp_safe_redirect(string $url): bool { return true; }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="nonce">'; }
function get_post_stati(array $arguments = [], string $output = 'names'): array {
	if (($arguments['exclude_from_search'] ?? null) === true) { return ['inherit', 'trash', 'auto-draft', 'flamingo-spam']; }
	return ['publish', 'inherit', 'trash', 'auto-draft', 'flamingo-spam'];
}

final class WP_CLI {
	public static array $commands = [];
	public static array $messages = [];
	public static function add_command(string $name, mixed $command): void { self::$commands[$name] = $command; }
	public static function log(string $message): void { self::$messages[] = $message; }
	public static function success(string $message): void { self::$messages[] = 'SUCCESS: ' . $message; }
	public static function warning(string $message): void { self::$messages[] = 'WARNING: ' . $message; }
	public static function error(string $message): never { throw new RuntimeException($message); }
}

final class Flamingo_Inbound_Message {
	public static array $findArguments = [];
	public static array $messages = [];
	public static bool $enforceConstraints = false;
	public array $fields;
	public array $meta;
	public function __construct(private int $messageId) {
		$data = self::$messages[$messageId] ?? ['fields' => [], 'meta' => []];
		$this->fields = $data['fields'];
		$this->meta = $data['meta'];
	}
	public function id(): int { return $this->messageId; }
	public static function find(array $arguments): array {
		self::$findArguments[] = $arguments;
		$ids = array_keys(self::$messages);
		sort($ids, SORT_NUMERIC);
		if (self::$enforceConstraints) {
			$ids = array_values(array_filter($ids, static function (int $id) use ($arguments): bool {
				if (isset($arguments['post__in']) && ! in_array($id, (array) $arguments['post__in'], true)) { return false; }
				if (($arguments['post_status'] ?? '') === 'any' && in_array((string) (self::$messages[$id]['status'] ?? 'publish'), ['inherit', 'trash', 'auto-draft', 'flamingo-spam'], true)) { return false; }
				if (isset($arguments['meta_query'])) {
					$formId = (int) (self::$messages[$id]['fields']['_wpcf7'] ?? 0);
					$matches = false;
					foreach ($arguments['meta_query'] as $key => $clause) {
						if ($key === 'relation' || ! is_array($clause)) { continue; }
						if (($clause['key'] ?? '') === '_field__wpcf7' && in_array($formId, (array) ($clause['value'] ?? []), true)) { $matches = true; }
						if (($clause['key'] ?? '') === '_meta' && ($clause['compare'] ?? '') === 'EXISTS' && array_key_exists('meta', self::$messages[$id])) { $matches = true; }
					}
					if (! $matches) { return false; }
				}
				if (isset($arguments['date_query'][0])) {
					$date = (string) ($GLOBALS['calibration_posts'][$id]->post_date_gmt ?? '');
					$query = $arguments['date_query'][0];
					if ($date < (string) ($query['after'] ?? '') || $date > (string) ($query['before'] ?? '')) { return false; }
				}
				return true;
			}));
		}
		$ids = array_slice($ids, max(0, (int) ($arguments['offset'] ?? 0)), max(1, (int) ($arguments['posts_per_page'] ?? 100)));
		return array_map(static fn (int $id): self => new self($id), $ids);
	}
}

final class FlamingoQueryWpdb {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public array $prepared = [];
	public array $idPages = [];
	public array $rows = [];
	public int $getColCalls = 0;
	public function prepare(string $sql, mixed ...$arguments): string { $this->prepared[] = [$sql, $arguments]; return $sql; }
	public function get_col(string $sql): array {
		$this->getColCalls++;
		if ($this->idPages !== []) { return array_shift($this->idPages) ?? []; }
		[, $arguments] = $this->prepared[array_key_last($this->prepared)];
		$afterId = (int) ($arguments[1] ?? 0);
		$limit = (int) ($arguments[count($arguments) - 2] ?? 100);
		$offset = (int) ($arguments[count($arguments) - 1] ?? 0);
		$ids = [];
		foreach ($this->rows as $id => $status) {
			if ((int) $id > $afterId && ! in_array($status, $arguments, true)) { $ids[] = (int) $id; }
		}
		sort($ids, SORT_NUMERIC);
		return array_slice($ids, $offset, $limit);
	}
}

define('ARRAY_A', 'ARRAY_A');
define('WP_CLI', true);
define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'calibration-test-auth-key');
define('SECURE_AUTH_KEY', 'calibration-test-secure-auth-key');

require_once dirname(__DIR__) . '/cf7-ai-enquiry-validator.php';

$plan = CF7AIV\Admin\CalibrationPlanner::plan([20, 10, 20], '2025-01-01', '2026-07-22', 25);
calibration_assert(array_sum(array_column($plan, 'limit')) === 25, 'Strata must allocate exactly the requested maximum.');
calibration_assert(count(array_unique(array_column($plan, 'form_id'))) === 2, 'Selected forms must be represented.');
calibration_assert($plan === CF7AIV\Admin\CalibrationPlanner::plan([10, 20], '2025-01-01', '2026-07-22', 25), 'Planning must be deterministic regardless of duplicate or unordered form input.');
calibration_assert(count(array_unique(array_map(static fn (array $row): string => $row['date_from'] . '|' . $row['date_to'], $plan))) === 3, 'Long ranges must split into three chronological date buckets.');
calibration_assert(max(array_column($plan, 'limit')) - min(array_column($plan, 'limit')) <= 1, 'Quota allocation must differ by no more than one per stratum.');
$smallPlan = CF7AIV\Admin\CalibrationPlanner::plan([], '2026-07-21', '2026-07-22', 1);
calibration_assert(count($smallPlan) === 1 && $smallPlan[0]['form_id'] === null && $smallPlan[0]['limit'] === 1, 'A form-agnostic plan must drop zero-quota date strata.');

$queued = [];
$availableSource = new class implements CF7AIV\Contracts\SubmissionSourceInterface {
	public function key(): string { return 'flamingo'; }
	public function get(string $reference): ?array { return null; }
	public function iterate(array $arguments = []): iterable { return []; }
	public function available(): bool { return true; }
};
$service = new CF7AIV\Admin\CalibrationService(
	static fn (): array => ['flamingo' => $availableSource],
	static fn (): array => [10, 20],
	static function (string $hook, array $arguments) use (&$queued): int { $queued[] = compact('hook', 'arguments'); return count($queued); },
	static fn (): string => 'calibration-run-fixed'
);
$scheduled = $service->schedule('flamingo', [
	'explicit_opt_in' => true,
	'form_ids' => [10, 20],
	'date_from' => '2025-01-01',
	'date_to' => '2026-07-22',
	'maximum' => 25,
	'ai_whitelist_snapshot' => ['subject', 'message'],
	'whitelist_fingerprint' => CF7AIV\Admin\CalibrationPolicySnapshot::fingerprint(['subject', 'message']),
], 77);
calibration_assert($scheduled === 25, 'Default calibration must schedule 25 records.');
calibration_assert(array_sum(array_map(static fn (array $action): int => (int) $action['arguments'][2], $queued)) === 25, 'Queued stratum quotas must sum to the scheduled maximum.');
foreach ($queued as $action) {
	$criteria = $action['arguments'][1] ?? [];
	calibration_assert($action['hook'] === 'cf7aiv_backfill_batch' && $action['arguments'][0] === 'flamingo', 'Every stratum must use the canonical calibration worker and selected source.');
	calibration_assert(($criteria['calibration_run_id'] ?? '') === 'calibration-run-fixed', 'Every stratum must carry one immutable calibration run ID.');
	calibration_assert(isset($criteria['date_from'], $criteria['date_to'], $criteria['quota']) && is_array($criteria['form_ids'] ?? null), 'Every stratum must carry bounded form/date criteria and its exact quota.');
	calibration_assert(($criteria['ai_whitelist_snapshot'] ?? null) === ['message', 'subject'] && ($criteria['whitelist_fingerprint'] ?? '') !== '', 'Every stratum must carry the normalized whitelist snapshot explicitly authorized for that run.');
	calibration_assert((int) $criteria['quota'] === (int) $action['arguments'][2], 'The immutable stratum quota must match the initial worker quota.');
}

foreach ([
	['criteria' => ['explicit_opt_in' => false, 'maximum' => 25], 'message' => 'Calibration must reject a request without explicit opt-in.'],
	['criteria' => ['explicit_opt_in' => true, 'date_from' => '2026-08-01', 'date_to' => '2026-07-01', 'maximum' => 25], 'message' => 'Calibration must reject inverted dates.'],
	['criteria' => ['explicit_opt_in' => true, 'form_ids' => [999], 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'maximum' => 25], 'message' => 'Calibration must reject unknown form IDs.'],
	['criteria' => ['explicit_opt_in' => true, 'form_ids' => ['10junk'], 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'maximum' => 25], 'message' => 'Calibration must reject malformed form IDs instead of broadening scope.'],
	['criteria' => ['explicit_opt_in' => true, 'maximum' => 0], 'message' => 'Calibration must reject a maximum outside 1..500.'],
] as $case) {
	$refused = false;
	try { $service->schedule('flamingo', $case['criteria'], 77); } catch (InvalidArgumentException) { $refused = true; }
	calibration_assert($refused, $case['message']);
}
$unavailable = new CF7AIV\Admin\CalibrationService(static fn (): array => [], static fn (): array => [10], static fn (): int => 1);
$refused = false;
try { $unavailable->schedule('flamingo', ['explicit_opt_in' => true, 'maximum' => 25], 77); } catch (InvalidArgumentException) { $refused = true; }
calibration_assert($refused, 'Calibration must reject an unavailable source.');

$GLOBALS['calibration_async'] = [];
$webWhitelistFingerprint = CF7AIV\Admin\CalibrationPolicySnapshot::active()['fingerprint'];
$_POST = ['_wpnonce' => 'nonce', 'source' => 'flamingo', 'explicit_opt_in' => '1', 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'whitelist_fingerprint' => $webWhitelistFingerprint];
CF7AIV\Admin\AdminHooks::backfill();
unset($_POST);
calibration_assert(array_sum(array_map(static fn (array $action): int => (int) ($action['arguments'][2] ?? 0), $GLOBALS['calibration_async'])) === 25, 'The web calibration action must default to an exact maximum of 25.');
$webRefused = false;
try {
	$_POST = ['_wpnonce' => 'nonce', 'source' => 'flamingo', 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'whitelist_fingerprint' => $webWhitelistFingerprint];
	CF7AIV\Admin\AdminHooks::backfill();
} catch (InvalidArgumentException) { $webRefused = true; }
unset($_POST);
calibration_assert($webRefused, 'The web calibration action must reject a missing explicit opt-in.');

$command = new CF7AIV\Cli\Commands();
$explicitCli = ['source' => 'flamingo', 'date-from' => '2026-07-01', 'date-to' => '2026-07-22', 'maximum' => 7, 'explicit-opt-in' => true];
foreach (['source', 'date-from', 'date-to', 'maximum', 'explicit-opt-in'] as $requiredCliArgument) {
	$cliRefused = false;
	$GLOBALS['calibration_async'] = [];
	$incompleteCli = $explicitCli;
	unset($incompleteCli[$requiredCliArgument]);
	try { $command->calibrate([], $incompleteCli); } catch (RuntimeException) { $cliRefused = true; }
	calibration_assert($cliRefused && $GLOBALS['calibration_async'] === [], "CLI calibration must require explicit {$requiredCliArgument} without scheduling.");
}
$GLOBALS['calibration_async'] = [];
$command->backfill([], $explicitCli);
calibration_assert(array_sum(array_map(static fn (array $action): int => (int) ($action['arguments'][2] ?? 0), $GLOBALS['calibration_async'])) === 7, 'The legacy CLI backfill alias must remain calibration-only and use the explicit exact maximum.');

final class CalibrationPagedSource implements CF7AIV\Contracts\SubmissionSourceInterface {
	public array $iterations = [];
	public function key(): string { return 'paged'; }
	public function get(string $reference): ?array { return null; }
	public function iterate(array $arguments = []): iterable {
		$this->iterations[] = $arguments;
		$after = (int) ($arguments['after_id'] ?? 0);
		$limit = (int) ($arguments['limit'] ?? 1);
		for ($id = $after + 1; $id <= $after + $limit; $id++) {
			yield ['reference' => (string) $id, 'form_id' => 10, 'fields' => ['email' => "private{$id}@example.test", 'message' => 'PRIVATE']];
		}
	}
}
$persisted = [];
$workerQueue = [];
$pagedSource = new CalibrationPagedSource();
$backfill = new CF7AIV\Admin\BackfillService(
	static function (array $item) use (&$persisted): int { $persisted[] = $item; return count($persisted); },
	static function (string $hook, array $arguments) use (&$workerQueue): int { $workerQueue[] = compact('hook', 'arguments'); return count($workerQueue); }
);
$processed = $backfill->run($pagedSource, [
	'form_ids' => [10], 'date_from' => '2026-01-01', 'date_to' => '2026-07-22',
	'calibration_run_id' => 'run-boundary', 'quota' => 125, 'ai_whitelist_snapshot' => ['message'],
], 125);
calibration_assert($processed === 100 && count($persisted) === 100, 'A worker action must process only the bounded async page.');
foreach ($persisted as $item) {
	calibration_assert(($item['is_calibration'] ?? null) === true && ($item['is_historical'] ?? null) === true, 'Every persisted calibration item must carry permanent calibration and historical flags.');
	calibration_assert(array_key_exists('attribution_encrypted', $item) && $item['attribution_encrypted'] === null && array_key_exists('consent_encrypted', $item) && $item['consent_encrypted'] === null && ($item['attribution_ready'] ?? true) === false, 'Calibration items must not persist attribution or consent.');
	calibration_assert(array_key_exists('email_hash', $item) && $item['email_hash'] === null && array_key_exists('phone_hash', $item) && $item['phone_hash'] === null, 'Calibration items must not persist identity hashes.');
	calibration_assert(! isset($item['fields'], $item['email'], $item['phone'], $item['attribution']), 'Calibration items must not persist raw, identity, or attribution fields.');
}
$continuations = array_values(array_filter($workerQueue, static fn (array $entry): bool => $entry['hook'] === 'cf7aiv_backfill_batch'));
calibration_assert(count($continuations) === 1 && $continuations[0]['arguments'][2] === 25, 'Continuation must receive only the remaining stratum quota.');
calibration_assert(($continuations[0]['arguments'][1]['calibration_run_id'] ?? '') === 'run-boundary' && ($continuations[0]['arguments'][1]['quota'] ?? 0) === 125, 'Continuation must preserve the immutable run ID and original stratum quota.');
$classificationActions = array_values(array_filter($workerQueue, static fn (array $entry): bool => $entry['hook'] === 'cf7aiv_classify_item'));
calibration_assert(($classificationActions[0]['arguments'] ?? null) === [1, false, ['message']], 'Historical classification must receive the exact whitelist snapshot authorized for its calibration run.');
calibration_assert(! in_array('cf7aiv_evaluate_delivery', array_column($workerQueue, 'hook'), true), 'Calibration must never enqueue delivery evaluation.');
$tamperedPersisted = [];
$tamperedWorker = new CF7AIV\Admin\BackfillService(static function (array $item) use (&$tamperedPersisted): int { $tamperedPersisted[] = $item; return count($tamperedPersisted); }, static fn (): int => 1);
$tamperedCount = $tamperedWorker->run(new CalibrationPagedSource(), ['calibration_run_id' => 'run-tampered', 'quota' => 25], 500);
calibration_assert($tamperedCount === 25 && count($tamperedPersisted) === 25, 'A mutable worker argument must never raise the immutable stratum quota.');
$legacyPersisted = [];
$legacyWorker = new CF7AIV\Admin\BackfillService(static function (array $item) use (&$legacyPersisted): int { $legacyPersisted[] = $item; return count($legacyPersisted); }, static fn (): int => 1);
calibration_assert($legacyWorker->run(new CalibrationPagedSource(), [], 25) === 0 && $legacyPersisted === [], 'A legacy broad worker action without calibration run and quota must fail closed.');
$failureQueue = [];
$failureAttempts = [];
$failureWorker = new CF7AIV\Admin\BackfillService(
	static function (array $item) use (&$failureAttempts): int {
		$reference = (int) $item['source_reference'];
		$failureAttempts[] = $reference;
		return $reference % 2 === 0 ? $reference : 0;
	},
	static function (string $hook, array $arguments) use (&$failureQueue): int { $failureQueue[] = compact('hook', 'arguments'); return count($failureQueue); }
);
$failureSuccesses = $failureWorker->run(new CalibrationPagedSource(), ['calibration_run_id' => 'run-failures', 'quota' => 125, 'completed' => 0], 125);
$failureContinuations = array_values(array_filter($failureQueue, static fn (array $entry): bool => $entry['hook'] === 'cf7aiv_backfill_batch'));
calibration_assert($failureSuccesses === 50 && count($failureAttempts) === 100, 'Persistence failures must not consume successful quota, while each action remains bounded to 100 scanned rows.');
calibration_assert(count($failureContinuations) === 1 && $failureContinuations[0]['arguments'][2] === 75, 'A persistence-failure continuation must request only the remaining successful quota.');
calibration_assert(($failureContinuations[0]['arguments'][1]['after_id'] ?? 0) === 100 && ($failureContinuations[0]['arguments'][1]['completed'] ?? 0) === 50, 'Continuation must advance past all scanned IDs and retain cumulative successful progress.');

Flamingo_Inbound_Message::$messages = [
	1 => ['fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
	2 => ['fields' => ['_wpcf7' => 20], 'meta' => ['post_id' => 20]],
	3 => ['fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
];
Flamingo_Inbound_Message::$enforceConstraints = true;
$flamingoWpdb = new FlamingoQueryWpdb();
$flamingoWpdb->idPages = [[1, 2], [4, 5]];
$GLOBALS['wpdb'] = $flamingoWpdb;
$GLOBALS['calibration_posts'] = [
	1 => (object) ['post_date_gmt' => '2026-07-20 10:00:00'],
	2 => (object) ['post_date_gmt' => '2026-07-20 11:00:00'],
	3 => (object) ['post_date_gmt' => '2026-06-01 12:00:00'],
];
$flamingoRecords = iterator_to_array((new CF7AIV\Sources\FlamingoSource())->iterate([
	'form_ids' => [10], 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'after_id' => 0, 'offset' => 0, 'limit' => 2,
]));
$flamingoArgs = Flamingo_Inbound_Message::$findArguments[0] ?? [];
calibration_assert(isset($flamingoArgs['meta_query'], $flamingoArgs['date_query']) && ($flamingoArgs['order'] ?? '') === 'ASC', 'Flamingo find arguments must include form/date constraints and stable ascending IDs.');
calibration_assert(($flamingoArgs['meta_query']['relation'] ?? '') === 'OR' && ($flamingoArgs['meta_query'][0]['key'] ?? '') === '_field__wpcf7' && ($flamingoArgs['meta_query'][1]['key'] ?? '') === '_meta' && ($flamingoArgs['meta_query'][1]['compare'] ?? '') === 'EXISTS', 'Flamingo form filtering must use the real persisted CF7 field key and admit serialized meta only for bounded post-filtering.');
calibration_assert(($flamingoArgs['post__in'] ?? []) === [1, 2] && count(Flamingo_Inbound_Message::$findArguments) === 1 && $flamingoWpdb->getColCalls === 1, 'Flamingo must inspect one bounded preselected ID page per invocation.');
calibration_assert(array_column($flamingoRecords, 'reference') === ['1'], 'Flamingo must post-filter the bounded page when its find layer does not enforce constraints.');
Flamingo_Inbound_Message::$enforceConstraints = true;
Flamingo_Inbound_Message::$messages = [
	4 => ['fields' => [], 'meta' => ['post_id' => 10]],
	5 => ['fields' => [], 'meta' => ['post_id' => 20]],
];
$GLOBALS['calibration_posts'] = [
	4 => (object) ['post_date_gmt' => '2026-07-21 10:00:00'],
	5 => (object) ['post_date_gmt' => '2026-07-21 11:00:00'],
];
$flamingoFallback = iterator_to_array((new CF7AIV\Sources\FlamingoSource())->iterate([
	'form_ids' => [10], 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'after_id' => 2, 'offset' => 0, 'limit' => 2,
]));
calibration_assert(array_column($flamingoFallback, 'reference') === ['4'], 'Serialized Flamingo _meta form IDs must be handled by bounded page post-filtering.');
calibration_assert((Flamingo_Inbound_Message::$findArguments[1]['post__in'] ?? []) === [4, 5] && count(Flamingo_Inbound_Message::$findArguments) === 2 && $flamingoWpdb->getColCalls === 2, 'A later Flamingo continuation must perform exactly one new bounded ID page query.');
$secondFlamingoSql = $flamingoWpdb->prepared[1] ?? ['', []];
calibration_assert(str_contains($secondFlamingoSql[0], 'ID > %d') && in_array(2, $secondFlamingoSql[1], true), 'Flamingo continuation must constrain stable IDs in parameterized SQL rather than an ignored find argument.');

$statusWpdb = new FlamingoQueryWpdb();
$statusWpdb->rows = [1 => 'trash', 2 => 'flamingo-spam', 3 => 'auto-draft', 4 => 'publish', 5 => 'publish', 6 => 'publish'];
$GLOBALS['wpdb'] = $statusWpdb;
Flamingo_Inbound_Message::$findArguments = [];
Flamingo_Inbound_Message::$messages = [
	1 => ['status' => 'trash', 'fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
	2 => ['status' => 'flamingo-spam', 'fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
	3 => ['status' => 'auto-draft', 'fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
	4 => ['status' => 'publish', 'fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
	5 => ['status' => 'publish', 'fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
	6 => ['status' => 'publish', 'fields' => ['_wpcf7' => 10], 'meta' => ['post_id' => 10]],
];
$GLOBALS['calibration_posts'] = [];
foreach (array_keys(Flamingo_Inbound_Message::$messages) as $messageId) { $GLOBALS['calibration_posts'][$messageId] = (object) ['post_date_gmt' => '2026-07-21 10:00:00']; }
$statusPage = iterator_to_array((new CF7AIV\Sources\FlamingoSource())->iterate(['form_ids' => [10], 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'after_id' => 0, 'offset' => 0, 'limit' => 2]));
calibration_assert(array_column($statusPage, 'reference') === ['4', '5'], 'Excluded Flamingo statuses must not consume the bounded publish-plus-sentinel candidate page.');
$laterStatusPage = iterator_to_array((new CF7AIV\Sources\FlamingoSource())->iterate(['form_ids' => [10], 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'after_id' => 4, 'offset' => 0, 'limit' => 2]));
calibration_assert(array_column($laterStatusPage, 'reference') === ['5', '6'], 'Later publish rows must remain visible to stable continuation after excluded statuses.');
calibration_assert(count(Flamingo_Inbound_Message::$findArguments) === 2 && $statusWpdb->getColCalls === 2, 'Status-aligned Flamingo pages must remain one bounded ID query and one bounded fetch per invocation.');
$statusSql = $statusWpdb->prepared[0] ?? ['', []];
calibration_assert(str_contains($statusSql[0], 'post_status NOT IN') && in_array('trash', $statusSql[1], true) && in_array('flamingo-spam', $statusSql[1], true) && in_array('auto-draft', $statusSql[1], true), 'Flamingo ID preselection must parameterize the same excluded statuses as post_status=any.');

final class CalibrationWpdb {
	public string $prefix = 'wp_';
	public array $prepared = [];
	public function prepare(string $sql, mixed ...$arguments): string { $this->prepared[] = [$sql, $arguments]; return $sql; }
	public function get_var(string $sql): string { return 'wp_db7_forms'; }
	public function get_results(string $sql, mixed $format = null): array {
		return [['form_id' => 8, 'form_post_id' => 10, 'form_value' => serialize(['message' => 'PRIVATE']), 'form_date' => '2026-07-20 10:00:00']];
	}
}
$GLOBALS['wpdb'] = new CalibrationWpdb();
$cfdbRecords = iterator_to_array((new CF7AIV\Sources\CFDB7Source())->iterate([
	'form_ids' => [10, 20], 'date_from' => '2026-07-01', 'date_to' => '2026-07-22', 'after_id' => 7, 'offset' => 0, 'limit' => 5,
]));
[$cfdbSql, $cfdbParameters] = $GLOBALS['wpdb']->prepared[array_key_last($GLOBALS['wpdb']->prepared)];
calibration_assert(str_contains($cfdbSql, 'form_post_id IN (%d,%d)') && str_contains($cfdbSql, 'form_date BETWEEN %s AND %s') && str_contains($cfdbSql, 'form_id > %d') && str_contains($cfdbSql, 'ORDER BY form_id ASC'), 'CFDB7 must use parameterized form/date filters and stable ascending IDs.');
calibration_assert(in_array(10, $cfdbParameters, true) && in_array(20, $cfdbParameters, true) && in_array('2026-07-01 00:00:00', $cfdbParameters, true) && in_array('2026-07-22 23:59:59', $cfdbParameters, true), 'CFDB7 filter values must be passed separately to prepare().');
calibration_assert(array_column($cfdbRecords, 'reference') === ['8'], 'CFDB7 filtered rows must remain available through the unchanged source interface.');

ob_start();
CF7AIV\Admin\AdminPages::health();
$healthPage = ob_get_clean();
calibration_assert(str_contains($healthPage, 'name="explicit_opt_in"') && str_contains($healthPage, 'name="maximum"') && str_contains($healthPage, 'value="25"') && str_contains($healthPage, 'name="date_from"') && str_contains($healthPage, 'name="date_to"'), 'The web calibration form must expose explicit opt-in, date boundaries, and a default maximum of 25.');

echo "PASS CalibrationV2Test\n";
