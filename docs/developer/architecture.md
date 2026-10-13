# Architecture

## Directory map

```text
cf7-ai-enquiry-validator.php   Plugin header, constants, autoloader, Action Scheduler loader
uninstall.php                  Opt-in data removal
assets/
  js/attribution.js            Public-page attribution and consent collector
  js/admin.js                  Admin screen behaviour (busy buttons, tier→outcome pickers, scan polling)
  css/admin.css                Admin styles
  policies/packs.json          Industry starter policies (cf7aiv-policy/v2)
src/                           Namespace CF7AIV\ (PSR-4 style: src/Foo/Bar.php → CF7AIV\Foo\Bar)
  Plugin.php                   Registers every module; runs migrations and settings upgrades
  Hooks.php                    Names of the public lifecycle actions
  Intake/                      Capture at form submit; attribution, consent, fingerprints
  Sources/                     Flamingo and CFDB7 readers (SubmissionSourceInterface)
  Classification/              Policies, prompt compiler, redaction, worker, evidence rules
  Providers/                   DeepSeek and OpenAI clients (AIProviderInterface)
  Delivery/                    Delivery worker, mappings, consent at send time, retries
  Destinations/                GA4, server-side GTM, Microsoft UET, webhook
  Domain/                      Immutable value objects passed between layers
  Contracts/                   Interfaces that extensions implement
  Admin/                       Screens, admin-post handlers, review, Policy Lab, setup, calibration
  Integration/                 Controlled live tests, pilot helpers, the isolated pipeline self-test
  Infrastructure/              Settings, schema, migrations, queue, encryption, HTTP, privacy tools
  Cli/                         `wp cf7aiv …` commands
vendor/action-scheduler/       Bundled Action Scheduler (GPL)
tests/                         Framework-free PHP tests, one JS test, WordPress/Playwright harness
tools/                         Release packager
docs/                          User and developer documentation (docs/superpowers is internal planning, never shipped)
```

## Layers

Dependencies point downwards. A lower layer never imports a higher one.

```text
Admin, Cli                     (people and operators)
   │
Intake ── Classification ── Delivery        (the pipeline)
   │            │              │
Sources     Providers     Destinations      (I/O adapters behind Contracts)
   │            │              │
          Domain, Contracts
               │
         Infrastructure        (settings, DB schema, queue, crypto, HTTP)
```

The pipeline stages talk through the database and the queue, not through
direct calls. Intake writes an item and fires `cf7aiv_after_capture`.
Classification picks the item up from a queued job. Delivery picks it up from
another queued job. Because of this, each stage can be retried, re-run or
tested on its own.

## Bootstrap

`cf7-ai-enquiry-validator.php` does this:

