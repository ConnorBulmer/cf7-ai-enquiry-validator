# Queue and background jobs

All slow work (AI calls, conversion sends, scans, calibration) runs in the
background through `Infrastructure\Queue`. The visitor's request and the
administrator's form post only queue a job.

## Queue API

```php
Queue::enqueue(string $hook, array $args = [], string $group = 'cf7aiv'): int|bool  // run as soon as possible
Queue::schedule(string $hook, int $timestamp, array $args = [], string $group = 'cf7aiv'): int|bool
Queue::unschedule(string $hook, array $args = [], string $group = 'cf7aiv'): bool
Queue::accepted(mixed $result): bool  // true for true or a positive action ID
```

Always test the result with `Queue::accepted()`. Never use `!== false`.

### Backends

1. **Action Scheduler.** Used when `as_enqueue_async_action` exists. The
   plugin bundles a copy in `vendor/action-scheduler/`, loaded only if no
   other plugin has loaded one.
2. **WP-Cron.** The fallback: `wp_schedule_single_event`, one second ahead for
   `enqueue`.

### De-duplication

Before adding a job, the queue checks for an identical *pending* action (same
hook, arguments and group) and returns `true` if one exists. Only *pending*
counts. A job that is currently running is not "already queued". If it were,
a job that schedules its own retry would have the retry silently dropped.
Before 0.2.35 this was the case, and retries never ran. `tests/QueueTest.php`
guards against that.

### Normalised results

- Action Scheduler reports a failed enqueue as `0`; the queue returns `false`.
- WP-Cron's `duplicate_event` error means the job is already waiting; the
  queue returns `true`.

## Jobs

| Hook | Queued by | Arguments | Retries |
| --- | --- | --- | --- |
| `cf7aiv_classify_item` | `cf7aiv_after_capture`, reanalyse, calibration, the worker itself | `[itemId]`; `[itemId, true]` to replace a prediction; `[itemId, replace, whitelist]` for historical items | Transient provider errors: up to 3 more attempts, delay `min(3600, 60·2^(n−1))` s |
| `cf7aiv_evaluate_delivery` | Classification (eligible), approve-and-dispatch | `[itemId]` | — |
| `cf7aiv_deliver_item` | The delivery worker | `[itemId]` | Retryable results: up to 5 attempts, same backoff (`RetryPolicy`) |
| `cf7aiv_backfill_batch` | `CalibrationService::schedule`, the batch worker (to wait for activation or continue) | `[sourceKey, arguments, quota]` | Waits up to 5 times for the run to activate |
| `cf7aiv_validate_ga4_job` | `cf7aiv_validate_ga4` | `[]` | Runs inline if it cannot be queued |
| `cf7aiv_setup_scan_job` | `cf7aiv_setup_scan` | `[packKey, adminId]` | — |
| `cf7aiv_accuracy_test_job` | `cf7aiv_accuracy_test`, then itself (one item per job) | `[runId]` | A failed item is recorded and the chain moves on |
| `cf7aiv_daily_maintenance` | WP-Cron daily event (`AdminHooks::schedule` on `init`) | — | — |

### Rules for job handlers

- **Idempotent.** A job may run twice: a retry after a timeout, or a second
  worker. Re-read the item and check whether the work is already done.
  - The classification worker stops if a prediction exists (unless
    replacing).
  - The delivery worker relies on idempotency keys and leases.
  - The accuracy test re-reads its option fresh.
- **Primitive arguments.** Arguments must be JSON-serialisable. Action
  Scheduler stores and compares them. Two jobs with the same arguments are the
  same job for de-duplication.
- **Never throw out of a handler.** Catch, record the failure where an
  administrator can see it (the item's `last_error`, `EventLog`, an option),
  and decide whether to reschedule.
- **Background results get their own option.** Never write them into
  `cf7aiv_settings`; see [Architecture](architecture.md#bootstrap).

## Running the queue by hand

`wp cf7aiv process_queue` runs one Action Scheduler pass. It refuses unless
automation is shadow and every destination is in dry run
(`OperationalSafety::isSafe`). `--allow-live` overrides that, and should be
used only after a deliberate production review.

In WordPress admin, Tools → Scheduled Actions (from Action Scheduler) lists
`cf7aiv` group actions with their logs.
