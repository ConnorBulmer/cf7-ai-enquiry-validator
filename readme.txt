=== CF7 AI Enquiry Validator ===
Contributors: cf7aiv-contributors
Tags: contact form 7, flamingo, conversion tracking, ga4, privacy
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.18
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Classifies stored Contact Form 7 enquiries asynchronously and sends only deterministically eligible, consent-permitted conversions.

== Description ==

CF7 AI Enquiry Validator keeps Contact Form 7 submission handling independent from AI and advertising services. It retrieves source-owned content from Flamingo or CFDB7 after submission, sends only explicitly whitelisted and redacted non-identity context to DeepSeek or OpenAI, and holds every new policy in shadow mode until an administrator publishes it.

Destinations include direct GA4 Measurement Protocol, GA4-protocol delivery through an existing server-side GTM tagging server, Microsoft UET CAPI, and signed HTTPS webhooks. Destination mappings begin as disabled drafts and destination settings begin in dry-run mode. Direct GA4 is the recommended simple route; existing sGTM appears in Advanced mode and does not require the plugin to provision Google Cloud or use the Google Ads API.

Version 0.2.0 adds guided Setup, Testing, and Live lifecycle stages; six domain starter packs plus Custom business; optional public page/form analysis whose suggestions require accept/edit/reject; universal v2 outcomes; grounded evidence; Simple and Advanced views; and a separate, explicit historical calibration workflow that suggests 25 records. Model confidence is diagnostic only and never controls dispatch.

Existing v1 policies remain readable in shadow/review mode. Upgrades create reviewed v2 drafts and never publish, reanalyse history, learn from reviews, or dispatch historical records automatically. Optional deeper verification is a second paid AI call, is off by default, and is offered only by capable providers.

== Installation ==

1. Upload the plugin folder or install the release ZIP.
2. Activate **CF7 AI Enquiry Validator**.
3. Confirm Contact Form 7 and Flamingo or CFDB7 are available.
4. Open **Enquiry Validator > Setup** and keep automation in shadow mode.
5. Add provider and destination secrets through constants or the write-only settings fields.
6. Import or create a draft policy, optionally opt in to a scoped historical calibration sample, review results, then publish deliberately.

See `README.md` and `docs/SETUP.md` for the complete safe rollout.

== Frequently Asked Questions ==

= Does AI run during form submission? =

No. Capture is local and classification is queued after storage.

= Is contact identity sent to AI? =

No. Names, email addresses, phone numbers, IPs, files, click IDs, attribution, consent, hashes, and all `cf7aiv_` fields are excluded.

= Does activation enable advertising uploads? =

No. Automation defaults to shadow and every destination defaults to disabled dry-run.

= Does a high AI confidence score make an enquiry eligible? =

No. Confidence is an Advanced-mode diagnostic only. Version 2 eligibility requires grounded policy evidence and all independent lifecycle, mapping, consent, attribution, and delivery gates.

= Does the plugin learn from my reviews or reanalyse old enquiries? =

No. Corrections create draft suggestions only. Historical calibration is separate, explicit, calibration-only, and cannot deliver.

== Privacy ==

The plugin stores source references and encrypted attribution/consent snapshots, not a second raw enquiry copy. Optional enhanced matching stores normalized hashes only when `ad_user_data` is granted. See `docs/PRIVACY-AND-AI.md`.

== Changelog ==

= 0.2.18 =
* Adds a read-only operational doctor and per-item diagnosis command.
* Exposes safe attribution issue-code counts, queue backlog state, destination/mapping readiness, and delivery gate reasons.
* Returns controlled HTTP 403 responses for rejected administrator requests and adds tablet Overview overflow coverage.
* Keeps schema version 10 unchanged; no migration is required.

= 0.2.17 =
* Fixes queued work being discarded: Action Scheduler's `$unique` flag ignores arguments, so only one action per hook could be pending site-wide and every per-item job enqueued behind another was dropped silently.
* Fixes attribution never being captured on sites publishing consent through Google Consent Mode, which never emit the WordPress Consent API event the script listened for.
* Fixes stored attribution being deleted before consent was known, which destroyed the click identifier captured on the landing page.
* Skips storage writes when the attribution payload is unchanged, so one consent decision arriving by two routes still stores exactly once.
* Keeps schema version 10 unchanged; no migration is required.

= 0.2.16 =
* Replaces raw Overview and Tracking Health output with semantic summaries, status badges, descriptions, and accessible local table regions.
* Keeps wide administration tables locally scrollable and all six plugin screens responsive across desktop, tablet, and narrow viewports.
* Groups native WordPress controls and actions consistently, adds forced-colour and focus coverage, and corrects the Microsoft synthetic-ID disclosure copy.
* Excludes local top-level `.superpowers` plans and evidence from release archives.
* Keeps schema version 10 unchanged; no migration is required.

= 0.2.15 =
* Parses Microsoft CAPI's documented nested validation-error envelope while retaining only bounded error codes and property paths.
* Adds a one-off synthetic anonymous ID to the zero-value transport diagnostic because Microsoft requires at least one supported user-data identifier.
* Refuses identifier-free Microsoft requests locally instead of sending a request that CAPI must reject; schema remains version 10.

