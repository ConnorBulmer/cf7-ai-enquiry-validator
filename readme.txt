=== CF7 AI Enquiry Validator ===
Contributors: cf7aiv-contributors
Tags: contact form 7, flamingo, conversion tracking, ga4, privacy
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.2.4
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
