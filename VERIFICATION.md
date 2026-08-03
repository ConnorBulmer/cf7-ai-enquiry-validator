# Release verification — 0.2.15 candidate

The 0.2.15 Microsoft UET Conversion API validation-hardening candidate was verified
locally on 2026-08-03. Automated verification used synthetic fixtures only and
made no external AI, analytics, or advertising request.

## 0.2.15 Microsoft UET CAPI verification

- Live diagnostic RED: Microsoft CAPI returned HTTP 400 `ValidationError` at
  `data[0].userData` because the original identifier-free diagnostic omitted
  every provider-supported user identifier.
- TDD RED: the documented nested `error.details` response regression failed
  because the classifier did not retain its safe rejected-property path.
- TDD RED: the controlled transport regression failed because its request did
  not include the one-off synthetic anonymous ID required by CAPI.
- GREEN: documented error envelopes now retain only bounded code/property
  diagnostics, identifier-free production events fail before transport, and
  the guarded diagnostic carries only its synthetic anonymous ID.
- TDD RED: `DeliveryTest.php` failed because the existing Microsoft request had
  no top-level `data` batch or stable `transactionId`.
- TDD RED: the Microsoft response-classifier regression failed because the
  provider-specific classifier did not exist.
- TDD RED: `MicrosoftUetTestServiceTest.php` failed because the guarded service
  did not exist; `AdminTest.php` then failed because no secured Microsoft test
  form/action existed.
- GREEN: `DeliveryTest.php`, `DeliveryReviewRegressionTest.php`,
  `MicrosoftUetTestServiceTest.php`, `AdminTest.php`, and `FoundationTest.php`
  all pass after the minimal contract and test-surface changes.
- Complete sorted standalone PHP suite: **25/25 PASS**. The pre-existing
  fail-closed migration retry diagnostic may be emitted by the in-memory test,
  but the test exits successfully.
- JavaScript attribution suite: **PASS**; JavaScript syntax check: **PASS**.
- Complete local PHP lint excluding generated `dist`: **247/247 PASS**.
- Release identities are all `0.2.15`; schema remains `10`; release tag
  contract is `v0.2.15`.
- Release build: `php tools/build-release.php --tag=v0.2.15 --output-dir=dist`
  - archive: `cf7-ai-enquiry-validator-0.2.15.zip`;
  - entries: **231** under one canonical `cf7-ai-enquiry-validator` root;
  - unsafe entries: **0**;
  - credential/site-pattern matches in reopened archive: **0**;
  - checksum file matches the reopened archive: **PASS**;
  - SHA-256:
    `ba1ce0c25519b427ffe506d77b451bd0fbd2ccc168469b8f10d4d6514f8cdfdf`.
- The Microsoft contract suite covers one-event `data` envelopes,
  `continueOnValidationError: false`, mapped name/value/currency, integer event
  time, stable event/transaction identity, consented click data, enhanced
  matching off by default, consent-gated hashes, dry-run redaction, accepted
  empty/malformed optional success bodies, explicit batch rejection, permanent
  400/401 failures, retryable 408/425/429/5xx failures, timeouts, and bounded
  provider code/field diagnostics with no raw body retention.
- The controlled transport suite proves exact confirmation, required encrypted
  configuration, one live zero-value request, denied advertising consent, one
  synthetic anonymous ID, no click/enhanced identity fields, no settings
  mutation, stable synthetic identity, safe success/failure receipts, and
  token/tag redaction.

## Previous 0.2.13 portability verification

The 0.2.13 portability hardening candidate was verified in a disposable local
WordPress matrix on 2026-08-03. No production site, provider, analytics
property, advertising account, submission content or credential was used.

## 0.2.13 disposable WordPress portability matrix

- Release identities are all `0.2.13`; the WordPress compatibility declaration
  is tested through 7.0 and the release tag contract is `v0.2.13`.
- Complete sorted standalone PHP suite: **24/24 PASS**; JavaScript attribution
  suite and syntax checks: **PASS**; tracked non-vendor PHP lint:
  **149/149 PASS**; release-package contract and identifier scan: **PASS**.
- Review RED/GREEN regressions prove the PHP fallback removes legacy
  `submission_id`, scanner extensions process at most 100,000 content
  characters and 100 raw form-reference entries per page, server-GTM-only configuration
  supplies its Measurement ID, conflicting Google route IDs fail closed, and
  both GS1 and GS2 GA session cookies parse for the configured property.
