# Changelog

All notable changes to CF7 AI Enquiry Validator are documented here. The project follows semantic versioning.

## 0.2.30 — 2026-08-08

- Fixed the GA4 validation result being lost. It was written into the main
  settings array, which `Plugin::ensureSettings()` rewrites in full on every
  page load, so the background job's "passed" was overwritten by any request
  that had read the array moments earlier. The activity log recorded a pass
  while the screen reported "Not validated", which is exactly what happened on a
  live site. The result now has its own option that nothing else writes, and the
  write is read back and reported if it does not land.
- Changed the GA4 live transition and destination readiness to gate on that
  result, so the safety check and the thing it displays cannot disagree.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.29 — 2026-08-08

- Added a plugin activity log on Tracking Health & Logs. The plugin already
  broadcast why it refused, failed or gated something, but those facts only
  reached the PHP error log under `CF7AIV_DEBUG`, which managed hosting
  frequently does not expose - leaving the plugin able to diagnose its own
  failures with no way to hand over the answer. Entries are scrubbed of
  credentials and personal data before storage and capped at 200.
- Changed GA4 debug validation to run as a queued job instead of inside the
  administrator's request. Contacting Google can outlive PHP's execution limit
  on a slow or filtered network, and inline that took the whole request with it:
  nothing was recorded, and the page reloaded showing no result and no reason.
  The queued job records its outcome whenever it finishes, and the screen shows
  a "Checking" state meanwhile. If the queue is unavailable the check still runs
  inline, because refusing to check at all would be worse.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.28 — 2026-08-08

- Fixed the GA4 validation reason never being seen. It was held in a one-read
  transient, so it was consumed by whichever page load happened first - often one
  that rendered it inside a hidden tab panel - and refreshing lost it. The reason
  is now stored beside the flag it explains, persists until validation passes,
  and is cleared on success so a fixed problem stops being reported.
- Added a page-level notice when GA4 is enabled but unvalidated, because a
  destination that cannot go live is not a detail inside one tab.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.27 — 2026-08-08

- Fixed destination checkboxes silently reverting when cleared. An unchecked box
  is not submitted at all, and the sanitizer only overwrites a flag whose key is
  present, starting from defaults otherwise. Any flag defaulting to true was
  therefore impossible to turn off, which is what happened to the GA4 dry-run
  flag: it accepted the change and reverted it on save with no explanation. Every
  checkbox now carries a hidden companion, the pattern already used for the
  advanced-mode flag.
- Changed the GA4 dry-run control to state that it is governed by the validated
  lifecycle transition rather than presenting itself as editable. Live delivery
  is gated on debug validation, so that box was never going to release it, and a
  setting that quietly refuses reads as broken rather than deliberate.
- Changed the plugin author to Connor Bulmer.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.26 — 2026-08-08

- Added GA4's own validation messages to the failure it reports. The debug
  endpoint exists to say what is wrong - a mismatched API secret, an unknown
  event name, a malformed client id all come back named - and the response was
  collapsed into "GA4 debug validation failed", discarding the entire reason for
  calling it. A destination that refuses to leave dry-run now says why.
- Added the reason to Integrations, carried in a short-lived per-administrator
  transient and read once, so a fixed problem stops being reported as current.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.25 — 2026-08-08

- Added the recorded classification failure reason to the Review Queue. It was
  written to `last_error` on every failure and displayed nowhere, so a queue of
  failed items showed only that they had failed and left re-running them as the
  only available move. It is shown for failed and retrying items only, and
  sanitized through the same path as delivery errors.
- Added GA4 debug validation state to Integrations. Validation recorded its
  result silently, and live delivery refuses to leave dry-run until it passes,
  so the next step could fail with nothing on screen to explain it.
- Added progress feedback to the administration actions that call a provider or
  Google synchronously and can take tens of seconds. They submitted an ordinary
  form and gave no cursor change, no disabled button and no message, so the
  screen appeared inert and the action was pressed repeatedly, queueing
  duplicate provider calls.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.24 — 2026-08-08

- Fixed attribution never being captured on sites using Complianz. The script
  read consent from the WordPress Consent API and from Consent Mode entries in
  `dataLayer`. Complianz publishes through its own `cmplz_has_consent()`
  function and its own `cmplz_event_*` events, and its Consent API bridge is
  optional, so on such a site both known routes stayed silent while consent was
  fully granted. Consent read as never decided, no click identifier was ever
  stored, `attribution_ready` never became true, and no conversion could be
  delivered - with nothing logged, because as far as the script could tell the
  visitor had simply not chosen yet.
