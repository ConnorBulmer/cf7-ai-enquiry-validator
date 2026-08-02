# CF7 AI Enquiry Validator

CF7 AI Enquiry Validator is a WordPress 6.5+/PHP 8.1+ plugin that asynchronously classifies stored Contact Form 7 enquiries and evaluates privacy-safe conversion delivery. Contact Form 7 validation, mail, Flamingo/CFDB7 storage, and the visitor response never wait for DeepSeek, OpenAI, GA4, Microsoft, or a webhook.

It is designed to optimise advertising around genuinely valuable enquiries
instead of treating every form submission as an equally useful conversion.
Classification and advertising delivery remain separate: AI proposes a
structured outcome, while deterministic policy, evidence, consent, attribution,
mapping, lifecycle and idempotency rules decide whether anything can be sent.

## Highlights

- DeepSeek Chat Completions and OpenAI Responses providers.
- Flamingo and CFDB7 live capture plus calibration-only historical sampling.
- Guided industry starter packs and editable Custom business policies.
- Grounded evidence outcomes: **Strong match**, **Needs review**, and
  **Not eligible**.
- Google click IDs, Microsoft click IDs, GA client/session data, UTMs, landing
  page, referrer and consent capture, stored only under the applicable explicit
  statistics or marketing consent category.
- Direct GA4 Measurement Protocol, existing server-side GTM, Microsoft UET CAPI
  and signed webhook destinations.
- Shadow mode, destination dry-runs, review queues, policy versioning,
  calibration, retention and WordPress privacy tools.
- No hosted SaaS, central telemetry or vendor-controlled data store.

## Requirements

| Component | Requirement |
| --- | --- |
| WordPress | 6.5 or newer |
| PHP | 8.1 or newer |
| Forms | Current Contact Form 7 |
| Storage | Flamingo or Contact Form 7 Database Addon (CFDB7) |
| Scheduling | Bundled Action Scheduler, loaded defensively |
| AI | Your own DeepSeek or OpenAI API credential |
| Google route | GA4 Measurement ID and Measurement Protocol API secret |
| Microsoft route | UET tag ID and Microsoft Advertising CAPI token |

## Contents