- Matrix: WordPress 6.9/PHP 8.1.34 and WordPress 7.0.2/PHP 8.3.31, each with
  Contact Form 7 6.1.6 plus either Flamingo 2.6.3 or CFDB7 1.4.0.
- Result: **4/4 cells PASS**, comprising **8/8 real-browser Playwright tests**
  and pre/post WordPress-loaded runtime assertions in every cell.
- Real Contact Form 7 AJAX coverage proves two forms receive distinct
  page-local attempt tokens; successful and failed-mail attempts rotate only
  the submitted form; invalid attempts retain their token; and a reload creates
  a fresh token.
- Real cookie coverage proves the configured `G-TEST123` property selects its
  exact GA session cookie while ignoring an unrelated property cookie. The
  JavaScript suite separately covers custom prefixes and fail-closed ambiguous
  pairs.
- Both persistent-source modes load successfully. CFDB7 resolves an exact
  form/time/token candidate even with 101 newer unrelated rows.
- The installed schema remains `10`; successful and failed-mail submissions
  both create classification items for asynchronous processing.
- Playwright is lockfile-pinned at 1.62.1; `npm audit` reports zero known
  vulnerabilities.
- Every cell ran under a unique Compose project and loopback port. Final
  checks found no retained `cf7aiv-portability` containers, volumes or
  networks.
- The matrix pins `wordpress:6.9-php8.1-apache` and
  `wordpress:7.0.2-php8.3-apache`; runtime assertions verify the exact core
  version rather than relying on mutable generic PHP image tags.
- A fresh one-root release build contains **229** entries and excludes tests,
  tooling, CI metadata, implementation plans, verification evidence and
  credential/site-identifier patterns. Independently verified archive SHA-256:
  `ffb9c0c28891781f135d03f878d3e96526d72834cf05f305b137f8af7d56ca95`.

The 0.2.10 DeepSeek supporting-evidence correction was verified locally and
against the configured isolated production provider on 2026-08-03.

## 0.2.10 DeepSeek supporting-evidence verification

- Release identities are all `0.2.10`; release tag is `v0.2.10`.
- Production reproduction: 10/10 privacy-safe configured DeepSeek pipeline
  runs returned the qualified tier but stopped at Needs review with
  `supporting_evidence_missing`; no production item or destination was touched.
- RED: the provider-wire regression failed because DeepSeek was not explicitly
  told to continue past required evidence until the chosen tier's supporting
  minimum was satisfied.
- Hypothesis probe: the single additional instruction was injected in-memory
  for 5 configured provider runs; all 5 produced Strong match, eligible,
  private, idempotent isolated deliveries with no production-state mutation.
- GREEN: the instruction is now part of v2 DeepSeek guidance. Deterministic
  evidence evaluation and fail-closed behavior remain unchanged.
- Complete sorted PHP suite: **24/24 PASS**; JavaScript checks: **PASS**;
  tracked non-vendor PHP lint: **145/145 PASS**.
- The verified 228-entry local archive SHA-256 is
  `54cd309f20002a43e66ad6bf934e7ae13effe0b8d07f2d3284d5b50b436c91ea`.

The 0.2.9 DeepSeek evidence-schema correction was verified locally on 2026-08-03.

## 0.2.9 DeepSeek evidence-schema verification

- Release identities are all `0.2.9`; release tag is `v0.2.9`.
- Production reproduction: configured DeepSeek returned valid JSON whose
  evidence code and dimension were independently allowed but incorrectly
  paired. Strict local validation rejected both attempts and kept production
  state untouched.
- RED: the provider-wire regression failed because v2 requests did not include
  the exact evidence code-to-dimension mapping or concrete pair examples.
  Review caught that two early full-result examples either promoted request
  text or assumed a tier-to-outcome pairing not required by custom policies.
- GREEN: DeepSeek now receives only the published mapping and JSON examples of
  its allowed code/dimension pairs. Tier, outcome, evidence excerpts, and
  request text remain outside those examples. Local catalog, schema, privacy,
  and grounding validation remain unchanged.
- Complete sorted PHP suite: **24/24 PASS**; JavaScript checks: **PASS**;
  tracked non-vendor PHP lint: **145/145 PASS**.
