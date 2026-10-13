# Administration

Everything an administrator sees or does goes through `src/Admin/`. Screens
render HTML from PHP. Forms post to `admin-post.php` and are handled by
`AdminHooks`. Long work is queued, and the screen reads the result from an
option or transient.

Every screen requires `manage_options`.

## Screens

The top-level menu is "AI Enquiry Validator", slug `cf7aiv`.

| Page slug | Rendered by | What it does |
| --- | --- | --- |
| `cf7aiv` | `AdminPages::overview` | Lifecycle stage; queue and classification counts; review-truth accuracy, Ads precision and missed leads (`CalibrationMetrics`), split by provider/model/reasoning; AI token usage (`IntegrationsPage::usageTotals`, cached in `cf7aiv_ai_usage_totals` for 10 minutes); destination state. Data comes from `DashboardService`. |
| `cf7aiv-review` | `ReviewQueuePage::render` | Filterable item list, bulk actions, and a single-item view. The single-item view shows the redacted AI input (`ReviewContext::fields`), the AI's tier and outcome, and the current classification. Actions include correcting via tier and outcome pickers. |
| `cf7aiv-policy` | `PolicyLabPage::render` | Guided policy editor; publish; advanced JSON import and export; accuracy test (`AccuracyTestPanel`); "learn from corrections". |
| `cf7aiv-integrations` | `IntegrationsPage::render` | AI provider (key, model, reasoning, timeout, connection test); GA4 or server-side GTM; Microsoft UET; webhook; consent and privacy; mappings; the automation-live and GA4-live transitions; Microsoft transport test; consent policy. |
| `cf7aiv-health` | `AdminPages::health` | Attribution capture diagnostics, paused destinations (resume), recent delivery failures, the activity log (`EventLog`), and the calibration scheduler. |
| `cf7aiv-setup` | `AdminPages::setup` → `SetupPage` | Pack choice, site scan and suggestions, guided policy, lifecycle stage, calibration. |

### Simple and advanced mode

`settings.ui.advanced_mode` switches between two views.

- **Simple mode** must never show model confidence, raw source content or
  source references. `tests/AdminTest.php` and `tests/ReviewScreenTest.php`
  check this.
- **Advanced mode** adds policy JSON, evidence diagnostics, provider usage,
  confidence, the raw source enquiry and lower-level destination controls.

### Shared UI helpers

- `AdminUi`: badges, metrics, panels, accessible tables, empty states,
  `humanLabel()`.
- `PolicyLabels`: human labels for a policy's tiers and outcomes, and the list
  of saved policies.
- `AdminSafe::log()`: redacts credential-looking text before display.

Escape all output: the code uses `esc_html`, with an `htmlspecialchars`
fallback so the tests can run without WordPress.

## Handling a form post

All handlers are listed once in `AdminHooks::HANDLERS` and registered in a
loop. Each is wrapped in `AdminHooks::guarded()`, so none can be registered
unguarded. A handler:

1. reads `$_POST` through `self::post()` (unslashed);
2. calls `AdminRequestGuard::authorize($nonce, $nonceAction)`, which checks
   `manage_options` and the nonce and answers 403 on failure;
3. validates input and calls a service;
4. redirects with `self::redirect('admin.php?page=…')`.

`guarded()` turns exceptions into responses:

- **`InvalidArgumentException`**: the message is shown with HTTP 400 (via
  `refuse()`, which fires `cf7aiv_admin_action_refused`). Write these messages
  for an administrator to read.
- **Anything else**: logged to `error_log` and fires
  `cf7aiv_admin_action_failed` (which the activity log records). The user sees
  a generic 500.
- **Outside WordPress** (in tests): the original exception is re-thrown.

