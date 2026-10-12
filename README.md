# CF7 AI Enquiry Validator

**Send Google Ads and Microsoft Ads the enquiries that matter, not every form
submission.**

CF7 AI Enquiry Validator is a WordPress plugin for Contact Form 7. After a
visitor submits a form, it uses AI (DeepSeek or OpenAI, with your own API key)
to classify the enquiry against rules you define:

- a genuine sales lead;
- a lower-value enquiry;
- a job application, a supplier pitch or spam.

It then sends a conversion to GA4, an existing server-side GTM container,
Microsoft UET or a signed webhook, but only when deterministic rules say it
should. Ad platforms can then optimise toward real opportunities instead of
form fills.

The visitor never waits. Contact Form 7 mail, Flamingo or CFDB7 storage and
the "thank you" message happen as normal. Classification and delivery run
afterwards in the background.

| | |
| --- | --- |
| Version | 0.2.36 ([changelog](CHANGELOG.md)) |
| Requires | WordPress 6.5+, PHP 8.1+, Contact Form 7, Flamingo or CFDB7 |
| AI providers | DeepSeek (default `deepseek-flash`) or OpenAI (default `gpt-5.4-mini`) |
| Destinations | Direct GA4, existing server-side GTM, Microsoft UET CAPI, signed webhook |
| Licence | GPL-3.0-or-later |

## Contents

