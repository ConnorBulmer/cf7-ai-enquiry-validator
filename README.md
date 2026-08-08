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

### Version 0.2.31 room for the answer

Version 0.2.31 raises the classification token allowance, which was smaller than
the one given to policy suggestions despite classification producing the larger
answer.

A v2 classification emits nine required fields, and every evidence entry carries
an excerpt quoted from the enquiry, so the reply grows with how much the enquiry
actually says. At 900 tokens it was being cut off mid-object, and a JSON object
cut in half does not parse - which surfaced as "malformed or schema-invalid
content" and read as the model misbehaving rather than as a budget that could
never have been sufficient.

Found on a live site where one enquiry timed out with reasoning enabled and
returned invalid content with reasoning off: two different failures with the same
underlying cause, visible only because the failure reason is now displayed.
Schema remains version 10, so no migration is required.

### Version 0.2.30 a result that survives a page load

Version 0.2.30 fixes the GA4 validation result being lost between the background
job that produced it and the screen that reports it.

The result was written into the main settings array. `Plugin::ensureSettings()`
rewrites that array in full on `plugins_loaded`, on every request, so a value
written asynchronously was overwritten by any request that had read the array
moments earlier. The symptom was precise and misleading: the activity log
recorded "Validation passed" while Integrations continued to report "Not
validated", because both were telling the truth about different copies.

A result produced asynchronously does not belong in a structure that synchronous
requests rewrite wholesale. It now has its own option, which nothing else
writes, and the write is read back and reported through the activity log if it
fails to land. The live transition and destination readiness read the same
stored result, so the safety gate and the badge cannot disagree. Schema remains
version 10, so no migration is required.

### Version 0.2.29 an activity log, and validation off the request

Version 0.2.29 adds a plugin activity log and moves GA4 debug validation into
the background.

The plugin already broadcast why it refused an action, why a delivery was gated
and why something failed. Those facts reached the PHP error log under
`CF7AIV_DEBUG` and nowhere else, and managed hosting frequently does not expose
that log - so the plugin could diagnose its own failure and had no way to hand
the answer over. Tracking Health & Logs now carries an activity log fed by the
same lifecycle actions, scrubbed of credentials and personal data before storage
rather than on display, and capped at 200 entries so it needs no migration and
cannot grow without bound.

GA4 debug validation now runs as a queued job. Contacting Google can outlive
PHP's execution limit on a slow or filtered network, and run inline that took the
whole request with it: nothing was written, and the page reloaded with no result
and no reason - indistinguishable from a button that does nothing. The job
records its outcome whenever it completes, the screen reports a "Checking" state
while it runs, and an unavailable queue falls back to running inline rather than
declining to check at all. Schema remains version 10, so no migration is
required.

### Version 0.2.28 a reason that stays put

Version 0.2.28 fixes the GA4 validation reason never reaching the screen. It was
held in a short-lived, read-once transient, which meant whichever page load
happened first consumed it - frequently one that rendered it inside a tab panel
the operator was not looking at - and a refresh a few seconds later found
nothing. The result was a destination that reported itself blocked while
offering no explanation, which is the problem the reason was added to solve.

The reason is now stored beside the flag it explains. It persists for as long as
validation has not passed, and is cleared when it does, so a problem that has
been fixed stops presenting itself as current. A page-level notice also appears
whenever GA4 is enabled but unvalidated, because a destination that cannot go
live is not a detail belonging to one tab. Schema remains version 10, so no
migration is required.

### Version 0.2.27 settings that save what you set

Version 0.2.27 fixes destination checkboxes silently reverting when cleared.

An unchecked checkbox is not submitted at all. The sanitizer starts from
`Settings::defaults()` and only overwrites a flag whose key is present, so a
cleared box left the default in place. Any flag defaulting to true could not be
turned off: it accepted the change, saved, and came back set, with nothing to
say why. The GA4 dry-run flag defaults to true, so it was affected. Every
checkbox now carries a hidden companion field, which is the pattern the
advanced-mode flag already used.

GA4 dry-run is a separate matter. Live delivery is gated on debug validation
having passed, so that flag is released by the lifecycle transition and never by
the settings form. It is now shown as governed by that transition, rather than
presented as an editable control that quietly refuses. Schema remains version 10,
so no migration is required.

### Version 0.2.26 GA4 validation reasons

Version 0.2.26 reports what GA4 objected to, rather than only that it objected.

The Measurement Protocol debug endpoint exists to explain refusals: a mismatched
API secret, an unknown event name and a malformed client id all come back named,
with a field path and a validation code. That response was collapsed into "GA4
debug validation failed", which discarded the entire reason for calling the debug
endpoint and left a destination that will not leave dry-run for reasons nobody
could see.