1. Defines `CF7AIV\VERSION`, `CF7AIV\FILE`, `CF7AIV\DIR` and `CF7AIV\URL`.
2. Registers an autoloader for `CF7AIV\` → `src/`.
3. Loads the bundled Action Scheduler only if no other plugin has already
   loaded one (`as_enqueue_async_action` undefined).
4. Calls `Plugin::instance()->register()` and registers `Plugin::activate` as
   the activation hook.

`Plugin::register()` wires every module:

```php
add_action('init', loadTextdomain);
add_action('plugins_loaded', upgrade, 20);   // migrate if schema version differs, then ensureSettings()
Privacy::register();          // WordPress personal-data exporter and eraser
IntakeHooks::register();
ClassificationHooks::register();
DeliveryHooks::register();
AdminHooks::register();
CliHooks::register();
```

`Plugin::ensureSettings()` runs on every load. It creates `cf7aiv_settings`
from `Settings::defaults()` on a fresh install. Otherwise it applies
`Settings::upgrade()` (one-time changes keyed by `settings_revision`) and then
`Settings::withDefaults()` (adds keys introduced by newer versions without
touching saved values). It writes only when something changed.

Because `ensureSettings()` rewrites the whole settings array, **never store a
result produced by a background job inside `cf7aiv_settings`.** A page load
that read the array earlier will overwrite it. Background results get their
own option: `Ga4Validation` and `AccuracyTest` show the pattern.

## Hook registry

### WordPress and Contact Form 7 hooks the plugin listens to

| Hook | Handler | Purpose |
| --- | --- | --- |
| `wp_enqueue_scripts` | `IntakeHooks::enqueue` | Loads `attribution.js` with its config (`window.cf7aivAttribution`). |
| `template_redirect` | `IntakeHooks::serverCookie` → `AttributionCookie::serverFallback` | Writes the attribution cookie server-side on navigation requests, for visitors whose JS has not run yet. |
| `wpcf7_form_elements` | `IntakeHooks::injectFields` → `FieldNames::injectMissing` | Adds the hidden `cf7aiv_*` fields to every CF7 form. |
| `wpcf7_after_flamingo` | `IntakeHooks::afterFlamingo` | Captures the item with its Flamingo inbound ID as the source reference. |
| `wpcf7_submit` (priority 20) | `IntakeHooks::afterSubmit` | Captures when Flamingo did not (CFDB7 or no store). Skipped if `afterFlamingo` already captured this submission. |
| `wp_consent_api_registered_{basename}` | `IntakeHooks::declareConsentApiCompatibility` | Declares WP Consent API support. |
| `wp_privacy_personal_data_exporters` / `_erasers` | `Privacy` | Export and erase plugin data by email hash. |
| `admin_menu`, `admin_init`, `admin_enqueue_scripts` | `AdminHooks` | Menu pages, settings registration, admin assets. |
| `admin_post_cf7aiv_*` | `AdminHooks::HANDLERS` (all wrapped by `guarded()`) | Every admin form. See [Administration](admin.md). |
| `init` | `AdminHooks::schedule` | Ensures the daily WP-Cron event exists. |

### Plugin filters (registries)

| Filter | Who adds to it | Who reads it |
| --- | --- | --- |
| `cf7aiv_submission_sources` | `IntakeHooks::registerSources` (`flamingo`, `cfdb7`) | Classification worker, review screen, calibration, health, CLI |
| `cf7aiv_ai_providers` | `ClassificationHooks::registerProviders` (`deepseek`, `openai`) | Classification worker, setup scan, CLI |
| `cf7aiv_destinations` | `DeliveryHooks::destinations` (`ga4`, `server_gtm`, `microsoft_uet`, `webhook`) | Delivery worker; `DiagnosticsService::diagnose` (for non-built-in keys) |
| `cf7aiv_policy_packs` | none (extension point) | `PolicyPackRegistry::all()` |
| `cf7aiv_site_profile_*` | none (extension points) | `SiteProfileScanner` |
| `cf7aiv_consent_resolvers` | `IntakeHooks::registerConsentResolvers` (`most_restrictive`) | `CaptureService::capture` → `ConsentResolver::resolveWith` (each extension resolver is one more consent source) |

### Lifecycle actions the plugin fires

Defined in `CF7AIV\Hooks`. Arguments are documented in
[Extending](../EXTENDING.md#lifecycle-actions).

| Action | Fired by | The plugin itself uses it to… |
| --- | --- | --- |
| `cf7aiv_after_capture` | `CaptureService::capture` | queue classification (`ClassificationHooks::afterCapture`). |
| `cf7aiv_after_classification` | `ClassificationWorker::process` | nothing (extensions only). |
| `cf7aiv_after_review` | `ReviewService::apply` | nothing (extensions only). |
| `cf7aiv_after_delivery` | `DeliveryWorker::process`, after each recorded attempt | nothing (extensions only). |
| `cf7aiv_after_setup_suggestions` | `SetupSuggestionService::generate` | nothing. |
| `cf7aiv_after_calibration_scheduled` | `CalibrationService::schedule` | nothing. |
| `cf7aiv_admin_action_failed` | `AdminHooks::fail` (unexpected fault in a handler) | write an error to the activity log (`EventLog`). |
| `cf7aiv_admin_action_refused` | `AdminHooks::refuse` (bad input, 400) | write a warning to the activity log. |
| `cf7aiv_delivery_gate` | `DiagnosticsService::recordGate` (a mapping was skipped) | write the gate reason to the activity log. Also logged to `error_log` when `CF7AIV_DEBUG` is true. |

### Background jobs

All except the daily event run through `Infrastructure\Queue` (Action
Scheduler, group `cf7aiv`, with a WP-Cron fallback). See
[Queue and background jobs](queue-and-jobs.md).

| Hook | Arguments | Handler |
| --- | --- | --- |
| `cf7aiv_classify_item` | `[itemId]`, `[itemId, true]` to replace a prediction, or `[itemId, replace, whitelistSnapshot]` for historical items | `ClassificationWorker::process` |
| `cf7aiv_evaluate_delivery` | `[itemId]` | `DeliveryWorker::process` |
| `cf7aiv_deliver_item` | `[itemId]` (retries) | `DeliveryWorker::process` |
| `cf7aiv_backfill_batch` | `[sourceKey, arguments, quota]` | `CalibrationBatchWorker::process` |
| `cf7aiv_validate_ga4_job` | `[]` | `AdminHooks::runGa4Validation` |
| `cf7aiv_setup_scan_job` | `[packKey, administratorId]` | `AdminHooks::runSetupScan` |
| `cf7aiv_accuracy_test_job` | `[runId]` | `AccuracyTest::runJob` |
| `cf7aiv_daily_maintenance` | WP-Cron, daily | `AdminHooks::daily`: retention cleanup and the optional digest email |

## One enquiry, end to end

```text
Visitor submits a CF7 form
│  (attribution.js has filled hidden cf7aiv_* fields, if consent allowed)
▼
Intake ─ CaptureService::capture                         [inside the CF7 request]
│  • only for mail_sent / mail_failed
│  • attribution from posted fields; consent from WP Consent API, Complianz, Consent Mode
│  • fingerprint (submission token or canonical posted data) → unique
│  • encrypt attribution + consent; optional email/phone hashes (consent-gated)
│  • INSERT wp_cf7aiv_items (state=captured, review_state=pending)
│  • do_action cf7aiv_after_capture → Queue cf7aiv_classify_item
▼
Classification ─ ClassificationWorker::process           [Action Scheduler]
│  • resolve the source (link a 'cf7' item to Flamingo/CFDB7 by submission token)
│  • load the original fields; find the published policy (form:N, else global)
│  • whitelist ∩ safe fields → redact → ClassificationRequest
│  • provider->classify() → ResultValidator (schema + evidence grounding)
│  • EvidenceEvaluator → strong_match | needs_review | not_eligible
│  • possible duplicate (same email/phone hash, sent in last 30 days) → needs_review
│  • optional verification pass for auto-send candidates
│  • Eligibility: published v2 policy, live, not calibration/historical,
│    Ads-eligible tier, strong_match, published mapping
│  • store (CAS on review_revision) → state=classified, review_state=eligible|review
│  • eligible → Queue cf7aiv_evaluate_delivery
│  • transient provider error → retry with backoff (up to 3); permanent → state=error
▼
Review (optional) ─ ReviewService::apply                  [administrator]
│  • confirm / change tier / approve (no send) / approve and dispatch / reject / reanalyse
│  • approve and dispatch records a unique approval ID, then queues delivery
▼
Delivery ─ DeliveryWorker::process                        [Action Scheduler]
   • item must be live origin (is_shadow=0), not calibration/historical
   • approved: strong_match (+ verification passed if required) or audited manual approval
   • for each published mapping of the tier:
       paused? configured? consent permits? signals ready?
       → EnvelopeBuilder (idempotency key) → claim under lease → destination->send()
       → record attempt → retry (up to 5, exponential) → auto-pause on repeated permanent failure
   • each skipped mapping records a gate reason (DiagnosticsService::recordGate)