- Added Complianz as a recognised consent source, mapping its statistics and
  marketing categories exactly as the WordPress Consent API categories are
  mapped, and treating its synchronous answer as a real decision.
- Added listeners for `cmplz_event_marketing`, `cmplz_event_statistics`,
  `cmplz_status_change` and `cmplz_fire_categories`, so a decision made after
  page load still captures the click identifier from the landing URL.
- The WordPress Consent API remains authoritative wherever it is present, so
  existing sites are unaffected.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.23 — 2026-08-07

- Fixed credential fields giving no sign that a credential is stored. Every
  secret input rendered with an empty value whether one was saved or not, so a
  fully configured site looked identical to an untouched one. Administrators
  read that as a failed save and reasonably feared that saving again would erase
  the key.
- Added stored/not-set state to each credential field: a badge, a masked
  placeholder, and an explicit sentence stating that leaving the field blank
  keeps the stored credential. The mask is a placeholder attribute and never a
  value, which would otherwise be submitted and saved as the credential.
- Added explicit removal. Because a blank field means "keep", there was
  previously no way to remove a stored credential without editing the database.
  A typed replacement takes precedence over a removal request.
- A credential supplied through a constant now reports as stored, since
  `Crypto::secret()` prefers a constant over the database.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.22 — 2026-08-07

- Fixed DeepSeek reasoning starving the answer it was meant to improve. DeepSeek
  counts reasoning tokens against `max_tokens`, and both the suggestion and
  classification calls sent one flat budget for both, so enabling reasoning left
  too little for the JSON answer. The reply came back cut mid-object and was
  reported as a malformed response, several layers from the cause.
- Changed both calls to size the answer and the reasoning separately. The answer
  allowance now matches what the OpenAI provider is already given for the same
  call, and reasoning receives its own allowance on top rather than competing
  with it. Budgets remain bounded and predictable.
- Added detection of `finish_reason: length`, so a reply the model did not
  finish writing is reported as an exhausted budget rather than as malformed
  input. The two need opposite fixes.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.21 — 2026-08-07

- Fixed a setup scan failure being undiagnosable. `SetupSuggestionService` caught
  every failure with an unbound `catch (Throwable)` and replaced it with one
  generic sentence, so a credential that was never stored, a credential the
  provider rejected, and a host that blocks outbound HTTP all looked identical.
- Added the HTTP status to DeepSeek suggestion failures, which is what separates
  a rejected credential (401) from an unusable account (403), a wrong base URL
  (404) and a provider outage (5xx).
- Added `DiagnosticsService::describeFailure()`, which reports the exception
  class and reason after scrubbing credential-shaped runs — `Redactor` covers
  enquiry content but not API keys or bearer tokens — and length-caps the result.
- Added the redacted reason to the Setup failure notice, carried in a
  short-lived per-administrator transient rather than the query string, and
  cleared after one read so a stale reason cannot be mistaken for a fresh one.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.20 — 2026-08-07

- Fixed administration actions presenting rejected input as a site failure. All
  twenty `admin_post` handlers are now registered through a single guard, so a
  value the plugin is right to reject returns HTTP 400 carrying the reason
  instead of rendering WordPress's critical-error page, mailing the site
  administrator and logging a fatal.
- Changed unexpected administration faults to a generic HTTP 500 with the detail
  written to the error log and broadcast on `cf7aiv_admin_action_failed`, so
  internal messages are no longer echoed to the browser.
- Added `cf7aiv_admin_action_refused` and `CF7AIV_DEBUG` logging for refusals,
  which are otherwise visible only to the administrator who caused them.
- Fixed deliberate denials being reported as server faults where `wp_die` is
  filtered to throw rather than end the request.
- Fixed calibration offering an action that cannot succeed: unavailable sources
  are disabled, the submit button is disabled with a reason when no source has
  records, and a stale whitelist fingerprint is refused rather than raised.
- Fixed misleading administration defaults and unreadable Overview presentation:
  a neutral starter pack is preselected instead of a sector-specific one, policy
  identifiers are shown as readable labels, table captions no longer duplicate
  the visible panel heading, and the panel grid no longer overflows.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.19 — 2026-08-07

- Added a guided, five-step Setup workflow with safe starter-pack previews,
  evidence requirements, provider/destination safety status, and no-JavaScript
  form fallbacks.
- Reworked Integrations into provider-aware tabs with truthful save/failure
  status, reversible client-side discard, aggregate destination safety, and
  preserved high-risk action boundaries.