| `admin_post` action | Nonce action | Purpose |
| --- | --- | --- |
| `cf7aiv_review` | `cf7aiv_review` | One review action on one item |
| `cf7aiv_bulk_review` | `cf7aiv_bulk_review` | The same action on several items |
| `cf7aiv_save_settings` | `cf7aiv_settings` | Save settings and secrets (see below) |
| `cf7aiv_save_guided_policy` | `cf7aiv_guided_policy` | Build a draft from the guided form (`GuidedPolicyDraft`) |
| `cf7aiv_lifecycle_transition` | `cf7aiv_lifecycle_transition` | Setup → Testing → Live |
| `cf7aiv_automation_transition` | `cf7aiv_automation_transition` | Automation shadow ↔ live (live only at stage Live) |
| `cf7aiv_ga_transition` | `cf7aiv_ga_transition` | GA4 dry run ↔ live (live only after validation passes) |
| `cf7aiv_save_consent` | `cf7aiv_save_consent` | Consent mode and legal-basis override (appends to audit) |
| `cf7aiv_policy_import` | `cf7aiv_policy` | Save pasted policy JSON as a draft |
| `cf7aiv_policy_publish` | `cf7aiv_policy_publish` | Publish a policy version |
| `cf7aiv_save_mapping` | `cf7aiv_mapping` | Save a mapping draft |
| `cf7aiv_mapping_publish` / `_enable` / `_disable` | same as action | Mapping lifecycle |
| `cf7aiv_validate_ga4` | `cf7aiv_validate_ga4` | Queue GA4 debug validation |
| `cf7aiv_test_microsoft` | `cf7aiv_test_microsoft` | One zero-value Microsoft test event |
| `cf7aiv_setup` | `cf7aiv_setup` | Complete setup |
| `cf7aiv_setup_scan` | `cf7aiv_setup_scan` | Queue the site scan |
| `cf7aiv_setup_suggestion` | `cf7aiv_setup_suggestion` | Accept, edit or reject a suggestion |
| `cf7aiv_backfill` | `cf7aiv_backfill` | Schedule a calibration run |
| `cf7aiv_resume_destination` | `cf7aiv_resume_destination` | Lift an automatic pause |
| `cf7aiv_test_provider` | `cf7aiv_test_provider` | AI connection test (result in transient `cf7aiv_provider_test_{userId}`) |
| `cf7aiv_accuracy_test` / `cf7aiv_accuracy_cancel` | same as action | Start or cancel an accuracy test |
| `cf7aiv_corrections_to_examples` | same as action | Turn corrections into a new policy draft |

## Settings

Settings live in one option, `cf7aiv_settings`. Defaults and upgrades are in
`Infrastructure\Settings`.