- The verified 228-entry local archive SHA-256 is
  `0f21d1781f0aad4f630f9c4eea83433e40b4ec602a275d136c69bb34ef528198`.
- The MT rollout verifier reports `valid: true`, version `0.2.9`, the same
  SHA-256, one safe plugin root, no credential-like text, and safe extraction.

The 0.2.8 assisted-setup provenance correction was verified locally on 2026-08-02.

## 0.2.8 assisted-setup provenance verification

- Release identities are all `0.2.8`; release tag is `v0.2.8`.
- Production reproduction: DeepSeek returned 20 structurally valid, safe
  suggestions, but all 20 cited HTTPS URLs because the provider schema did not
  expose the validator's opaque provenance identifiers.
- Corrected probe: a dynamic enum containing only the finalized `pack:key`,
  `page:ID`, and `form:ID` references produced 20/20 valid provenance values.
- DeepSeek and OpenAI wire-level regressions confirm both providers receive the
  same exact finalized provenance enum.
- Complete sorted PHP suite: **24/24 PASS**; JavaScript checks: **PASS**;
  tracked non-vendor PHP lint: **145/145 PASS**.
- The verified 228-entry local archive SHA-256 is
  `1add921ceede4e3ce0ec91d97016a6b1094791a2542aeecb36fd2129aa713205`.
- The MT rollout verifier reports `valid: true`, version `0.2.8`, the same
  SHA-256, one safe plugin root, no credential-like text, and safe extraction.

The 0.2.7 assisted-setup response correction was verified locally on 2026-08-02.

## 0.2.7 assisted-setup response verification

- Release identities: plugin header, `CF7AIV\VERSION`, and `readme.txt`
  `Stable tag` are all `0.2.7`; release tag is `v0.2.7`.
- Production reproduction: a safe 27,153-character Mosaic public-site profile
  returned HTTP 200 but stopped at the prior 1,500-token output limit, leaving
  truncated invalid JSON that correctly failed closed.
- Corrected probe: the shared 20-suggestion bound and 4,000-token DeepSeek
  output limit completed with `finish_reason: stop`, valid JSON, and exactly 20
  suggestions.
- Complete sorted PHP suite: **24/24 PASS**.
- JavaScript checks: **PASS**.
- Tracked non-vendor PHP lint: **145/145 PASS**.
- Build command: `php tools/build-release.php --tag=v0.2.7 --output-dir=dist`
  — **PASS**; JSON reports **228** entries.
- Local release archive SHA-256:
  `1949ef80c1316041382c58959d227ba025f50451c1bde923040a44d5fefa4c88`.
- The MT rollout verifier reports `valid: true`, version `0.2.7`, the same
  SHA-256, one safe plugin root, no credential-like text, and safe extraction.

The 0.2.6 WordPress admin-input correction was verified locally on 2026-08-02.

## 0.2.6 WordPress admin-input verification

- Release identities: plugin header, `CF7AIV\VERSION`, and `readme.txt`
  `Stable tag` are all `0.2.6`; release tag is `v0.2.6`.
- RED: a full-handler advanced policy import regression submitted recursively
  slashed Training and Education JSON, reproducing the production failure with
  `Policy JSON is invalid` before the correction.
- GREEN: the shared admin request helper now applies WordPress `wp_unslash()`
  recursively before any guarded handler processes the request. The same
  form-specific policy import succeeds and persists a validated draft.
- Complete sorted PHP suite: **24/24 PASS**.
- JavaScript commands: `node tests/AttributionJsTest.js` and
  `node --check assets/js/attribution.js` — **PASS**.
- Tracked non-vendor PHP lint: **145/145 PASS**.
- Build command: `php tools/build-release.php --tag=v0.2.6 --output-dir=dist`
  — **PASS**; JSON reports **228** entries and matching `0.2.6` plugin header,
  version constant, and stable tag.
- Local release archive SHA-256:
  `0a62668e26822277249ddf797e0dee12ba481015780d0cf8c05dcec0b745cec3`;
  the generated checksum matches independently.
- The MT rollout verifier reports `valid: true`, version `0.2.6`, the same
  SHA-256, one safe plugin root, no credential-like text, and safe extraction.

The 0.2.5 archive-safety correction was verified locally on 2026-08-02.

