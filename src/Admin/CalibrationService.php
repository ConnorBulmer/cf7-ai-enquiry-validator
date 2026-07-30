<?php

declare(strict_types=1);

namespace CF7AIV\Admin;

use CF7AIV\Contracts\SubmissionSourceInterface;
use CF7AIV\Hooks;
use CF7AIV\Infrastructure\Queue;
use CF7AIV\Classification\ContextBuilder;

final class CalibrationService {
	private $sources;
	private $knownFormIds;
	private $enqueue;
	private $runId;
	private $cancel;
	private CalibrationRunStoreInterface $runs;

	public function __construct(?callable $sources = null, ?callable $knownFormIds = null, ?callable $enqueue = null, ?callable $runId = null, ?callable $cancel = null, ?CalibrationRunStoreInterface $runs = null) {
		$this->sources = $sources ?? static fn (): array => function_exists('apply_filters') ? (array) apply_filters('cf7aiv_submission_sources', []) : [];
		$this->knownFormIds = $knownFormIds ?? static function (): array {
			if (! function_exists('get_posts')) { return []; }
			return array_map('intval', (array) get_posts([
				'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
			]));
		};
		$this->enqueue = $enqueue ?? static fn (string $hook, array $arguments): int|bool => Queue::enqueue($hook, $arguments);
		$this->runId = $runId ?? static fn (): string => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
		$this->cancel = $cancel ?? static fn (string $hook, array $arguments): bool => Queue::unschedule($hook, $arguments);
		$this->runs = $runs ?? new WpCalibrationRunStore();
	}

	/** @param array<string,mixed> $criteria */
	public function schedule(string $sourceKey, array $criteria, int $administratorId): int {
		if (($criteria['explicit_opt_in'] ?? null) !== true) {
			throw new \InvalidArgumentException('Calibration requires explicit opt-in.');
		}
		$maximum = (int) ($criteria['maximum'] ?? 25);
		if ($maximum < 1 || $maximum > 500) {
			throw new \InvalidArgumentException('Calibration maximum must be between 1 and 500.');
		}
		$sourceKey = trim($sourceKey);
		$sources = ($this->sources)();
		$source = is_array($sources) ? ($sources[$sourceKey] ?? null) : null;
		if (! $source instanceof SubmissionSourceInterface || (method_exists($source, 'available') && ! $source->available())) {
			throw new \InvalidArgumentException("Submission source {$sourceKey} is unavailable.");
		}

		$formIds = self::formIds($criteria['form_ids'] ?? []);
		if ($formIds !== []) {
			$known = array_values(array_unique(array_map('intval', (array) ($this->knownFormIds)())));
			$unknown = array_diff($formIds, $known);
			if ($unknown !== []) { throw new \InvalidArgumentException('Calibration contains an unknown form ID.'); }
		}

		$dateFrom = (string) ($criteria['date_from'] ?? '');
		$dateTo = (string) ($criteria['date_to'] ?? '');
		$whitelist = ContextBuilder::safeWhitelist(is_array($criteria['ai_whitelist_snapshot'] ?? null) ? $criteria['ai_whitelist_snapshot'] : []);
		$fingerprint = (string) ($criteria['whitelist_fingerprint'] ?? '');
		$expectedFingerprint = CalibrationPolicySnapshot::fingerprint($whitelist);
		if ($fingerprint === '') { $fingerprint = $expectedFingerprint; }
		if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1 || ! hash_equals($expectedFingerprint, $fingerprint)) {
			throw new \InvalidArgumentException('Calibration whitelist fingerprint does not match its field snapshot.');
		}
		$criteria['ai_whitelist_snapshot'] = $whitelist;
		$criteria['whitelist_fingerprint'] = $fingerprint;
		$plan = CalibrationPlanner::plan($formIds, $dateFrom, $dateTo, $maximum);
		$runId = (string) ($this->runId)();
		if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $runId) !== 1) { throw new \RuntimeException('Could not create a calibration run ID.'); }
		if (! $this->runs->create($runId, $sourceKey, max(0, $administratorId), $criteria)) {
			throw new \RuntimeException('Could not persist the calibration run.');
		}

		$scheduled = 0;
		$queued = [];
		foreach ($plan as $stratum) {
			$quota = $stratum['limit'];
			$arguments = [
				'form_ids' => $stratum['form_id'] === null ? [] : [$stratum['form_id']],
				'date_from' => $stratum['date_from'],
				'date_to' => $stratum['date_to'],
				'offset' => 0,
				'after_id' => 0,
				'quota' => $quota,
				'completed' => 0,
				'calibration_run_id' => $runId,
				'administrator_id' => max(0, $administratorId),
				'ai_whitelist_snapshot' => $whitelist,
				'whitelist_fingerprint' => $fingerprint,
			];
			$queueArguments = [$sourceKey, $arguments, $quota];
			$queueResult = ($this->enqueue)('cf7aiv_backfill_batch', $queueArguments);
			if (! self::queued($queueResult)) {
				$this->runs->cancel($runId);
				foreach (array_reverse($queued) as $queuedAction) {
					try { ($this->cancel)($queuedAction['hook'], $queuedAction['arguments']); } catch (\Throwable) {}
				}
				throw new \RuntimeException('Could not schedule the calibration batch.');
			}
			$queued[] = ['hook' => 'cf7aiv_backfill_batch', 'arguments' => $queueArguments];
			$scheduled += $quota;
		}
		if (! $this->runs->activate($runId, $scheduled)) {
			$this->runs->cancel($runId);
			foreach (array_reverse($queued) as $queuedAction) {
				try { ($this->cancel)($queuedAction['hook'], $queuedAction['arguments']); } catch (\Throwable) {}
			}
			throw new \RuntimeException('Could not activate the calibration run.');
		}

		if (function_exists('do_action')) { try { do_action(Hooks::AFTER_CALIBRATION_SCHEDULED, $runId, $sourceKey, $scheduled, $administratorId, $criteria); } catch (\Throwable) {} }
		return $scheduled;
	}

	private static function queued(mixed $result): bool { return $result === true || (is_int($result) && $result > 0); }

	/** @return list<int> */
	private static function formIds(mixed $values): array {
		if (! is_array($values)) { throw new \InvalidArgumentException('Calibration form_ids must be an array.'); }
		$formIds = [];
		foreach ($values as $value) {
			if (is_int($value)) { $id = $value; }
			elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) { $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); }
			else { throw new \InvalidArgumentException('Calibration form IDs must be canonical positive integers.'); }
			if (! is_int($id) || $id < 1) { throw new \InvalidArgumentException('Calibration form IDs must be canonical positive integers.'); }
			$formIds[] = $id;
		}
		$formIds = array_values(array_unique($formIds));
		sort($formIds, SORT_NUMERIC);
		return $formIds;
	}
}