Google's own text is now reported, bounded and stripped to plain description
because it is shown to an administrator, and surfaced on Integrations through a
short-lived per-administrator transient that is read once so a fixed problem
stops being reported as current. Schema remains version 10, so no migration is
required.

### Version 0.2.25 visible operational state

Version 0.2.25 shows three things the plugin already knew and never displayed.
Each had the same shape: a state that decided what happened next was recorded
internally and left off the screen, so the only remaining move was to guess or
read the code.

The reason a classification failed is written to `last_error` on every failure
and was displayed nowhere. A queue of failed items showed only that they had
failed, which leaves re-running them as the sole option - and something that
fails deterministically fails again. It now appears in the Review Queue for
failed and retrying items, sanitized through the same path as delivery errors.

GA4 debug validation recorded its result silently. Live delivery refuses to
leave dry-run until validation passes, so enabling it could do nothing with no
explanation. Integrations now shows whether validation has passed and what
follows from it.

Administration actions that call an AI provider or Google synchronously can take
tens of seconds behind an ordinary form submission, with no cursor change, no
disabled button and no message. They now report progress while they run, without
ever claiming success. Schema remains version 10, so no migration is required.

### Version 0.2.24 Complianz consent capture

Version 0.2.24 fixes attribution never being captured on sites using Complianz,
which silently prevented any conversion from ever being delivered.

The script read consent from two places: the WordPress Consent API, and Consent
Mode entries in `dataLayer`. Complianz publishes through its own
`cmplz_has_consent()` function and its own `cmplz_event_*` events, and its
Consent API bridge is optional. On a site configured that way both known routes
stayed silent while consent was in fact fully granted, so consent read as never
decided, the click identifier on the landing URL was never stored, and
`attribution_ready` never became true. Nothing was logged, because as far as the
script could tell the visitor had simply not chosen yet.

Complianz is now a recognised consent source. Its statistics and marketing
categories map exactly as the WordPress Consent API categories do, its
synchronous answer counts as a real decision, and its post-load consent events
are observed so a decision made after page load still captures the landing click
identifier. The WordPress Consent API remains authoritative wherever it is
present, so existing sites are unaffected. Schema remains version 10, so no
migration is required.

### Version 0.2.23 credential field state

Version 0.2.23 makes a stored credential visible as stored. Secret inputs are
write-only by design and the value is never rendered - that has not changed -
but the field also rendered with an empty value whether a credential was saved
or not. A fully configured site was indistinguishable from an untouched one, so
a successful save looked like a failure, and re-entering the key looked safer
than trusting it.

Each credential field now carries a stored/not-set badge, a masked placeholder
so a configured field looks configured, and a sentence stating that leaving it
blank keeps the stored value. The mask is a placeholder attribute rather than a
value: as a value it would be submitted on the next save and stored as the
credential itself.

Removal is now possible. Because a blank field means "keep", a stored credential
could not previously be taken out without editing the database. A typed
replacement takes precedence over a removal request. A credential supplied
through a constant reports as stored, because `Crypto::secret()` prefers a
constant over the database. Schema remains version 10, so no migration is
required.

### Version 0.2.22 reasoning token budgets

Version 0.2.22 fixes reasoning starving the answer it was meant to improve.
DeepSeek counts reasoning tokens against `max_tokens`, and both the suggestion
and classification calls sent a single flat budget covering both. With reasoning
enabled the thinking could consume the allowance, and the JSON answer arrived cut
mid-object - which surfaced as "response is malformed" several layers from the
cause, because half a JSON object does not parse.

The answer and the reasoning are now sized separately. The answer allowance
matches what the OpenAI provider already receives for the identical call, and
reasoning is granted its own allowance on top instead of competing with it.
Budgets remain bounded and predictable rather than unlimited.

A reply the model did not finish writing is also detected directly through
`finish_reason: length` and reported as an exhausted budget, not as malformed
input, because those need opposite fixes. Schema remains version 10, so no
migration is required.

### Version 0.2.21 diagnosable setup failures

Version 0.2.21 makes a failed site scan say why it failed. The service caught
every failure with an unbound `catch (Throwable)` and replaced it with a single
reassuring sentence, so an API key that was never stored, an API key the
provider rejected, and a host that refuses outbound HTTP all produced the same
notice. Those need opposite fixes, and the screen gave no way to tell them
apart.

DeepSeek suggestion failures now carry the HTTP status, which is the whole
diagnosis: 401 means the credential was rejected, 403 that the account cannot
use it, 404 a wrong base URL, 5xx a provider outage. The reason is scrubbed
before display — `Redactor` handles enquiry content but not API keys or bearer
tokens, so credential-shaped runs are removed first — then length-capped.