- [Why it exists](#why-it-exists)
- [How it works](#how-it-works)
- [Features](#features)
- [Requirements](#requirements)
- [Installation and rollout](#installation-and-rollout)
- [Policies: telling the AI what a good enquiry is](#policies-telling-the-ai-what-a-good-enquiry-is)
- [Review, accuracy and learning from corrections](#review-accuracy-and-learning-from-corrections)
- [Destinations](#destinations)
- [Safety model](#safety-model)
- [Privacy, consent and data retention](#privacy-consent-and-data-retention)
- [Administration screens](#administration-screens)
- [Configuration and secret constants](#configuration-and-secret-constants)
- [WP-CLI](#wp-cli)
- [Troubleshooting](#troubleshooting)
- [Scope and v1 boundaries](#scope-and-v1-boundaries)
- [Documentation](#documentation)
- [Development](#development)

## Why it exists

Smart bidding (Maximise Conversions, Target CPA) learns from whatever you
count as a conversion. When every form submission counts, the platform also
learns from spam, job seekers, sales pitches and existing customers, and finds
you more of them.

This plugin puts a qualification step between the form and the ad platform.
Only enquiries that match your definition of a valuable lead are reported, at
the value you choose.

## How it works

```text
Visitor submits a Contact Form 7 form
 │   mail, Flamingo/CFDB7 storage and the visitor response carry on as normal
 ▼
1. Capture        Store a fingerprint, a reference to the stored enquiry,
                  encrypted attribution (click IDs, GA IDs, UTMs) and consent.
 ▼  (background job)
2. Classify       Load the enquiry, keep only whitelisted fields, redact
                  personal details, ask the AI for a structured answer, and
                  check every piece of evidence it cites against the enquiry.
 ▼
3. Decide         Strong match → may be sent automatically
                  Needs review → waits for a person
                  Not eligible → never sent
 ▼  (background job)
4. Deliver        For each mapped destination: consent, identifiers,
                  configuration and approval checks; then send exactly once,
                  retry on temporary errors, pause on repeated failures.
```

The AI only *proposes* a classification. Whether anything is sent is decided
by plain PHP rules: grounded evidence, a published policy, live mode, consent,
a published mapping and idempotency. Model confidence is shown for
diagnostics and never triggers a send.

## Features

- **Asynchronous by design.** Nothing slow runs in the form request. Work is
  queued with the bundled Action Scheduler (WP-Cron fallback).
- **Industry starter packs.** Care and supported living; marketing, web and
  design agencies; training and education; business consultancy; home and
  professional services; B2B products and services; and Custom business.
- **Guided setup.** It can scan your public pages and form structure (never
  submissions) and suggest services, audiences and exclusions. Each
  suggestion is accepted, edited or rejected by you.
- **Grounded evidence.** Every reason the AI gives must quote text that is
  actually in the enquiry, or the answer is rejected.
- **Reasoning levels.** Off, Standard or Deep, with matching token budgets
  and timeouts. An optional second "verification" pass (DeepSeek) is
  available for automatic candidates.
- **Review queue.** Shows the enquiry exactly as the AI saw it. You can
  confirm, correct, approve, approve and send, reject or reanalyse.
- **Measure and improve.** Accuracy, Ads precision and missed leads are
  reported per model and reasoning level. An accuracy test replays reviewed
  enquiries against a draft policy or a different model. Reviewer corrections
  can become policy examples.
- **Duplicate protection.** A second enquiry from the same person within 30
  days is held for review instead of counted twice (with enhanced matching).
- **Four destinations.** Each is idempotent, retries with backoff and pauses
  itself after repeated permanent failures.
- **Consent-aware.** Supports the WordPress Consent API, Complianz and Google
  Consent Mode. Consent is enforced per destination at send time.
- **Privacy by default.** Identity fields never reach the AI. Attribution is
  encrypted at rest. Retention and WordPress export and erasure tools are
  included. There is no SaaS, no telemetry and no vendor data store.
- **Safe rollout.** Shadow mode, dry runs, draft-then-publish policies and
  mappings, and separately confirmed live switches.
- **Operator tooling.** Activity log, Tracking Health screen, per-item
  delivery diagnostics, optional daily digest email and WP-CLI commands.

## Requirements

| Component | Requirement |
| --- | --- |
| WordPress | 6.5 or newer (tested to 7.0) |
| PHP | 8.1 or newer, with OpenSSL |
| Forms | Contact Form 7 |
| Enquiry storage | Flamingo (recommended) or Contact Form 7 Database Addon (CFDB7) |
| Background jobs | Bundled Action Scheduler (an existing copy from another plugin is reused) |
| AI | Your own DeepSeek or OpenAI API key |
| Google | GA4 Measurement ID and Measurement Protocol API secret, or an existing server-side GTM container |
| Microsoft | UET tag ID and Conversion API token |

## Installation and rollout

1. **Install.** Install and activate Contact Form 7 and Flamingo (or CFDB7),
   then this plugin. Activation creates the database tables. You can run
   `wp cf7aiv migrate`, then `wp cf7aiv status` and `wp cf7aiv health`, to
   confirm.
2. **Connect AI.** Go to **AI Enquiry Validator → Integrations**, add a
   DeepSeek or OpenAI key (or a `CF7AIV_*` constant), and click **Test
   connection**.
3. **Set up a policy.** Go to **Setup**: pick a starter pack, optionally scan
   your site for suggestions, review them, and save a policy draft. Publish
   it in **Policy Lab**.
4. **Move to Testing.** Enquiries are classified in *shadow* mode: nothing is
   sent. Review them in the **Review Queue**, and correct anything wrong.
5. **Optionally calibrate on history.** Classify a bounded sample (25
   suggested) of past enquiries to measure accuracy before going live.
   Calibration items can never be sent.
6. **Configure a destination in dry run.** For Google, direct GA4 is
   simplest: add the Measurement ID and API secret and click **Validate**.
   Server-side GTM is available in Advanced mode. Then create a mapping
   ("tier X → event Y worth Z"), publish it and enable it.
7. **Go live, one switch at a time.** Set the stage to Live, then switch
   automation to live, then switch GA4 (or another destination) out of dry
   run. Each is a separate, confirmed step.
8. **Tell the ad platform.** For GA4, mark the event as a key event, link
   GA4 to Google Ads and import it as a conversion. Make the valuable one
   Primary.

Full step-by-step instructions, including GA4 → Google Ads and Microsoft UET,
are in [Setup and rollout](docs/SETUP.md).

## Policies: telling the AI what a good enquiry is

A policy describes your business and sorts enquiries into **tiers** (for
example `residential_care`, `care_information`, `spam`). Each tier maps to
one of six universal outcomes:

| Outcome | Typical use |
| --- | --- |
| `primary_opportunity` | The lead you want more of |
| `secondary_opportunity` | Worth something, but less |
| `legitimate_non_conversion` | Real, but not a sale (support, existing customers) |
| `excluded` | Recruitment, supplier pitches, and so on |
| `spam` | Spam or automated content |
| `insufficient_information` | Not enough to tell |

For each tier the policy lists:

- the **required evidence** and **supporting evidence**, as codes from its
  evidence catalogue;
- whether the tier is **Ads-eligible**;
- which exclusion and ambiguity codes apply.

The policy also says which form fields the AI may read (the *whitelist*).

An enquiry is a **Strong match** only when:

- the outcome is an opportunity;
- every required evidence group is satisfied by a quote found in the enquiry;
- there are enough supporting quotes;
- no exclusion or ambiguity applies.

Anything else is **Needs review** or **Not eligible**.

Policy versions:

- **Lifecycle.** Policies are versioned. A new version is a draft until
  published, and publishing supersedes the previous version.
- **Per form.** A form can have its own policy (`form:<id>`), overriding the
  `global` one.
- **Format.** Policies are `cf7aiv-policy/v2` JSON and can be imported and
  exported in Advanced mode. Older v1 policies still work but always produce
  Needs review.

## Review, accuracy and learning from corrections

- **Review Queue.** Shows the AI's tier and outcome, the evidence it cited,
  and the enquiry *as the AI received it* (whitelisted and redacted). The raw
  enquiry appears only in Advanced mode. You can confirm, change the tier,
  approve without sending, approve and send, reject, or reanalyse.
- **Overview.** Reports agreement with reviewers, Ads precision, false
  positives and **missed leads**, split by provider, model and reasoning
  level.
- **Accuracy test** (Policy Lab). Re-runs up to 100 reviewed enquiries
  against any saved policy, provider, model and reasoning level, and shows
  the result next to production's. Use it before publishing a policy or
  changing model.
- **Learn from corrections** (Policy Lab). Each correction includes a short,
  non-identifying description of the enquiry. Pick the useful ones and they
  are added to a copy of the policy as examples, saved as a new draft to test
  and publish. Nothing is learned or published automatically.

## Destinations

| Destination | Typical use | Needs | Notes |
| --- | --- | --- | --- |
| **Direct GA4** | Simplest Google Ads route | Measurement ID and API secret; the visitor's GA client ID | Validate against the GA4 debug endpoint first. Mark the event as a key event and import it into Google Ads. Best attribution when sent within the visitor's session. |
| **Existing server-side GTM** | You already run a tagging server | HTTPS endpoint, measurement ID and API secret; a GA client ID or Google click ID | Sends a GA4 Measurement Protocol request to your server. Your container owns the Google Ads tag. |
| **Microsoft UET CAPI** | Microsoft Advertising conversion goals | Tag ID and CAPI token; `msclkid`, or consented hashed email/phone | Uses a stable event and transaction ID so Microsoft can de-duplicate. |
| **Signed webhook** | CRM, Make/Zapier, custom receivers | HTTPS URL and secret | JSON event with no names, contact details or message text (hashed email/phone only with enhanced matching and consent), signed with HMAC-SHA256 (`X-CF7AIV-Signature`). |

**Mappings** connect a tier to a destination: "send `residential_care` to GA4
as `qualified_lead` worth £250". Mappings are versioned drafts until you
publish and enable them.

All destinations start disabled and in **dry run**: requests are built and
logged, but not sent. Changing a GA4 credential or measurement ID clears its
validation and returns it to dry run.

## Safety model

New installs cannot send anything until an administrator deliberately goes
live:

- **Shadow mode** is the default. Enquiries are classified but nothing is
  sent, and an enquiry first classified in shadow mode can never be sent
  later, even after going live.
- **Draft first.** Policies and mappings are drafts until published.
  Publishing does not switch anything on.
- **Separate live switches.** Lifecycle stage, automation mode and GA4 live
  mode are three separate, separately confirmed switches.
- **Calibration and historical items can never be sent.**
- **Every send is idempotent.** Each conversion has a stable key and is
  claimed under a lease, so retries and duplicate jobs cannot double count.
- **Concurrency is fail-closed.** A classifier cannot overwrite a reviewer's
  decision, and approve-and-send writes an audited approval atomically.
- **Auto-pause.** A destination with repeated permanent failures (default 3)
  pauses itself until resumed from Tracking Health.
- **Verification (optional).** A second, deeper AI pass for automatic
  candidates. Disagreement goes to review.

## Privacy, consent and data retention

- **No copy of the enquiry.** The plugin stores a reference to the
  Flamingo/CFDB7 record, not the message.
- **What the AI sees.** Only whitelisted fields, with emails, phone numbers,
  URLs, IPs and click IDs redacted. Some fields are always blocked, whatever
  the policy says: any field whose name contains name, email, phone,
  address, postcode, IP, file or upload, a click ID, UTM, campaign, source or
  referrer, consent, cookie, session, hash, secret, token or password.
- **What is stored.** The model's own text is never stored; summaries are
  fixed phrases.
- **Attribution.** Stored in the browser only with consent: statistics
  consent for GA IDs, UTMs and pages; marketing consent for click IDs.
  - Sources, in order: the WordPress Consent API (opt-in), Complianz, then
    Google Consent Mode.
  - Withdrawing consent removes the stored data.
  - A CF7 acceptance checkbox is not treated as tracking consent.
- **Consent at send time.** Checked per destination from the snapshot taken
  at submission. There are three modes:
  - `respect` (default): requires a grant;
  - `always_actual`: sends, with the real consent state;
  - `legal_basis_override`: sends, but requires an administrator, a date and
    a reason, and is audited.
- **Enhanced matching** (hashed email/phone) is off by default and needs
  `ad_user_data` consent.
- **Retention.** Plugin records are deleted after 90 days by default
  (configurable). WordPress personal-data export and erasure are supported.
- **Uninstall.** Keeps data unless **Delete on uninstall** is enabled.

Your Flamingo/CFDB7 enquiries are never deleted by this plugin. Read
[Privacy and AI data flow](docs/PRIVACY-AND-AI.md) before production use. You
remain responsible for your AI provider's terms, data location and legal
basis.

## Administration screens

Under **AI Enquiry Validator** in wp-admin:

| Screen | What it is for |
| --- | --- |
| **Overview** | Stage, queue, accuracy and missed leads, token usage, destination state |
| **Review Queue** | Review and correct classifications, approve and send |
| **Policy Lab** | Edit and publish policies, run accuracy tests, learn from corrections, JSON import/export |
| **Integrations** | AI provider, destinations, mappings, consent, live switches |
| **Tracking Health & Logs** | Attribution capture checks, paused destinations, failures, activity log, calibration |
| **Setup** | Guided setup, site scan suggestions, lifecycle stage |

**Simple mode** keeps to the guided workflow. **Advanced mode** adds policy
JSON, evidence diagnostics, model confidence, provider usage and lower-level
controls.

## Configuration and secret constants

Settings are managed in the admin screens. API keys and secrets can be
entered there (stored encrypted, write-only). It is better to define them in
`wp-config.php`, where a constant always wins:

```php
define('CF7AIV_DEEPSEEK_API_KEY', getenv('CF7AIV_DEEPSEEK_API_KEY'));
define('CF7AIV_OPENAI_API_KEY', getenv('CF7AIV_OPENAI_API_KEY'));
define('CF7AIV_GA4_API_SECRET', getenv('CF7AIV_GA4_API_SECRET'));
define('CF7AIV_SERVER_GTM_API_SECRET', getenv('CF7AIV_SERVER_GTM_API_SECRET'));
define('CF7AIV_MICROSOFT_UET_TOKEN', getenv('CF7AIV_MICROSOFT_UET_TOKEN'));
define('CF7AIV_WEBHOOK_SECRET', getenv('CF7AIV_WEBHOOK_SECRET'));

define('CF7AIV_DEBUG', true); // optional: also write delivery gates to the PHP error log
```

Stored secrets are encrypted with a key derived from `AUTH_KEY` and
`SECURE_AUTH_KEY`. **Changing those salts makes stored secrets unreadable,**
so re-enter them afterwards. Secrets and request bodies are never logged.

AI request timeout defaults to 60 seconds (maximum 300). Standard reasoning
raises it to at least 90 s, and Deep to at least 180 s.

## WP-CLI

```text
wp cf7aiv status                 Version, schema, automation mode, dry-run state, sources
wp cf7aiv migrate                Create or upgrade the database tables
wp cf7aiv health                 Source availability and counts, paused destinations
wp cf7aiv doctor                 Full diagnostics: queue, configuration, mappings, consent
wp cf7aiv diagnose --item=<id>   Why one enquiry was or was not sent
wp cf7aiv dry_run                Read-only safety report; sends nothing
wp cf7aiv pilot [--create-policy-draft]
                                 Starter policy, form profiles and draft mappings (never publishes)
wp cf7aiv calibrate --source=flamingo --date-from=2026-01-01 --date-to=2026-06-30 \
    --maximum=25 --explicit-opt-in=1 [--form-ids=10,20]
                                 Schedule a calibration-only historical sample (alias: backfill)
wp cf7aiv process_queue [--allow-live]
                                 Run one queue pass (refused unless shadow + dry run, without --allow-live)
wp cf7aiv pipeline_test [--use-configured-ai]
                                 Isolated end-to-end self-test; touches no real data
wp cf7aiv ga4_test --mode=validate
wp cf7aiv ga4_test --mode=transport --confirm=SEND_ZERO_VALUE_TEST
wp cf7aiv microsoft_test --confirm=SEND_ZERO_VALUE_MICROSOFT_TEST
```

## Troubleshooting

**Enquiries are stored but not classified.**

- Run `wp cf7aiv status` to confirm Action Scheduler is available.
- Look under Tools → Scheduled Actions (group `cf7aiv`).
- Check that a policy is published.
- Errors such as a bad key, a rate limit or a timeout appear on the item and
  in the activity log. They never affect CF7 mail or storage.

**Everything stays "Needs review".**

- Check that the published policy is v2.
- Check that the fields carrying the evidence are in the AI whitelist.
- Check that each tier's required and minimum supporting evidence is
  realistic for your forms.
- Try Standard reasoning, and use the accuracy test to compare.

**A Strong match was not sent.**

Run `wp cf7aiv diagnose --item=<id>`, or check the activity log for the gate
reason. Common causes:

- shadow mode;
- no published and enabled mapping;
- destination in dry run, paused or missing a credential;
- consent not granted;
- missing click or client ID;
- verification required but not passed;
- the item was first classified in shadow mode.

**Google Ads shows nothing.**

- Confirm the event in GA4 Realtime or DebugView.
- Confirm GA4 is linked to the right Ads account and the event is imported
  as a conversion.
- Note that imports can lag by a day.
- Attribution is strongest when the GA client and session IDs were captured
  and the conversion is sent soon after the visit.

**Live delivery stopped after changing a credential.**

This is deliberate. Validate again in dry run, then switch to live.

## Scope and v1 boundaries

This plugin supports:

- Contact Form 7 with Flamingo or CFDB7;
- text classification with DeepSeek or OpenAI;
- direct GA4 Measurement Protocol, an *existing* server-side GTM container,
  Microsoft UET CAPI and signed HTTPS webhooks;
- `cf7aiv-policy/v1` policies in shadow/review-only compatibility mode.

It does **not**:

- provision a tagging server;
- read uploaded files with AI;
- replace a CRM;
- infer or invent consent;
- learn or publish policies automatically;
- reanalyse history on upgrade;
- call the Google Ads API;
- guarantee lead quality;
- validate enquiries in real time inside the form request.

## Documentation

| Document | For |
| --- | --- |
| [Setup and rollout](docs/SETUP.md) | Site owners: step-by-step configuration of providers, policies, calibration, each destination and consent |
| [Privacy and AI data flow](docs/PRIVACY-AND-AI.md) | Exactly what data goes where, and what never leaves the site |
| [Extending](docs/EXTENDING.md) | Other plugins: filters, interfaces and lifecycle actions |
| [Developer guide](docs/developer/README.md) | Developers changing this plugin: architecture, each subsystem, data model, queue, testing, recipes |
| [Changelog](CHANGELOG.md) | A summary of each release |
| [Release notes](docs/RELEASE-NOTES.md) | The detailed notes for each release |

## Development

```bash
for f in tests/*Test.php; do php "$f" || break; done     # PHP tests (no Composer/PHPUnit needed)
node tests/AttributionJsTest.js                          # attribution script tests
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l   # lint
php tools/build-release.php --tag=v0.2.36 --output-dir=dist                       # build the release ZIP
```

The tests are framework-free PHP scripts with fake WordPress functions, so
they run anywhere with PHP 8.1 and Node.js. `tests/wordpress/` holds an
optional Docker and Playwright matrix against real WordPress versions.

**Releases.**

1. Update the version in four places: the plugin header, `CF7AIV\VERSION`,
   `readme.txt` "Stable tag", and `tests/ReleasePackageTest.php`.
2. Add a `CHANGELOG.md` entry.
3. Push a `vX.Y.Z` tag. GitHub Actions runs every test, builds a verified ZIP
   with a SHA-256 checksum and attaches both to a GitHub Release.

Start with the [developer guide](docs/developer/README.md) before making
changes. It explains the architecture, the rules the code follows, and
checklists for common changes.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE). Bundles
[Action Scheduler](https://actionscheduler.org/) (GPL-3.0).