## 0.2.5 archive-safety verification

- Release identities: plugin header, `CF7AIV\VERSION`, and `readme.txt`
  `Stable tag` are all `0.2.5`; release tag is `v0.2.5`.
- Root-cause trace: the `v0.2.4` tag tree contains no `NUL` path, while its
  published ZIP contains one `cf7-ai-enquiry-validator/NUL` entry. The release
  workflow ran PHP tests before packaging, and `MigrationV9Test.php` configured
  `NUL` as its error log path; Linux therefore created a regular repository-root
  file that the filesystem packager included.
- RED: the focused release-package regression failed because the archive
  verifier accepted `cf7-ai-enquiry-validator/NUL`.
- GREEN: the same test passes after the shared source/archive path policy began
  rejecting Windows device names (including extensions and the reserved
  `COM¹`–`COM³` / `LPT¹`–`LPT³` forms), trailing dots/spaces, alternate data
  stream colons, and other Windows-unsafe characters. Safe names including
  `console.md` and `com10.php` remain accepted. Independent review found the
  superscript-device bypass; its focused regression was observed RED before the
  matcher fix and GREEN afterward.
- `MigrationV9Test.php` now uses `NUL` only on Windows and `/dev/null` elsewhere,
  preventing Linux test runs from creating repository artifacts.
- Complete sorted PHP suite command:
  `Get-ChildItem tests\*Test.php | Sort-Object Name | ForEach-Object { php $_.FullName; if ($LASTEXITCODE -ne 0) { throw "Failed: $($_.Name)" } }`
  — **24/24 PASS**.
- JavaScript commands: `node tests/AttributionJsTest.js` and
  `node --check assets/js/attribution.js` — **PASS**.
- Tracked non-vendor PHP lint: every sorted tracked `*.php` file outside
  `vendor/` was checked with `php -l` — **145/145 PASS**.
- Build command: `php tools/build-release.php --tag=v0.2.5 --output-dir=dist`
  — **PASS**; JSON reports **228** entries and matching `0.2.5` plugin header,
  version constant, and stable tag.
- Local release archive SHA-256:
  `1768fd3dac7b1234b00ec8601b8f4ad906c10106adfacc18337e81d2849fe04e`;
  the generated checksum matches independently.
- The workflow post-build verifier reopens the ZIP and reports one canonical
  `cf7-ai-enquiry-validator/` root with no Windows-unsafe or reserved paths.
- The MT rollout verifier command used `Test-Cf7AivRelease.ps1` with
  `-ExpectedVersion 0.2.5`; output: `valid: true`, version `0.2.5`, SHA-256
  `1768FD3DAC7B1234B00EC8601B8F4AD906C10106ADFACC18337E81D2849FE04E`.
  It independently verifies checksum, safe paths, one plugin root, version,
  credential-like text, and safe extraction.

The 0.2.4 consent-safe attribution release was verified locally on 2026-08-02.

## 0.2.4 consent-safe attribution verification

- Release identities: plugin header, `CF7AIV\VERSION`, and `readme.txt`
  `Stable tag` are all `0.2.4`; release tag is `v0.2.4`.
- Complete sorted PHP suite command:
  `Get-ChildItem tests\*Test.php | Sort-Object Name | ForEach-Object { php $_.FullName; if ($LASTEXITCODE -ne 0) { throw "Failed: $($_.Name)" } }`
  — **24/24 PASS**. `MigrationV9Test.php` emitted its pre-existing retry warning
  after passing; no test returned a non-zero exit code.
- JavaScript commands: `node tests/AttributionJsTest.js` and
  `node --check assets/js/attribution.js` — **PASS**.
- Non-vendor PHP lint command: every sorted `*.php` file outside `vendor/`
  was checked with `php -l` — **145/145 PASS**.
- Consent regression coverage confirms deny-by-default storage, an unqualified
  WordPress Consent API fallback to known Google Consent Mode, opt-out defaults,
  explicit denial, statistics-only and marketing-only filtering, unsafe stored
  URL rejection, consent grant after load, withdrawal cleanup, and idempotent
  repeated consent/CF7 hooks.
- Build command: `php tools/build-release.php --tag=v0.2.4 --output-dir=dist`
  — **PASS**; JSON reports **228** entries and matching `0.2.4` plugin header,
  version constant, and stable tag.