The reason reaches the notice through a short-lived per-administrator transient
rather than the query string, because a URL is logged by the server, kept in
history and read over a shoulder. It is cleared after one read. Schema remains
version 10, so no migration is required.

### Version 0.2.20 administration failure handling

Version 0.2.20 changes what an administrator sees when an action cannot be
carried out. Every `admin_post` handler validates its input and passes it to a
service that rejects bad values; those rejections were uncaught, so WordPress
rendered its critical-error page, mailed the site administrator and logged a
fatal for what was a typed-in value the plugin was right to refuse.

Handlers are now registered through a single guard. A rejected value returns
HTTP 400 carrying the reason it was rejected. An unexpected fault returns a
generic HTTP 500, with the real detail written to the error log and broadcast on
`cf7aiv_admin_action_failed` rather than shown in the browser. Refusals are
broadcast on `cf7aiv_admin_action_refused` and logged under `CF7AIV_DEBUG`,
because a refusal is otherwise visible only to the administrator who caused it.
Denials keep their own 403 where `wp_die` is filtered to throw.

This release also corrects misleading defaults and presentation found by working
through the screens as an operator: a neutral starter pack is preselected,
calibration no longer offers actions that cannot succeed, policy identifiers are
shown as readable labels, and Overview tables no longer duplicate their heading
or overflow. Schema remains version 10, so no migration is required.

### Version 0.2.19 guided administration and provider reasoning

Version 0.2.19 adds a five-step guided Setup workflow and a tabbed
Integrations dashboard. Starter-pack previews and evidence requirements remain
bounded and escaped, provider/destination safety is visible, and ordinary Save
changes stays separate from lifecycle, mapping, consent, validation, and
transport actions.

Provider reasoning controls now follow the selected model's documented
capabilities. DeepSeek and OpenAI suggestions/classification retain bounded
structured-output budgets, and OpenAI's GPT-5.4-family `none` effort is sent only
where documented. Unknown or unsupported models fail closed. Schema remains
version 10, so no migration is required.

### Version 0.2.18 diagnostics and delivery observability

Version 0.2.18 adds a read-only operational doctor and per-item diagnosis path
for live troubleshooting. `wp cf7aiv doctor` reports queue state, captured but
unclassified items, attribution readiness and safe issue-code counts, consent
integration detection, destination readiness, and published mapping readiness.
`wp cf7aiv diagnose --item=ID` walks one stored item through classification,
lifecycle, attribution, consent, and mapping/destination gates without sending
or claiming a delivery.

Delivery gate decisions also emit the `cf7aiv_delivery_gate` lifecycle action and
optional `CF7AIV_DEBUG` error-log records. Invalid administrator nonces now
return a controlled HTTP 403 instead of an uncaught critical error. Schema
remains version 10, so no migration is required.

### Version 0.2.17 queue and Consent Mode attribution fixes

Version 0.2.17 corrects three defects that each stopped qualified conversions
being delivered, and none of which could be worked around through settings.

Queued work is now deduplicated by hook, arguments and group. Action Scheduler's
own `$unique` flag compares only hook and group, so the previous behaviour
allowed a single pending action per hook for an entire site: every per-item job
enqueued while another was waiting was discarded without an error, including
classification and delivery retries.

Attribution now follows Consent Mode. Sites that publish consent through
`dataLayer` rather than the WordPress Consent API never emitted the event the
script listened for, so a click identifier present on the landing URL was never
stored and no conversion became eligible. Consent defaults and updates pushed to
`dataLayer` are now observed directly.

Stored attribution is only discarded once consent has actually been decided.
Consent Mode publishes asynchronously, and treating "not yet known" as a refusal
deleted the click identifier captured on the landing page before the visitor
reached the form. Storage writes are also skipped when the payload is unchanged,
so one consent decision arriving by two routes still stores exactly once.

Schema remains version 10, so no migration is required.

### Version 0.2.16 WordPress-native administration refresh

Version 0.2.16 replaces raw Overview and Tracking Health output with semantic
summaries, status badges, descriptions, and accessible local table regions.
Wide administration tables scroll within their own labelled regions while all
six plugin screens remain responsive across desktop, tablet, and narrow
viewports. Native WordPress controls and actions are grouped consistently,
forced-colour and keyboard-focus coverage protects the refreshed interfaces,
and the Microsoft transport-test disclosure now accurately describes its
one-off synthetic anonymous ID. Release packaging excludes local top-level
`.superpowers` plans and evidence. Schema remains version 10, so no migration
is required.

### Version 0.2.15 Microsoft UET Conversion API validation hardening