- Added model-capability-aware OpenAI reasoning controls, DeepSeek/OpenAI
  reasoning budgets, and regression coverage for provider payloads and Setup
  suggestion reasoning.
- Added responsive Playwright coverage for admin UX, consent-safe public
  attribution, reversible save round-trips, and exact synthetic cleanup.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.18 — 2026-08-06

- Added a read-only operational doctor and per-item diagnosis command that expose queue, attribution issue-code, consent, destination, mapping, and delivery-gate state without exposing enquiry content or secrets.
- Added delivery gate lifecycle observability and optional `CF7AIV_DEBUG` logging for skipped delivery decisions.
- Changed rejected administrator requests from uncaught critical errors to controlled HTTP 403 responses.
- Added focused diagnostics regression coverage and corrected tablet Overview distribution-table overflow.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.17 — 2026-08-04

- Fixed queued work being discarded. `Queue::enqueue()` and `Queue::schedule()`
  passed Action Scheduler's `$unique` flag, whose predicate matches only hook and
  group and ignores arguments, so a single pending action per hook was permitted
  for an entire site. Every per-item job enqueued while another was waiting was
  dropped silently, with no error recorded. Observed on a live site as four
  enquiries submitted seconds apart producing one classification and three items
  left in `captured` with `classification_attempts` at zero. Deduplication now
  uses `as_has_scheduled_action()`, which compares arguments.
- Fixed attribution never being captured on sites that publish consent through
  Google Consent Mode. The script re-collected only on the WordPress Consent API
  event, which such sites never emit, so a click identifier present on the
  landing URL was never stored, `attribution_ready` never became true, and no
  conversion could be dispatched. Consent defaults and updates pushed to
  `dataLayer` are now observed.
- Fixed stored attribution being deleted before consent was known. `collect()`
  runs on every page load and discarded storage whenever consent was not granted,
  but Consent Mode publishes asynchronously, so an undecided state was treated as
  a refusal and destroyed the click identifier captured on the landing page.
  Attribution is now discarded only once a decision has actually been made.
- Skipped storage writes when the attribution payload is unchanged, so a Consent
  Mode push and a WordPress Consent API event describing the same decision still
  result in exactly one write.
- Schema version 10 is unchanged; no migration is required.

## 0.2.16 — 2026-08-03

- Replaced raw Overview and Tracking Health output with semantic summaries,
  status badges, descriptions, and accessible local table regions.
- Kept wide administration tables locally scrollable and made all six plugin
  screens responsive across desktop, tablet, and narrow viewports.
- Grouped native WordPress fields, controls, and actions consistently across
  integration, policy, and setup workflows.
- Added regression coverage for keyboard focus, forced colours, badge text,
  document overflow, and the absence of raw preformatted status output.
- Corrected the Microsoft transport-test disclosure to describe its required
  one-off synthetic anonymous ID accurately.
- Excluded local top-level `.superpowers` plans and evidence from release
  archives.
- Kept schema version 10 unchanged; no migration is required.

## 0.2.15 — 2026-08-03

- Parsed Microsoft CAPI's documented nested `error.details` validation envelope
  while retaining only bounded provider codes and property paths.
- Added a one-off synthetic anonymous ID to the guarded zero-value,
  consent-denied transport diagnostic because CAPI requires at least one
  supported `userData` identifier.
- Refused identifier-free Microsoft events locally before transport rather
  than making a request CAPI must reject. Schema remains version 10.

## 0.2.14 — 2026-08-03

- Conformed Microsoft UET Conversion API delivery to the documented top-level
  `data` batch and fail-closed `continueOnValidationError` contract.
- Reused the deterministic event ID as `customData.transactionId` so retries
  preserve one event/transaction identity for deduplication.
- Added Microsoft-specific response classification for explicit batch errors,
  permanent authentication/validation failures, retryable rate limits and
  transient failures without storing raw provider bodies.
- Added a guarded administrator and WP-CLI Microsoft transport test that sends
  one zero-value, consent-denied diagnostic event and does not
  alter destination settings or mappings.
- Kept enhanced matching off by default and added no IP address, user agent, or
  raw identity collection. Schema remains version 10 with no migration.

## 0.2.13 — 2026-08-03

- Scoped operational submission tokens to each form and browser-page lifecycle;
  sent and failed-mail terminal events rotate only the submitted form, while
  validation failures retain the active attempt token.
- Bound GA client/session identity to one configured direct-GA4 or server-GTM
  Measurement ID, including custom prefixes and GS1/GS2 cookies, and fail
  closed when multiple properties or cookie pairs are plausible.