= 0.2.14 =
* Wraps each Microsoft UET Conversion API event in the documented data batch and disables partial validation continuation.
* Uses one stable event and transaction identity across retries and safely classifies Microsoft batch, authentication, rate-limit, transient, and malformed-body responses.
* Adds an exact-confirmation Microsoft transport test that sends one zero-value, consent-denied diagnostic event without changing production settings.
* Keeps Microsoft account IDs, UET tag details, credentials, and conversion-goal configuration outside reusable plugin source; schema remains version 10.

= 0.2.13 =
* Keeps operational submission tokens local to each form and browser-page lifecycle, rotating only the submitted form after sent or failed mail while retaining the token through validation failures.
* Selects GA client/session identity only for one configured direct-GA4 or server-GTM property, supports GS1/GS2 cookies, and fails closed on ambiguity.
* Adds a reusable persistent-source locator contract, qualified bounded CFDB7 lookup, extensible bounded site-profile scanning, and provider evidence regressions for cross-site portability.
* Adds a disposable WordPress 6.9/7.0.2, PHP 8.1/8.3, Flamingo/CFDB7 browser matrix; schema remains version 10 with no migration required.
* Removes legacy operational tokens from PHP fallback cookies and bounds builder content and form-reference extension inputs before processing.

= 0.2.12 =
* Assigns the published policy dimension locally from each allowed evidence code instead of trusting redundant provider metadata.
* Prevents intermittent fail-closed classifications when DeepSeek returns an allowed code with a different dimension value.
* Continues to reject unknown evidence codes, non-whitelisted fields, and ungrounded excerpts.

= 0.2.11 =
* Keeps a fresh source-link token in page memory when analytics and marketing consent are denied or unavailable.
* Allows privacy-respecting CF7 enquiries to join their CFDB7 source row without storing attribution in localStorage or cookies.
* Rotates the operational token after each completed submission while keeping every tracking field empty without consent.

= 0.2.10 =
* Tells DeepSeek to include available grounded supporting evidence until the chosen tier's minimum is satisfied.
* Prevents complete enquiries from stopping at Needs review after returning only their required evidence groups.
* Keeps the fallback fail-closed when supporting evidence is genuinely absent.

= 0.2.9 =
* Gives DeepSeek the exact evidence code-to-dimension mapping plus concrete JSON examples of every allowed pair for v2 classification requests.
* Prevents intermittent fail-closed classifications caused by independently valid evidence codes and dimensions being paired incorrectly.
* Keeps tier, outcome, evidence excerpts, and request-controlled enquiry text out of system-level examples while preserving strict validation.

= 0.2.8 =
* Constrains assisted-setup provenance to the exact finalized pack, page, and form identifiers supplied to the provider.
* Prevents providers from returning page URLs or invented references that the fail-closed local validator must reject.
* Applies the same dynamic provenance enum to DeepSeek JSON instructions and OpenAI strict JSON Schema.

= 0.2.7 =
* Bounds assisted setup to 20 compact suggestions and gives DeepSeek enough output headroom to return complete JSON for larger public site profiles.
* Keeps partial or truncated model output fail-closed while avoiding the reproducible 1,500-token truncation failure.
* Adds focused payload regressions for the shared suggestion bound, DeepSeek output limit, and explicit compact-count instruction.

= 0.2.6 =
* Normalizes WordPress admin form input before processing settings, guided policies, advanced policy JSON, mappings, lifecycle controls, and setup actions.
* Fixes advanced policy JSON imports that WordPress had escaped with request slashes before the plugin decoded them.
* Adds a regression that submits recursively slashed Training and Education policy JSON through the real admin handler.

= 0.2.5 =
* Prevents test logging from creating repository artifacts on Linux release runners.
* Rejects Windows-unsafe or reserved archive path components, including device names, trailing dots/spaces, and alternate data stream colons.
* Adds a post-build release check for one canonical plugin root with no unsafe archive entries.

= 0.2.4 =
* Stores public attribution only when a configured WordPress Consent API grant or Google Consent Mode fallback proves the relevant category.
* Removes disallowed attribution from local storage, the plugin cookie, and injected Contact Form 7 fields when consent is denied, withdrawn, or partial.
* Rejects unsafe stored attribution URLs and keeps WordPress Consent API decisions authoritative over stale Google Consent Mode values.

= 0.2.3 =
* Renamed the controlled GA4 confirmation and receipt profile so the dedicated zero-value test remains truthful when an administrator registers it as a GA4 key event for Secondary Google Ads acceptance testing.

= 0.2.2 =
* Added strict GA4 validation, a confirmed dedicated transport test, and an isolated configured-AI pipeline self-test.
* Treats missing optional evidence according to published tier requirements while retaining fail-closed ambiguity, contradiction, exclusion, and required-evidence gates.

= 0.2.1 =
* Added an optional existing-sGTM destination using GA4 Measurement Protocol shape, Advanced-mode setup guidance, Google click/consent/deduplication fields, encrypted write-only credentials, and local dry-run safeguards.

= 0.2.0 =
* Added guided v2 policy packs, grounded evidence, universal outcomes, Simple/Advanced administration, explicit historical calibration, optional capable-provider verification, and concurrency-safe review/calibration/delivery controls.

= 0.1.0 =
* Initial open-source pilot release.