Version 0.2.15 sends Microsoft events inside the documented `data` batch,
uses one stable event/transaction identity across retries, safely classifies
provider batch and nested validation failures, and adds an exact-confirmation,
zero-value Microsoft transport test. The diagnostic event is consent-denied,
contains no click ID or enhanced identity, and uses only a one-off synthetic
anonymous ID required by CAPI. Identifier-free events fail locally without a
network request. Account IDs, UET tag details,
credentials, and conversion-goal configuration remain outside reusable source.
Schema remains version 10, so no migration is required.

### Version 0.2.13 cross-site portability hardening

Version 0.2.13 scopes operational source-link tokens to each form and page,
binds GA identity to one configured direct-GA4 or server-GTM property,
generalizes persistent source locators, and qualifies bounded CFDB7 matching by
form, time and exact token.
It also extends the bounded public-site scanner through explicit filters and
adds real WordPress 6.9/7.0.2 browser coverage across PHP 8.1/8.3 and
Flamingo/CFDB7.
Schema remains version 10, so no migration is required.

### Version 0.2.12 deterministic evidence dimensions

Version 0.2.12 assigns the published policy dimension locally from each allowed
evidence code. Provider-supplied dimension is redundant metadata, so a mismatch
no longer discards otherwise allowed and grounded evidence. Unknown codes,
non-whitelisted fields, and ungrounded excerpts remain strictly rejected.

### Version 0.2.11 consent-independent source linking

Version 0.2.11 keeps the CF7-to-CFDB7 source-link token in page memory even
when analytics and marketing consent are denied or unavailable. The token is
operational rather than tracking data: it is submitted only with CF7, is not
stored in the plugin attribution cookie or localStorage, and rotates after a
completed submission. All tracking fields remain empty without consent.

### Version 0.2.10 DeepSeek supporting-evidence guidance

Version 0.2.10 tells DeepSeek not to stop after the chosen tier's required
evidence groups. When the supplied context contains grounded supporting
signals, it must include enough to satisfy `minimum_supporting_evidence`;
otherwise it must choose a tier whose rules are satisfied or return
insufficient information. Deterministic evidence evaluation remains unchanged.

### Version 0.2.9 DeepSeek evidence-schema guidance

Version 0.2.9 gives DeepSeek the exact published evidence code-to-dimension
mapping plus concrete JSON examples of every allowed pair. This prevents
intermittent fail-closed classifications in JSON mode without inventing tier,
outcome, evidence-excerpt, or enquiry semantics, while preserving strict local
schema, catalog, privacy, and grounding validation.

### Version 0.2.8 assisted setup provenance correction

Version 0.2.8 constrains assisted-setup provenance to the exact finalized
`pack:key`, `page:ID`, and `form:ID` identifiers supplied to the provider. Both
DeepSeek and OpenAI receive the same dynamic enum, preventing otherwise safe
suggestions from being discarded because the model cited a URL or invented
reference.

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
| Microsoft UET CAPI | Microsoft Advertising conversion goals | Consent-permitted `msclkid`, or separately enabled consented hashes | Uses the UET tag Conversion API authentication key, preserves actual consent, and sends one stable event/transaction identity |
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

The optional assisted-setup scanner also exposes bounded filters for public
custom post types, local post objects, builder-managed public copy and embedded
CF7 form IDs. It never fetches rendered page URLs, and every extension result
is rechecked for published/password-free/local-origin eligibility plus the
scanner's page and character limits. Copy-paste CPT and builder examples are in
[Extending](docs/EXTENDING.md#assisted-site-profile-scanning).

## v1 boundaries and v2 compatibility

Version 0.2.17 supports Contact Form 7 with Flamingo/CFDB7, v1 shadow/review compatibility, v2 grounded-evidence policies, text classification through DeepSeek Chat Completions or OpenAI Responses, direct GA4 Measurement Protocol, GA4-protocol delivery through an existing sGTM server, Microsoft UET CAPI, and signed HTTPS webhooks. It does not provision a tagging server, inspect uploads with AI, replace a CRM, infer/falsify consent, learn from reviews automatically, auto-publish policy/mappings, reanalyse historical records on upgrade, guarantee lead quality, import conversions through the Google Ads API, or provide real-time AI validation in the CF7 request.

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
php tools/build-release.php --tag=v0.2.24 --output-dir=dist
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

For version `0.2.22`:

```bash
git tag v0.2.22
git push origin v0.2.22
```

GitHub Actions checks out that tag, runs every PHP/JavaScript test and lint,
scans distributable text for common credential patterns, builds
`cf7-ai-enquiry-validator-0.2.21.zip`, generates its SHA-256 file and attaches
both to the GitHub Release. The workflow may also be rerun manually for an
existing tag from the Actions tab; existing assets with the same names are
replaced only after the new build passes.

Do not commit API credentials, local `.env` files or generated ZIPs. The
repository ignore rules cover common local files, and the release packager
performs an additional fail-closed scan.