- Added a reusable persistent-source locator contract and a form/time/token
  qualified, bounded CFDB7 lookup that remains correct under high row volume.
- Extended bounded public site-profile scanning through explicit filters and
  locked provider evidence canonicalization with controlled adapter tests.
- Added a disposable WordPress 6.9/7.0.2, PHP 8.1/8.3, Flamingo/CFDB7 browser
  matrix. Schema remains version 10; no migration is required.
- Removed legacy operational tokens from the PHP attribution fallback and
  bounded builder-filter content and form references before scanner processing.

## 0.2.12 — 2026-08-03

- Assigned the published policy dimension locally from each allowed evidence
  code instead of trusting redundant provider-supplied dimension metadata.
- Prevented intermittent fail-closed classifications when DeepSeek returned an
  allowed code with a different independently allowed dimension value.
- Preserved strict rejection of unknown evidence codes, non-whitelisted fields,
  and excerpts that are not grounded in their named request field.

## 0.2.11 — 2026-08-03

- Kept a fresh, operational CF7-to-CFDB7 source-link token in page memory when
  analytics and marketing consent are denied or unavailable.
- Preserved strict consent behavior by storing no attribution in localStorage
  or cookies and leaving every tracking field empty without consent.
- Rotated the ephemeral source-link token after each completed submission so
  repeated no-consent submissions remain distinct.

## 0.2.10 — 2026-08-03

- Instructed DeepSeek to include every available grounded supporting assertion
  until the chosen tier's `minimum_supporting_evidence` is satisfied.
- Prevented otherwise complete opportunities from stopping at Needs review
  after the provider returned only the tier's required evidence groups.
- Kept insufficient-evidence behavior fail-closed by requiring the provider to
  select a satisfiable lower tier or return insufficient information.

## 0.2.9 — 2026-08-03

- Added the exact published evidence code-to-dimension mapping and concrete JSON
  examples of every allowed pair to DeepSeek v2 classification instructions.
- Prevented intermittent fail-closed classifications when JSON mode paired an
  allowed evidence code with the wrong independently allowed dimension.
- Preserved strict local validation and kept tier, outcome, evidence excerpts,
  and request-controlled enquiry text out of system-level examples.

## 0.2.8 — 2026-08-02

- Constrained assisted-setup provenance to the exact finalized pack, page, and
  form identifiers supplied to the provider.
- Prevented providers from returning page URLs or invented references that the
  fail-closed local validator must reject.
- Applied the same dynamic provenance enum to DeepSeek JSON instructions and
  OpenAI strict JSON Schema, with provider-wire regressions for both routes.

## 0.2.7 — 2026-08-02

- Bounded assisted setup to 20 compact suggestions and increased DeepSeek
  output headroom so larger public site profiles return complete JSON.
- Kept partial and truncated model output fail-closed while correcting the
  reproducible 1,500-token truncation seen during Mosaic production testing.
- Added regressions that pin the shared suggestion bound, DeepSeek output
  limit, and explicit compact-count provider instruction.

## 0.2.6 — 2026-08-02

- Normalized WordPress admin form input before settings, policy, mapping,
  lifecycle, setup and review handlers process it.
- Fixed advanced policy JSON imports that failed because WordPress recursively
  slashes request values before dispatching `admin-post.php` actions.
- Added a full-handler regression using recursively slashed Training and
  Education policy JSON, a form-specific policy key and the guarded nonce path.

## 0.2.5 — 2026-08-02

- Corrected the release test log sink so Linux runners cannot create a
  repository-root `NUL` artifact before packaging.
- Hardened the packager and archive verifier against Windows-reserved device
  names, reserved names with extensions, trailing dots/spaces, alternate data
  stream colons, and other Windows-unsafe path characters.
- Added focused behavioral regressions plus an explicit post-build workflow
  check for one canonical plugin root with no unsafe archive entries.
- Supersedes 0.2.4, whose published ZIP contained an unintended `NUL` entry;
  the 0.2.4 tag and release remain unchanged for audit history.

## 0.2.4 — 2026-08-02

- Made public attribution storage fail closed unless a configured WordPress
  Consent API grant, or a valid Google Consent Mode fallback when that authority
  is unavailable or unqualified, permits the relevant category.
- Made WordPress Consent API decisions authoritative over stale Google Consent
  Mode state, including opt-out-by-default configurations.
- Removed disallowed local attribution and injected Contact Form 7 fields on
  denied, withdrawn, and partial consent; rejected unsafe stored URLs before
  they can survive filtered attribution state.

