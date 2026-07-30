# Changelog

All notable changes to CF7 AI Enquiry Validator are documented here. The project follows semantic versioning.

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