- Release contents: `dist/cf7-ai-enquiry-validator-0.2.4.zip` has one
  `cf7-ai-enquiry-validator/` root, no backslash entries, and the main plugin
  file with the expected `0.2.4` header and constant.
- SHA-256: `e7825c7b39dee052d1157c90401c3c0aac38a47e4c008ca0db3a841c206d06df`;
  the independently computed ZIP hash matches the generated `.sha256` asset.
- Source-tree and extracted-archive scans for credential-like values and quoted
  OAuth/API secret assignments: **0 matches**. No environment secrets or
  production submissions were used or recorded.

The 0.2.3 Google acceptance-testing delta was verified on 2026-07-30.

## 0.2.3 Google acceptance verification

- Complete standalone suite: **23/23 PHP test files PASS**.
- JavaScript attribution behavior and syntax: **PASS**.
- Non-vendor PHP lint: **142/142 files PASS**.
- Source and release-archive credential-pattern scans: **0 matches**.
- Docker plugin version/schema: `0.2.3` / `10`; automation remains `shadow`,
  every destination remains disabled/dry-run, and operational safety is
  `safe: true`.
- A fresh configured-DeepSeek isolated pipeline passed classification,
  grounded evidence, eligibility, delivery idempotency, and privacy checks
  without touching production state.
- GA4 property `275522660` and Google Ads customer `6926292172` were audited
  through their official APIs before any configuration change.
- The existing Google Ads custom goal was inspected and does not contain the
  plugin test action.
- `cf7aiv_transport_test` was registered as a GA4 key event with once-per-event
  counting and GBP currency.
- The linked Google Ads API exposed exactly one matching GA4 conversion action.
  Its import mutation passed `validateOnly` before execution.
- Conversion action `7702965342` is `ENABLED`, category `DEFAULT`,
  `primary_for_goal: false`, `include_in_conversions_metric: false`, and belongs
  to no custom conversion goal. It is named
  `CF7AIV Transport Test (Secondary - Do Not Bid)`.
- A fresh plugin-owned command passed strict GA4 validation and then sent one
  zero-value, synthetic, consent-denied, PII-free event:
  `cf7aiv-test-d8c21f62e29f5da83b3aaecf`.
- An independent GA4 Realtime Data API query then returned
  `cf7aiv_transport_test` with `eventCount: 2`, increased from the independently
  observed pre-run count of 1.
- The local plugin database contains no recent non-historical
  attribution-ready record. The synthetic test therefore proves plugin
  transport, GA4 receipt, linkage, import, and bidding isolation; it cannot
  prove attribution to a real ad click. Google states imported conversion data
  can take up to 24 hours to appear, and click attribution requires a genuine
  paid session.
- Release archive:
  `dist/cf7-ai-enquiry-validator-0.2.3.zip`, **253** entries, one correct plugin
  root, no backslash entries, and the main plugin file present. The final
  SHA-256 is reported alongside the generated artifact rather than embedded
  recursively.

## 0.2.2 verification

- Complete standalone suite: **23/23 PHP test files PASS**.
- JavaScript attribution behavior and syntax: **PASS**.
- Non-vendor PHP lint: **142/142 files PASS**.
- Source and release-archive credential-pattern scans: **0 matches**.
- Docker plugin version/schema: `0.2.2` / `10`.
- Docker safety after testing: automation `shadow`; GA4, sGTM, Microsoft,
  and webhook disabled and dry-run; operational safety `safe: true`.
- Strict plugin-owned GA4 `/debug/mp/collect` validation: **PASS** with
  `ENFORCE_RECOMMENDATIONS`.
- One approved live Measurement Protocol transport event:
  `cf7aiv_transport_test`, event ID
  `cf7aiv-test-379a5a7bfff6565dca7ab966`, zero value, GBP, denied advertising
  consent, synthetic client/session identifiers, no click ID, no identity.
  Google collection returned success after a successful strict preflight.
- After read-only Analytics reauthentication, an independent GA4 Realtime Data
  API query for property `275522660` returned
  `cf7aiv_transport_test` with `eventCount: 1`. This separately proves that the
  accepted transport event became visible in Greensleeves Analytics.
- Deterministic isolated pipeline: **PASS**; the real delivery worker evaluated
  the item twice and produced one in-memory zero-value delivery.
