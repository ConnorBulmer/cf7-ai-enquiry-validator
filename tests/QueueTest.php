<?php
/**
 * Queue de-duplication against Action Scheduler. Run: php tests/QueueTest.php
 */
declare(strict_types=1);

function queue_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException('FAIL: ' . $message); } }

spl_autoload_register(static function (string $class): void {
	if (! str_starts_with($class, 'CF7AIV\\')) { return; }
	$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
	if (is_file($path)) { require_once $path; }
});

// A fake Action Scheduler: actions are pending or running, as in the real store.
$GLOBALS['queue_actions'] = [];
function as_get_scheduled_actions(array $args = [], string $format = 'OBJECT'): array {
	$ids = [];
	foreach ($GLOBALS['queue_actions'] as $id => $action) {
		if ($action['hook'] === $args['hook'] && $action['args'] === $args['args'] && $action['status'] === $args['status']) { $ids[] = $id; }
	}
	return $ids;
}
function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool {
	foreach ($GLOBALS['queue_actions'] as $action) {
		if ($action['hook'] === $hook && $action['args'] === $args && in_array($action['status'], ['pending', 'in-progress'], true)) { return true; }
	}
	return false;
}
function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false): int {
	$GLOBALS['queue_actions'][] = ['hook' => $hook, 'args' => $args, 'status' => 'pending'];
	return count($GLOBALS['queue_actions']);
}
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false): int {
	return as_enqueue_async_action($hook, $args, $group);
}

use CF7AIV\Infrastructure\Queue;

// A classification job is running for item 42 and hits a rate limit, so it
// schedules its own retry. The running job must not count as the retry.
$GLOBALS['queue_actions'] = [['hook' => 'cf7aiv_classify_item', 'args' => [42], 'status' => 'in-progress']];
$retry = Queue::schedule('cf7aiv_classify_item', time() + 60, [42]);
queue_assert(is_int($retry) && $retry > 0 && count($GLOBALS['queue_actions']) === 2, 'A job must be able to schedule its own retry while it is running.');

// A second identical request while the retry is still waiting is a duplicate.
queue_assert(Queue::enqueue('cf7aiv_classify_item', [42]) === true && count($GLOBALS['queue_actions']) === 2, 'An identical waiting job must not be queued twice.');

// Different arguments are a different job.
queue_assert(Queue::accepted(Queue::enqueue('cf7aiv_classify_item', [43])) && count($GLOBALS['queue_actions']) === 3, 'Jobs for different items must both be queued.');

echo "PASS QueueTest\n";
