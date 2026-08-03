# Changelog

All notable changes to CF7 AI Enquiry Validator are documented here. The project follows semantic versioning.

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