- Configured DeepSeek isolated pipeline: **PASS**; `primary_opportunity`,
  `qualified`, confidence `0.95`, grounded Strong match, one idempotent
  in-memory delivery, no PII/click ID in AI context or conversion envelope.
- The DeepSeek run exposed and verified a rule correction: missing optional
  facts no longer override satisfied required/minimum-supporting evidence.
  Missing required evidence, ambiguity, exclusions, ungrounded evidence, and
  contradictory grounded/missing claims still fail closed.
- Release archive:
  `dist/cf7-ai-enquiry-validator-0.2.2.zip`, **253** entries, one correct plugin
  root, no backslash entries, and the main file present. The final SHA-256 is
  reported alongside the generated artifact rather than embedded recursively.

## Historical 0.2.1 verification

The 0.2.1 Google-destination delta was verified on 2026-07-27 with the complete standalone suite, production PHP lint, source/archive credential scans, and release-archive inspection. On 2026-07-28, Docker Desktop was recovered through its CLI and the current 0.2.1 source was activated and smoke-tested against the Greensleeves WordPress pilot.

## Release identity

- Main plugin header: `0.2.1`.
- `CF7AIV\VERSION`: `0.2.1`.
- Current-session WordPress activation: `0.2.1` active in the Docker pilot.
- Reviewed database target and installed schema: `10`.
- WordPress.org stable tag: `0.2.1`.
- Release archive: `dist/cf7-ai-enquiry-validator-0.2.1.zip`.

## Standalone verification

The complete fresh standalone run executed every sorted `tests/*Test.php` file directly:

- PHP tests: **21/21 PASS**.
- JavaScript attribution behavior: **PASS**.
- `node --check assets/js/attribution.js`: **PASS**.
- Non-vendor PHP lint: **138/138 files PASS**.

The PHP tests were:

1. `AdminPrivacyTest.php`
2. `AdminTest.php`
3. `ApprovalAtomicityTest.php`
4. `AssistedSetupTest.php`
5. `CalibrationRunLifecycleTest.php`
6. `CalibrationV2Test.php`
7. `CaptureConsentHashRegressionTest.php`
8. `ClassificationTest.php`
9. `ConsentRegressionTest.php`
10. `CookieRegressionTest.php`
11. `DedupRegressionTest.php`
12. `DeliveryReviewRegressionTest.php`
13. `DeliveryTest.php`
14. `FoundationTest.php`
15. `GroundedEvidenceTest.php`
16. `IntakeTest.php`
17. `IntegrationTest.php`
18. `MigrationV9Test.php`
19. `PolicyV2Test.php`
20. `UninstallTest.php`
21. `VerificationPassTest.php`

`AdminTest.php`, `CalibrationV2Test.php`, and `ClassificationTest.php` cover the separate historical panel and its privacy binding: the panel renders the suggested 25-record maximum, optional form scoping, the actual safe-field union from currently published policies, the sensitive-free-text warning, a fingerprint, and required explicit opt-in. A stale fingerprint schedules nothing. The normalized snapshot follows every stratum, classification action, continuation, and retry, and the worker intersects it with the current policy so later policy widening cannot disclose a newly added field.

`AdminTest.php` and `DeliveryTest.php` additionally cover the 0.2.1 existing-sGTM route: disabled/dry-run defaults, HTTPS-only sanitization, bounded identifiers, encrypted write-only credential persistence, Simple/Advanced visibility, concrete destination registration, first-party tagging-server URL construction, GA4-compatible event shape, stable transaction/event IDs, Google-only click identifiers, actual consent, consent-gated hashes, response handling, and a no-network secret-redacted dry-run.

## Secret and forbidden-persistence scans

Source scans returned:

- API-key/Bearer/private-key patterns: **0 matches**.
- Production `minimum_confidence` / `below_confidence_threshold` automation fields: **0 matches**.
- Production SQL that inserts or updates evidence excerpts or provider request bodies: **0 matches**.

The archive scan returned **0 secret-pattern matches**. Provider-authored prose and grounded excerpts are validated transiently and are not persisted.

## Current Docker migration and safety verification

Commands completed successfully:

```text
docker compose up -d wordpress
docker compose run --rm wpcli wp cf7aiv migrate
docker compose run --rm wpcli wp option get cf7aiv_schema_version
docker compose run --rm wpcli wp cf7aiv status
docker compose run --rm wpcli wp cf7aiv health
docker compose run --rm wpcli wp cf7aiv dry_run
```