```

## Design patterns used throughout

- **Injectable callables for testing.** Workers and services take optional
  callables in the constructor (settings resolver, enqueue, clock, store).
  `::standard()` builds the production version. Tests pass fakes instead of
  booting WordPress. Follow this pattern when adding a dependency.
- **`function_exists` guards.** Many classes check `function_exists('get_option')`
  and similar before calling WordPress. This lets the framework-free tests load
  real classes. Keep the guards when you edit these files.
- **Exceptions by meaning.**
  - `InvalidArgumentException`: the caller passed bad input. Its message is
    written for a person and shown to them (admin handlers return it with a
    400).
  - `RuntimeException` and anything else: an internal fault. It is logged and
    the user sees a generic message.
  - `TransientProviderException` / `PermanentProviderException`: tell the
    classification worker whether to retry.
- **Compare-and-swap writes.** Item writes that could race a reviewer check
  `review_revision`. Mapping transitions use `SELECT … FOR UPDATE` inside a
  transaction. Delivery claims use `INSERT IGNORE` on a unique key plus a
  lease token.
- **Fixed phrases, not model prose.** The stored `summary` is a fixed phrase
  chosen from the outcome and reason code. Text the model writes is never
  stored or shown as fact.
- **Bounded, scrubbed logs.** `EventLog` keeps the last 200 entries in one
  option, scrubbed of secrets and personal data on the way in.
  `AdminSafe::log()` redacts credential-looking text before it is displayed.