## 0.2.3 — 2026-07-30

- Added strict GA4 debug validation, a dedicated zero-value transport test and
  an isolated configured-provider classification-to-delivery self-test.
- Corrected grounded evidence evaluation so absent optional facts do not
  override satisfied required/minimum-supporting evidence.
- Added a reproducible release packager with semantic tag/version agreement,
  distributable credential scanning, one-root ZIP verification and SHA-256
  output.
- Added a private-repository GitHub Actions workflow that tests, lints, packages
  and publishes verified WordPress release assets from `vMAJOR.MINOR.PATCH`
  tags.
- Expanded the GitHub maintainer/operator README while keeping `readme.txt`
  focused on WordPress.org.

## 0.2.2 — 2026-07-30

- Added the controlled GA4 and isolated pipeline acceptance-test services.
- Verified one plugin transport event in Greensleeves GA4 and a test-only
  Secondary Google Ads import outside bidding goals.

## 0.2.1 — 2026-07-27

- Added an optional `server_gtm` destination that sends GA4 Measurement Protocol-shaped qualified events to an existing HTTPS tagging-server domain.
- Added stable transaction/event IDs, Google click identifiers, client/session context, actual consent, and separately enabled consented hashes to the sGTM event while excluding Microsoft identifiers, enquiry text, and raw identity.
- Added disabled/dry-run defaults, HTTPS-only endpoint sanitization, encrypted write-only `CF7AIV_SERVER_GTM_API_SECRET` support, destination registration, and local no-network dry-run payloads.
- Kept direct GA4 as the recommended simple route and exposed existing sGTM only in Advanced mode with native Google Ads tag, Primary-goal, and deduplication guidance.
- The plugin does not provision Google Cloud, call the Google Ads API, use OAuth, or require a developer token.

## 0.2.0 — 2026-07-23

- Added six domain starter packs plus Custom business, a guided Setup/Testing/Live lifecycle, and Simple/Advanced administration modes.
- Added optional public page and safe form-structure analysis with pending provenance-backed suggestions that require explicit accept/edit/reject decisions.
- Added `cf7aiv-policy/v2`, universal outcomes, source-grounded evidence, exclusion/ambiguity evaluation, deterministic stored summaries, and diagnostic-only model confidence.
- Preserved `cf7aiv-policy/v1` for shadow/review compatibility and added draft-only `PolicyUpgrader` conversion; no upgrade publishes, reanalyses history, learns automatically, or dispatches historical data.
- Added explicit scoped historical calibration with a suggested 25-record sample, a fingerprint-bound active published-policy whitelist snapshot, immutable runs, activation-after-complete-queueing, and cancellation/write serialization.
- Added optional paid deeper verification for capable providers; disagreements, failures, and unsupported providers fail to Needs review.
- Added concurrency-safe global fingerprint de-duplication, review-revision compare-and-swap writes, atomic audited manual dispatch approval, and lease-token delivery recording.
- Added provider token-usage diagnostics and expanded privacy boundaries so evidence excerpts and provider-authored prose are never persisted.
- Advanced the reviewed database schema to version 10.

## 0.1.0 — 2026-07-22

- Added asynchronous CF7 capture with Flamingo and CFDB7 source adapters.
- Added encrypted attribution/consent snapshots, restrictive consent resolution, privacy tools, and optional consent-gated enhanced matching.
- Added versioned draft/published policies, care-home starter policy, redaction, DeepSeek JSON mode, and OpenAI Structured Outputs.
- Added deterministic eligibility, review/calibration workflows, GA4, Microsoft UET CAPI, and signed webhook destinations.
- Added idempotent delivery leases, retry handling, dry-run/debug validation, administration screens, health checks, retention, digest, and WP-CLI integration.
- Added Docker testbed mounts, open-source documentation, standalone verification, and release packaging.
- Changed classification eligibility to read current published/enabled mappings from the mapping repository and defer consent/identifier decisions to each destination worker.
- Added destination-specific readiness/configuration gates, separately nonced automation/GA/mapping transitions, GA debug-before-live enforcement, and durable legal-basis override audit history.
- Added recursive privacy validation for every importable policy prose/key path and completed dependent privacy, retention, and uninstall cleanup.
- Disabled DeepSeek V4 thinking for classification, supplied the complete JSON schema (including ambiguity-flag patterns), and added live regression coverage for truncated or schema-divergent responses.
- Replaced provider-authored stored summaries with deterministic reason-based summaries so personal details disclosed inside free text cannot be persisted in AI result metadata.