| Key | Default | Notes |
| --- | --- | --- |
| `setup_complete`, `setup.stage` | `false`, `setup` | Stage is `setup`, `testing` or `live` |
| `ui.advanced_mode` | `false` | |
| `automation.mode` | `shadow` | `live` only through the automation transition |
| `automation.verification_mode` | `off` | `automatic_candidates` needs a verification-capable provider (DeepSeek) |
| `calibration.default_sample_size` | 25 | |
| `privacy.enhanced_matching` | `false` | Enables consent-gated email and phone hashes |
| `privacy.delete_on_uninstall` | `false` | |
| `privacy.retention_days` | 90 | Daily retention cleanup |
| `attribution.retention_days` | 90 | Cookie lifetime |
| `consent.mode` | `respect` | See [Delivery](delivery.md#consent) |
| `delivery.pause_threshold` | 3 | Permanent failures before auto-pause |
| `digest.enabled`, `digest.email` | off | Daily email of pending reviews, paused integrations and failures |
| `destinations.*` | all disabled, `dry_run: true` | Per-destination config |
| `providers.*` | DeepSeek, `deepseek-flash`, reasoning off, timeout 60 | |
| `settings_revision` | 2 | Bump with a new step in `Settings::upgrade()` |

### Saving

`saveSettings` merges only the sections present in the post, after
`SettingsSanitizer::sanitize()`.

- **Secrets.** Handled by `SettingsSanitizer::secrets()`. A non-empty value is
  encrypted into `cf7aiv_secrets`; a blank field keeps the stored value.
  `Crypto::secret($name)` prefers a `CF7AIV_<NAME>` constant over the stored
  value.
- **GA4 re-validation.** Changing the GA4 measurement ID, its secret or its
  enabled state invalidates GA4 validation, clears `live_enabled` and puts it
  back in dry run.
- **Verification.** Choosing a provider that cannot verify switches
  verification off.

### Encryption

`Crypto` uses AES-256-GCM, keyed by
`sha256(AUTH_KEY | SECURE_AUTH_KEY | 'cf7aiv/v1')`. Payloads look like
`v1.<iv>.<tag>.<ciphertext>` (base64url).

**Changing the WordPress salts makes stored secrets, attribution and consent
unreadable.** Re-enter the secrets afterwards.

### Lifecycle

- **Stage.** `setup` → `testing` (any time) → `live`. Live requires a
  published v2 policy (`hasPublishedV2Policy`). Each stage change resets
  automation to `shadow`.
- **Automation.** Can go `live` only when the stage is `live`.
- **GA4 live.** Additionally needs passed validation, a measurement ID and an
  API secret.

These are three separate, separately-nonced steps on purpose.

## Review

`ReviewService::apply($itemId, $action, $input, $adminId)`:

| Action | Effect |
| --- | --- |
| `confirm` | `review_state = confirmed`; the reviewed tier and outcome become the prediction |
| `change_tier` | `review_state = corrected`. Requires a tier, an outcome and a non-identifying `example_summary` (refused if `Redactor` would change it). Stores a `change_tier` row in `wp_cf7aiv_review_suggestions` (status `draft`). |
| `approve_no_send` | `review_state = approved` |
| `approve_dispatch` | Only for live-origin, non-calibration items while automation is live. In one transaction, `WpReviewStore::approveDispatch` writes `approved_dispatch` and a unique `dispatch_approval_id` plus an `accepted` audit row. It then queues `cf7aiv_evaluate_delivery`. |
| `reject` | `review_state = rejected` |
| `reanalyse` | Queues `cf7aiv_classify_item` with `replacePrediction = true` |

How changes stay safe:

- **Concurrent edits.** Every change is compare-and-swap on `review_revision`,
  which the form posts back. If the item changed meanwhile, the change is
  refused with a warning.
- **Timing warnings.** Items older than 24 hours have weakened session
  attribution; older than 72 hours, GA4 backdating is unavailable.
- **Hook.** Fires `cf7aiv_after_review` for every action.

## Policy Lab

### Accuracy test

`AccuracyTest`, state in option `cf7aiv_accuracy_test`. It replays up to 25,
50 or 100 of the most recently reviewed live enquiries. You choose the saved
policy, provider, model and reasoning level.

- **Which enquiries.** Only items classified under the same `policy_key` are
  used, because production picks the policy by form.
- **What is sent.** Each enquiry is sent with the intersection of the tested
  policy's whitelist and the fields the original policy sent.
- **How it runs.** One enquiry per `cf7aiv_accuracy_test_job`, chained, with
  a single writer. Cancel sets the state, and the next job stops. Results are
  re-read with `wp_cache_delete` to avoid stale object-cache reads.
- **Report.** `AccuracyTest::report()` compares production and test on:
  - exact agreement with reviewers;
  - Ads precision;
  - false positives;
  - missed leads;
  - tokens used.

### Learn from corrections

`CorrectionExamples` lists draft `change_tier` suggestions. `merge()` adds the
selected ones to a copy of a policy as `examples`
(`{summary, tier_id, not_tier_id?}`), skipping each of these with a reason:

- a summary that fails policy prose validation;
- a tier the policy lacks;
- a duplicate;
- anything beyond `MAX_EXAMPLES` = 40.

`createDraft()` saves the result as a new draft version, after checking that
the version does not already exist. Skipped reasons are shown once through the
transient `cf7aiv_examples_notice_{userId}`.

## Setup and the site scan

1. **Scan.** `cf7aiv_setup_scan` queues `cf7aiv_setup_scan_job` with
   `[packKey, adminId]`. Progress is tracked in the transient
   `cf7aiv_scan_outcome_{adminId}`; `admin.js` polls the page.
2. **Read the site.** `SiteProfileScanner::scan()` reads published,
   password-free local pages, with limits: 50 pages, 2,000 characters each,
   40,000 in total. It also reads CF7 form *structure* (field names, types,
   labels, options), never submission values. The `cf7aiv_site_profile_*`
   filters can extend it. See [Extending](../EXTENDING.md#assisted-site-profile-scanning).
3. **Suggest.** `SetupSuggestionService::generate()` sends the profile to the
   provider's `suggestPolicy()`.
4. **Store suggestions.** `SetupSuggestionRepository` stores each one in
   `wp_cf7aiv_setup_suggestions` with status `pending` and provenance (which
   page, form or pack it came from). Allowed suggestion types: `service`,
   `audience`, `value_signal`, `seriousness_signal`,
   `legitimate_non_conversion`, `exclusion`, `field_mapping`.
5. **Fires** `cf7aiv_after_setup_suggestions`.
6. **Review.** The administrator accepts, edits or rejects each suggestion.
   Accepted ones feed `GuidedPolicyDraft::build()`.

Nothing is published automatically.

## Calibration (historical sampling)

Calibration classifies a bounded, stratified sample of old enquiries to
measure accuracy. It can never send anything.

1. **Schedule.** Start it from Setup or Tracking Health (`cf7aiv_backfill`),
   or with `wp cf7aiv calibrate`. `CalibrationService::schedule()` requires
   explicit opt-in and a maximum of 1–500. The source must be available, and
   every form ID must exist.
2. **Check the whitelist fingerprint.** `CalibrationPolicySnapshot::active()`
   is the union of the published policies' safe whitelists, plus its
   fingerprint. The admin form posts the fingerprint it previewed; if
   published fields changed since, the request is refused.
3. **Plan.** `CalibrationPlanner::plan()` splits the quota across forms and
   date windows.
4. **Create the run.** A row in `wp_cf7aiv_calibration_runs` with state
   `scheduling`. One `cf7aiv_backfill_batch` job is queued per stratum. Only
   when all are queued does the run become `active`; otherwise it is
   cancelled and the queued jobs removed.
5. **Process batches.** `CalibrationBatchWorker::process()` waits up to 5
   times for activation. It then uses `BackfillService` to save items with
   `is_calibration = is_historical = 1` and the run ID, and queues
   `cf7aiv_classify_item` with `[itemId, false, whitelistSnapshot]`.
6. **Cancel safely.** `WpCalibrationRunStore` takes a row lock
   (`SELECT … FOR UPDATE`) for both cancellation and every write by a
   calibration item. A cancelled run's in-flight jobs therefore write nothing.

## Diagnostics, logs and health

- **`DiagnosticsService::inspect()`** (`wp cf7aiv doctor`):
  - plugin version, expected and installed schema;
  - queue health (counts by state, oldest unclassified);
  - destination configuration, mappings, consent integrations.

  **`diagnose($itemId)`** (`wp cf7aiv diagnose --item=N`) lists every gate
  for one item. **`describeFailure()`** turns an exception into a safe,
  readable message.
- **`HealthService::inspect()`** (`wp cf7aiv health`): source availability and
  counts, and paused destinations.
- **`EventLog`**: option `cf7aiv_event_log`, the last 200 entries, levels
  `info`/`warning`/`error`, scrubbed on write. Use
  `EventLog::record($channel, $level, $message, $context)` for anything an
  operator needs to see. Do not use it for debug noise.
- **`CF7AIV_DEBUG`**: when this constant is true, delivery gates and some
  diagnostics are also written to `error_log`.

## Daily maintenance

`cf7aiv_daily_maintenance` (WP-Cron, daily) runs:

- `RetentionService::cleanup(privacy.retention_days)`: deletes items older
  than the cutoff, with their review suggestions and deliveries, plus setup
  suggestions and calibration runs older than it. Source enquiries in
  Flamingo or CFDB7 are never touched.
- `DigestService::send()`: if enabled, emails counts of pending reviews,
  paused integrations and permanent failures.

## Privacy tools

`Infrastructure\Privacy` registers a WordPress personal-data exporter and
eraser (`cf7aiv`). Both find items by the normalised email hash. This works
only for items captured with enhanced matching and `ad_user_data` granted;
otherwise no hash was stored. The eraser removes dependent review suggestions
and deliveries before the items.