- [How it works](#how-it-works)
- [Current release](#current-release)
- [Policy model](#policy-model)
- [Safe defaults](#safe-defaults)
- [Data flow and privacy](#data-flow-and-privacy)
- [Installation and rollout](#installation-and-rollout)
- [Destinations](#destinations)
- [Administration](#administration)
- [WP-CLI](#wp-cli)
- [Secret constants](#secret-constants)
- [Consent, retention and uninstall](#consent-retention-and-uninstall)
- [Extension interfaces](#extension-interfaces)
- [Troubleshooting](#troubleshooting)
- [Development and testing](#development-and-testing)
- [Creating a GitHub release](#creating-a-github-release)

## How it works

```text
CF7 submission
  ├─ mail and source storage continue independently
  └─ local capture → queued source lookup → redaction/whitelist
       → AI structured classification → grounded policy evaluation
       → review/shadow/consent/attribution/mapping gates
       → idempotent qualified conversion delivery
```

The plugin does not use AI inside Contact Form 7 validation or the visitor
request. It stores a source reference rather than duplicating the raw enquiry,
then loads the original content from Flamingo or CFDB7 only when an authorised
worker or reviewer needs it.

## Current release

### Version 0.2.7 assisted setup response correction

Version 0.2.7 bounds assisted setup to 20 compact suggestions and gives
DeepSeek enough output headroom to complete its JSON response for larger public
site profiles. Partial or truncated provider output remains rejected.

### Version 0.2.6 WordPress admin input correction

Version 0.2.6 normalizes WordPress admin form input before any plugin handler
uses it. This fixes advanced policy JSON imports on normal WordPress requests,
where core recursively adds request slashes before dispatching the admin action.
The regression exercises a fully slashed Training and Education policy through
the real guarded import handler.

### Version 0.2.5 release archive safety correction

Version 0.2.5 supersedes 0.2.4. It uses a platform-safe test log sink and
rejects Windows-reserved or otherwise unsafe archive path components before a
release can be published. The release workflow independently reopens the built
ZIP and confirms one canonical plugin root with no unsafe entries.

### Version 0.2.4 consent-safe attribution

Public attribution is now fail-closed. A configured WordPress Consent API is
authoritative; only its explicit grant permits the relevant attribution category.
When that authority is unavailable or unqualified, the collector may use a known
Google Consent Mode grant as a fallback. Denied, withdrawn, and partial consent
remove disallowed local attribution, the plugin cookie, and injected Contact Form
7 fields. Stored landing and current-page URLs are accepted only for safe HTTP(S)
origins, preventing unsafe values from being carried forward after consent
filtering.

### Version 0.2.3 controlled Google testing

Controlled testing includes strict GA4 debug validation, a confirmed dedicated
transport test, and an isolated classification-to-delivery self-test. The
transport event is zero-value, PII-free, click-ID-free, and consent-denied; it
does not enable GA4, publish mappings, or change automation from shadow. The
pipeline self-test can use the configured AI provider while keeping its item,
mapping, delivery record, and destination entirely in memory.

The same dedicated event may optionally be registered as a GA4 key event and
imported into Google Ads as a Secondary observation-only conversion for
end-to-end acceptance testing. The plugin never performs that account-level
registration or import itself. Synthetic traffic cannot prove attribution to a
real paid click; that final check requires a genuine, consented paid session.

Missing evidence is now interpreted against the published tier rules. Optional
facts do not override otherwise satisfied required and minimum-supporting
evidence, while missing required evidence, contradictions, ambiguity, and
exclusions continue to fail closed.

### Version 0.2.1 Google conversion routes

Google delivery now offers two deliberately separate routes:

- **Direct GA4 — recommended for most users:** Measurement ID plus GA4 Measurement Protocol API secret → GA4 key event → Google Ads import.
- **Existing server-side GTM — Advanced:** tagging-server URL plus Measurement ID/API secret → the server container's GA4 client → a native Google Ads Conversion Tracking tag.

The sGTM route assumes that the operator already has a server container and tagging server. The plugin does not provision Google Cloud, require a Google Cloud Console account, call the Google Ads API, use OAuth, or require a developer token. A tagging server may be hosted on Google Cloud, another managed provider, or operator-controlled infrastructure.

Transport alone is not bidding authorization. For Maximize Conversions, the mapped qualified-lead conversion action must be Primary and its goal must be selected by the campaign. Only the valuable qualified tier should normally be Primary; lower-value or diagnostic actions should remain Secondary. Existing signed webhooks remain available for custom receivers.

## Policy model

Version 0.2.0 includes six domain starter packs plus **Custom business**:

- Care and supported living
- Marketing, web, and design agencies
- Training and education
- Business consultancy
- Home and professional services
- B2B products and services
- Custom business

The guided Setup supports **Setup**, **Testing**, and **Live** lifecycle stages. It can analyse public page copy and safe Contact Form 7 structure, but never submission values. Every generated suggestion remains pending with its page, form, or starter-pack provenance until an administrator accepts, edits, or rejects it. Setup may also be completed without a scan. Entering Testing or publishing a policy does not enable automatic delivery, and entering Live still leaves automation in shadow until the separate Integrations transition.

Simple mode focuses on the guided policy editor and the review outcomes **Strong match**, **Needs review**, and **Not eligible**. Advanced mode adds policy JSON/version controls, grounded evidence diagnostics, missing evidence, ambiguity, provider usage, and model confidence. Confidence is diagnostic only: it is not a dispatch threshold and cannot override grounded evidence, exclusions, consent, lifecycle, mapping, attribution, or delivery controls.

Version 2 policies use the universal outcomes `primary_opportunity`, `secondary_opportunity`, `legitimate_non_conversion`, `excluded`, `spam`, and `insufficient_information`. Strong matches require policy-defined, source-grounded evidence. Administrator corrections create review truth and draft suggestions; the plugin does not learn, rewrite, or publish policies automatically.

## Safe defaults

- Automation starts in `shadow` mode.
- GA4, existing server-side GTM, Microsoft UET, and webhook destinations start disabled with `dry_run: true`.
- Policies and delivery mappings are drafts until separately reviewed and published.
- Historical imports are calibration-only, belong to a durable immutable run, and cannot dispatch. A run becomes active only after every stratum is queued. Its row lock serializes cancellation with item/result/failure writes, so cancelled or superseded actions fail closed even when they are already running or retried.
- Historical calibration is a separate explicit opt-in workflow, suggests 25 records by default, and previews the actual safe-field union from currently published policies alongside the configured date/form/source scope. The preview is fingerprint-bound to the queued run; classification intersects it with the current policy, so later policy widening cannot disclose a newly added field. It never reanalyses existing records merely because the plugin or policy is upgraded.
- The care-home pilot helper suggests matches for titles containing **Care Enquiry**, **Brochure Request**, **Jobs**, and **General Enquiry**; it never publishes them.
- Existing v1 policies remain readable for shadow/review compatibility. Their results are always **Needs review** because v1 lacks v2 grounded-evidence rules; upgrading creates a v2 draft for review and does not alter the source policy or historical results.
- Optional deeper verification is off by default, is shown only for providers that implement the verification capability, makes a second paid provider call for otherwise automatic candidates, and fails back to **Needs review** on disagreement or error.
- No real credential is included in this repository.

## Data flow and privacy

Public-page attribution storage is denied by default. Explicit WordPress Consent API opt-in decisions are authoritative; when that authority is unavailable, unconfigured, or opt-out by default, the collector uses the latest known Google Consent Mode state. If neither source proves an allowed category, storage is denied. Statistics permits GA/UTM/navigation attribution, marketing permits click IDs, and partial consent removes data outside the granted category. Consent changes are handled through `wp_listen_for_consent_change`; withdrawal deletes the plugin localStorage entry and cookie and clears its hidden CF7 fields. Complianz is compatible through its standard WordPress Consent API bridge. A CF7 enquiry checkbox is not treated as statistics or marketing consent.

The capture hook persists a fingerprint, source reference, encrypted attribution/consent snapshots, and optional consent-gated normalized identity hashes. The asynchronous worker loads the original enquiry from Flamingo or CFDB7 on demand. Only explicitly whitelisted, non-identity fields are redacted and sent to AI. Dedicated name, email, phone, IP, click-ID, attribution, consent, file, hash, secret, and every case-insensitive `cf7aiv_` field is blocked. Free text can still contain self-disclosed sensitive information, so provider suitability remains an administrator responsibility; provider-authored prose is never stored as the result summary.

The classifier never decides whether a particular destination may send. Classification-level gates require a published v2 policy, live automation, at least one currently published/enabled database mapping, an Ads-eligible tier, a **Strong match** grounded-evidence decision, and no unresolved ambiguity or missing required evidence. Model confidence is retained for diagnostics only. The delivery worker then evaluates each mapping independently for its required identifier or identity readiness, destination configuration, applicable consent keys, shadow/calibration/history state, audited manual approval where applicable, and persistent idempotency lease.

Concurrency-sensitive transitions fail closed. Cross-source fingerprints are globally unique; classification writes use review revisions so they cannot overwrite a concurrent human decision; approve-and-dispatch records a unique, auditable approval atomically; calibration cancellation is serialized with queued work; and delivery claims use unique idempotency keys plus lease tokens.

Read [Privacy and AI data flow](docs/PRIVACY-AND-AI.md) for details.

## Installation and rollout

1. Install and activate Contact Form 7 plus Flamingo (recommended) or CFDB7.
2. Install this plugin and run `wp cf7aiv migrate`.
3. Run `wp cf7aiv status`, `wp cf7aiv health`, and `wp cf7aiv dry_run`.
4. Run `wp cf7aiv pilot` to inspect the starter policy, form profiles, and disabled draft mappings. Add `--create-policy-draft` only when you want to save the policy as a draft.
5. Configure DeepSeek or OpenAI, keeping automation in shadow. The public scan and classification features call the selected provider, so review provider terms, data location, retention, notices, and legal basis first.
6. Optionally schedule historical calibration from Setup or Tracking Health. Select source/forms/dates, review the whitelisted-field preview, explicitly opt in, and start with the suggested 25. Existing historical records are not reanalysed automatically.
7. Review classifications and corrections. Corrections create draft suggestions only; no automatic learning occurs.
8. Choose direct GA4 for the simplest Google setup, or enable Existing server-side GTM in Advanced mode when a tagging server already exists. Configure each destination in dry-run with its real credential available through a `CF7AIV_*` constant or write-only encrypted setting, validate it, save mappings as drafts, then use the separate publish and enable transitions. Separate controls disable mappings, return automation to shadow, and return GA4 to dry-run. Automation-live and GA4-live each have separately nonced administrator transitions; GA4-live remains unavailable until debug validation succeeds. Changing the GA measurement ID, credential, or enabled state clears validation/live state and forces a new dry-run validation cycle.

Full provider/destination and GA4-to-Google-Ads instructions are in [Setup](docs/SETUP.md).

## Destinations

| Destination | Typical use | Required matching data | Important behaviour |
| --- | --- | --- | --- |
| Direct GA4 | Simplest Google Ads route | GA client ID; recent session ID gives stronger session attribution | Send a GA4 key event, link GA4 to Google Ads, then import the selected event |
| Existing server-side GTM | Advanced users with a tagging server | Google click/client/session context as configured in the container | Sends GA4-shaped requests to the existing first-party tagging endpoint; the container owns its Google Ads tag |
| Microsoft UET CAPI | Microsoft Advertising conversion goals | `msclkid` and/or separately enabled consented hashes | Uses the Microsoft Advertising CAPI token and preserves actual consent |
| Signed webhook | CRM, Make, server-side GTM or custom receiver | Depends on the receiver | PII-free normalized envelope signed with HMAC-SHA256 |

Mappings are versioned independently from policies. Each mapping defines its
tier, destination, event/action name, value and ISO currency. Publishing a
mapping does not enable automation, and enabling automation does not bypass a
disabled destination, failed consent rule or missing identifier.

For Maximize Conversions, Google Ads must ultimately see the valuable qualified
action as Primary and the campaign must use its goal. Diagnostic tests and
lower-value actions should normally remain Secondary. The plugin cannot create
or select campaign goals for you.

## Administration

- **Overview:** queue status, classifications, attribution readiness and
  destination state.
- **Review Queue:** inspect the source message on demand, confirm/change the
  outcome, approve without sending, approve and dispatch, reject or reanalyse.
- **Policy Lab:** guided policy fields, per-form overrides, labelled examples,
  versions and `cf7aiv-policy/v1` JSON import/export.
- **Integrations:** provider credentials, destination configuration, mapping
  drafts, validation, dry-run and separately confirmed live transitions.
- **Tracking Health / Logs:** landing capture, navigation persistence, form
  injection, saved attribution, paused integrations and delivery failures.

Simple mode presents the guided workflow. Advanced mode exposes prompt-policy
JSON, evidence diagnostics, provider usage, confidence and lower-level
integration controls. Switching views does not discard settings.

## WP-CLI

```text
wp cf7aiv status
wp cf7aiv migrate
wp cf7aiv health
wp cf7aiv calibrate --source=flamingo --date-from=2026-01-01 --date-to=2026-07-22 --maximum=25 --explicit-opt-in=1 --form-ids=10,20
wp cf7aiv backfill --source=cfdb7 --date-from=2026-01-01 --date-to=2026-07-22 --maximum=25 --explicit-opt-in=1 --form-ids=10,20
wp cf7aiv dry_run
wp cf7aiv pilot [--create-policy-draft]
wp cf7aiv process_queue
wp cf7aiv ga4_test --mode=validate
wp cf7aiv ga4_test --mode=transport --confirm=SEND_ZERO_VALUE_TEST
wp cf7aiv pipeline_test [--use-configured-ai]
```

`process_queue` refuses to run by default unless automation is shadow and every destination is dry-run. `--allow-live` is an explicit production override, not part of the pilot procedure.

## Secret constants

Prefer constants in `wp-config.php`; never commit values or print them in diagnostics:

```php
define('CF7AIV_DEEPSEEK_API_KEY', getenv('CF7AIV_DEEPSEEK_API_KEY'));
define('CF7AIV_OPENAI_API_KEY', getenv('CF7AIV_OPENAI_API_KEY'));
define('CF7AIV_GA4_API_SECRET', getenv('CF7AIV_GA4_API_SECRET'));
define('CF7AIV_SERVER_GTM_API_SECRET', getenv('CF7AIV_SERVER_GTM_API_SECRET'));
define('CF7AIV_MICROSOFT_UET_TOKEN', getenv('CF7AIV_MICROSOFT_UET_TOKEN'));
define('CF7AIV_WEBHOOK_SECRET', getenv('CF7AIV_WEBHOOK_SECRET'));
```

The plugin also supports encrypted write-only secret settings. Request bodies and secrets are never logged.

## Consent, retention and uninstall

Consent conflicts resolve to the most restrictive captured state. `respect` requires the applicable captured grant. `always_actual` may deliver while preserving the actual captured state. `legal_basis_override` requires a separately saved administrator, timestamp, and reason, appends that decision to the dedicated audit history, and retains actual consent in every delivery envelope. Enhanced identity matching is disabled by default and requires granted `ad_user_data`.

Retention defaults to 90 days. Privacy export/erasure removes dependent review suggestions and deliveries before plugin items and never deletes source enquiries. Routine retention also removes expired calibration-run control records after their items. Uninstall deletion is opt-in and removes plugin mappings, review suggestions, deliveries, items, calibration runs, policies, secrets, paused-destination state, and consent-override audit history.

## Extension interfaces

Sources, providers, destinations, and consent resolvers can be registered with public filters. Lifecycle actions fire after capture, classification, review, and recorded delivery attempts. See [Extending](docs/EXTENDING.md).

## v1 boundaries and v2 compatibility

Version 0.2.7 supports Contact Form 7 with Flamingo/CFDB7, v1 shadow/review compatibility, v2 grounded-evidence policies, text classification through DeepSeek Chat Completions or OpenAI Responses, direct GA4 Measurement Protocol, GA4-protocol delivery through an existing sGTM server, Microsoft UET CAPI, and signed HTTPS webhooks. It does not provision a tagging server, inspect uploads with AI, replace a CRM, infer/falsify consent, learn from reviews automatically, auto-publish policy/mappings, reanalyse historical records on upgrade, guarantee lead quality, import conversions through the Google Ads API, or provide real-time AI validation in the CF7 request.

## Troubleshooting

### Enquiries are stored but not classified

Confirm Action Scheduler is available with `wp cf7aiv status`, inspect
`wp cf7aiv health`, and run a safe queue pass while automation and destinations
remain in their pilot state. Provider authentication, rate limiting and
malformed output are recorded as bounded errors and never prevent CF7 mail or
storage.

### Everything remains Needs review

Check that the active policy is published `cf7aiv-policy/v2`, the required and
minimum supporting evidence rules match the form fields, and the relevant fields
are explicitly AI-whitelisted. Version 1 policies intentionally cannot produce
an automatic Strong match.

### A Strong match was not dispatched

This is normally a deterministic gate rather than an AI-confidence issue.
Inspect lifecycle stage, automation mode, published/enabled mapping, destination
state, dry-run state, consent, identifier/identity readiness, ambiguity,
required evidence, verification state and the record's shadow/history/
calibration flags.

### Google Ads does not show a conversion

First verify the event in GA4 Realtime or DebugView and confirm the GA4 property
is linked to the intended Ads account. Imported conversion reporting can be
delayed, and a synthetic or fake click ID cannot prove attribution to a real
campaign. Direct GA4 attribution is strongest when the captured client and
session identifiers are dispatched within the original session window.

### Changing credentials invalidated live delivery

This is deliberate. Changing a measurement ID, credential or enabled state
clears its validation/live state. Validate again in dry-run before performing
the separate live transition.

## Development and testing

The test suite is deliberately framework-free:

```bash
for test_file in tests/*Test.php; do php "$test_file"; done
find . -path './vendor' -prune -o -name '*.php' -print0 \
  | sort -z \
  | xargs -0 -n1 php -l
node tests/AttributionJsTest.js
node --check assets/js/attribution.js
```

Build a release locally with the same packager used by GitHub Actions:

```bash
php tools/build-release.php --tag=v0.2.7 --output-dir=dist
```

The command refuses malformed tags, version disagreement and credential-like
content. It verifies the one-root ZIP and creates a matching `.sha256` file.
`VERIFICATION.md` records the current release evidence; it is intentionally
excluded from distributable ZIPs.

## Creating a GitHub release

The repository workflow at `.github/workflows/release.yml` runs when a semantic
version tag is pushed. Before publishing, update all four release identities:

1. plugin header `Version` in `cf7-ai-enquiry-validator.php`;
2. `CF7AIV\VERSION` in the same file;
3. `Stable tag` in `readme.txt`;
4. the Git tag, with a required `v` prefix.

For version `0.2.7`:

```bash
git tag v0.2.7
git push origin v0.2.7
```

GitHub Actions checks out that tag, runs every PHP/JavaScript test and lint,
scans distributable text for common credential patterns, builds
`cf7-ai-enquiry-validator-0.2.7.zip`, generates its SHA-256 file and attaches
both to the GitHub Release. The workflow may also be rerun manually for an
existing tag from the Actions tab; existing assets with the same names are
replaced only after the new build passes.

Do not commit API credentials, local `.env` files or generated ZIPs. The
repository ignore rules cover common local files, and the release packager
performs an additional fail-closed scan.