Observed state:

- Migration completed at schema `10`; expected and installed versions both reported `10`.
- Plugin `0.2.1` was active during the 2026-07-28 verification.
- Automation is `shadow`.
- GA4, existing server-side GTM, Microsoft UET, and webhook are disabled and dry-run.
- Action Scheduler is available.
- Flamingo is available with 6,456 source records; CFDB7 is unavailable, which is informational.
- `dry_run` reported `safe: true` and explicitly reported that no network request was sent.
- Public homepage returned HTTP `200`.
- Unauthenticated `/wp-admin/` returned HTTP `302` to the local login page.
- A targeted final WordPress log scan covered the post-recovery verification window and found **0 PHP warning/fatal/parse/notice or critical-error matches**.
- The migration CLI verifies the installed schema after migration and fails instead of printing success if a fail-closed index check prevents schema `10`.
- An upgrade regression test and the live status check verify that installations with older saved settings gain the new existing-sGTM defaults without replacing configured or extension-owned values. Operational safety now checks every configured destination rather than a fixed legacy list.

The CLI image's bundled MariaDB client could not authenticate to the MySQL 8 server because its `caching_sha2_password` plugin is absent. Schema and preservation inspection therefore used temporary WP-CLI probes through WordPress's active database connection. Those probes were removed before linting and packaging.

## Schema 10 inspection

The live database inspection found every required table, column, and index:

- `cf7aiv_items`: 44 columns, 10 indexes; v2 outcome/evidence/verification/provider-usage fields, review revision, approval ID, historical/calibration/run fields, unique global fingerprint, and unique approval index all present.
- `cf7aiv_calibration_runs`: 10 columns, 2 indexes; primary run ID plus `activated_at` and `cancelled_at` present.
- `cf7aiv_setup_suggestions`: 8 columns, 2 indexes; value, provenance, pending-review state, and status/type index present.
- `cf7aiv_policies`: 16 columns, 3 indexes; policy format, pack key, and pack version present.
- `cf7aiv_review_suggestions`: 12 columns, 3 indexes; reviewed outcome and unique dispatch-approval ID present.

No required column or index was missing. The primary, global fingerprint, item approval, and review-audit approval indexes inspected as unique single-column indexes where required.

## Pilot preservation and compatibility

Before and after the idempotent schema-10 migration, safe fingerprints and counts proved:

- The encrypted DeepSeek option exists.
- Its ciphertext length and SHA-256 fingerprint were unchanged; it was never displayed or decrypted.
- The original `care-home-pilot-v2-20260722` row remained published and byte-for-byte unchanged.
- All **20** plugin items remained unchanged.
- All **20/20** remained `is_calibration=1`, `is_historical=1`, and `is_shadow=1`.
- Deliveries remained **0**.

The original named care policy declares `cf7aiv-policy/v1`. It remains readable but v1 evidence evaluation is always **Needs review**, so it cannot auto-dispatch. `PolicyUpgrader` created a separate `care-home-pilot-grounded-v2-draft-20260723` row:

- status `draft`;
- `published_at` null;
- schema `cf7aiv-policy/v2`;
- pack `care_supported_living`;
- 10 source tiers retained;
- six universal outcomes;
- seven evidence definitions;
- migration source recorded as `cf7aiv-policy/v1`.

The original policy remained unchanged, and there were zero pending/running classification or delivery actions after draft creation. No historical reanalysis was scheduled.

## Scanner and calibration safety

A real `SiteProfileScanner` pass inspected **29 local public pages** and **25 Contact Form 7 forms**. To prohibit external provider traffic, the pass used an in-process fake `PolicySuggestionProviderInterface` implementation. It made one fake-provider call, zero external requests, and stored one suggestion as `pending` with `page:28` provenance. No suggestion was accepted or published automatically.

The calibration tests exercise deterministic synthetic/fake sources, exact quotas, stratification, bounded pages, explicit opt-in, cancellation races, retries, and zero-delivery behavior. The 20 live historical records were not rescheduled or reanalysed.

Provider and destination usage during release verification:

- DeepSeek classification requests: **1 synthetic, non-identifying smoke request** on 2026-07-28. The configured `deepseek-v4-flash` route returned `qualified_lead` / `qualified`, model confidence `0.95`, no ambiguity flags, 342 input tokens, 55 output tokens, zero reasoning tokens, and one provider call. The result passed the plugin's fixed schema validator.
- Historical-message DeepSeek requests during this verification: **0**.
- OpenAI requests: **0**.
- GA4 requests: **0**.
- Microsoft UET requests: **0**.
- Webhook requests: **0**.
- Existing-sGTM requests: **0**.

## Administration UI

The standalone administration tests verify:

- all six domain packs plus Custom business;
- pending scan suggestions with accept/edit/reject controls and provenance;
- Setup/Testing/Live lifecycle with automation left in shadow;
- separate historical calibration, default 25, whitelist preview, warning, form/date/source scope, and explicit opt-in;
- Simple Review Queue labels Strong match / Needs review / Not eligible without model confidence;
- Advanced diagnostic confidence, evidence, provider usage, source detail, and policy controls;
- policy publication and Live lifecycle do not enable automation;
- optional deeper-verification cost/token warning appears only for capable providers and is forced off otherwise.
- direct GA4 is recommended in Simple mode while existing sGTM configuration is confined to Advanced mode;
- existing sGTM setup states that the plugin does not provision Google Cloud or use the Google Ads API.

The required in-app browser runtime initialized, but selecting `iab` returned `Browser is not available: iab`; the prescribed recovery check reported an empty browser list. The task owner then explicitly authorized the bundled terminal Playwright fallback with a temporary local administrator.

That authenticated fallback returned `releaseBrowserVerified: true`. It verified all seven packs, pending suggestion provenance plus accept/edit/reject controls, Setup/Testing/Live copy, the 25-record historical default, the active-published-policy whitelist fingerprint and warning, Simple outcomes without confidence, Advanced policy JSON/evidence/confidence diagnostics, explicit publication copy with automation still shadow, capable-provider cost/token warning, incapable-provider restriction, and Simple-mode restoration without losing provider settings. It saved:

- `output/playwright/cf7aiv-setup-simple.png`
- `output/playwright/cf7aiv-review-simple.png`
- `output/playwright/cf7aiv-policy-advanced.png`
- `output/playwright/cf7aiv-review-advanced.png`
- `output/playwright/cf7aiv-integrations-openai-restricted.png`
- `output/playwright/cf7aiv-v020-verify.log`

A local-only route was installed before authentication. It blocked 14 external requests emitted by the host WordPress administration environment; no AI provider or plugin destination request was contacted. Cleanup removed the temporary administrator and restored Setup, Simple mode, shadow automation, verification off, and DeepSeek selection.

## Concurrency and fail-closed evidence

The release suite covers:

- globally unique cross-source fingerprints and source-link upgrade without duplicates;
- fail-closed migration when historical duplicate fingerprints exist, without deleting or reparenting rows, plus replacement of a wrong composite fingerprint index by the required unique single-column index;
- review-revision compare-and-swap writes that preserve concurrent administrator truth;
- atomic, unique audit linkage for approve-and-dispatch;
- transactionally locked policy and mapping publication that validates/publishes the target before superseding the prior version, single-version mapping enablement, and stale enabled-version suppression during resolution;
- calibration activation only after complete queueing;
- row-lock serialization of calibration cancellation with item, result, failure, hook, and retry mutations;
- immutable historical/calibration/shadow delivery blocks;
- delivery idempotency claims and lease-token recording so stale workers cannot record another worker's attempt.

## Release archive

The package script was run as:

```powershell
.\tools\package_wordpress_plugins.ps1 -PluginSlug cf7-ai-enquiry-validator -Version 0.2.1 -MainFile cf7-ai-enquiry-validator.php
```

Final archive inspection:

- one `cf7-ai-enquiry-validator/` root;
- **249** file entries;
- **0** entries outside the root;
- **0** backslash entry names;
- expected main file, policy packs, admin CSS, provider/capability interfaces, tests, and docs present;
- source files: **249**;
- package files: **249**;
- missing source files: **0**;
- extra package files: **0**;
- source/package SHA-256 mismatches: **0**;
- archive secret-pattern matches: **0**.

The package includes the same source state documented here. No encrypted option value, provider request, external destination request, or raw historical message entered the source tree or archive.
