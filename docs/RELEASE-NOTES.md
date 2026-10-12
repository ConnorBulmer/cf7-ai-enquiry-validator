# Release notes

Detailed notes for each release, moved here from the project README. For a
shorter summary of every version see [CHANGELOG.md](../CHANGELOG.md).

## Version 0.2.36 extension points that work

Version 0.2.36 makes two documented extension points work for other plugins,
and finishes uninstall cleanup.

A consent resolver added on `cf7aiv_consent_resolvers` was registered but
never consulted: capture always used the built-in resolver. Capture now asks
every registered resolver and treats each answer as one more consent source,
combined most-restrictively with Consent Mode, the WordPress Consent API and
Complianz. A denial from any source wins. A grant from a resolver counts only
where no other source refused, and a key the resolver does not decide is left
to the other sources. A resolver that throws records every key as unknown, so
under the default "respect" consent mode nothing is sent on its account. The
browser collector still needs one of the built-in consent sources to store
attribution; a resolver only affects the consent recorded at submission.

A destination added on `cf7aiv_destinations` could never send, because the
delivery worker judged readiness only for GA4, server-side GTM, Microsoft UET
and the webhook. A registered destination can now implement
`CF7AIV\Contracts\DestinationReadinessInterface` to answer three questions:
is it configured, does this enquiry carry the identifiers it needs, and which
consent keys must be granted. Each answer fails closed. A destination that
does not implement the interface, or throws, is still gated, and a destination
cannot declare that it needs no consent. Built-in destination keys are judged
from settings as before, even if another object is registered under them.

Uninstall with **Delete on uninstall** now removes the activity log, the GA4
validation result and the plugin's transients as well.

This release also adds the developer guide in `docs/developer/` and the
rewritten README. Schema version 10 is unchanged.

## Version 0.2.35 measure, test and learn

Version 0.2.35 fixes retries never running: a job that scheduled its own retry
was told the retry already existed, because the queue counted the running job.
Timed-out classifications and retryable deliveries now retry as intended.

It also adds ways to measure and improve accuracy before anything changes in
production. Policy Lab can re-run reviewed enquiries against a draft policy or a
different model and reasoning level, and compare the result with production on
the same enquiries. Reviewer corrections can become examples in a new draft
policy. Overview records which setup produced each classification and splits
accuracy by it, including missed leads.

The review page shows what the AI was given, redacted, and the AI's own answer
beside any correction. Corrections are chosen from the policy. Integrations
adds a connection test for the AI provider. Likely duplicates from the same
person go to review instead of being sent twice. Schema remains version 10.

## Version 0.2.34 delivery and attribution fixes

Version 0.2.34 fixes two destinations that could not send. Live GA4 never sent
for a property validated since 0.2.30, because delivery still read a flag that
release stopped setting. The existing server-side GTM destination never sent,
because the delivery worker had no rule for it. In both cases Tracking Health
said the destination was ready. Delivery and Health now share one readiness
check.

It also stops the standard Consent Mode setup deleting the landing click id on
the second page view, before the consent manager's update arrived, and does the
same for a pending Complianz banner. The GA session id is read from GS1 cookies,
the referrer stays the first external one, and a resend after a mail failure no
longer creates a second enquiry and conversion. Phone numbers are hashed only in
E.164 form. Auto-pause counts only recent consecutive failures, and Tracking
Health shows why a destination paused with a Resume button. Schema remains
version 10.

## Version 0.2.33 current DeepSeek model, distinct reasoning levels

Version 0.2.33 moves DeepSeek to `deepseek-flash` (DeepSeek-V4.1-Flash).
DeepSeek retired V4-Flash on 10 September 2026, and its old ID now works only as
a temporary alias. Saved settings on a retired DeepSeek ID move to the new one
once.

Standard and deep reasoning now differ in effort, not just in allowance.
DeepSeek was never sent a `reasoning_effort`, so both used its default. Standard
now asks for `high` and deep for `max`. Allowances are 8000 and 24000 tokens of
thinking on top of the answer, for both providers. Providers bill only what is
used, so this is a ceiling, not a charge.

Classification now uses the configured timeout. It was fixed at 30 seconds
before, so the 0.2.32 ceiling only reached the site scan. The default is 60
seconds. Reasoning calls get at least 90 seconds (standard) or 180 seconds
(deep), within the 300-second ceiling. A reply cut off at its token limit or
withheld by a content filter is reported as such and is not retried at full cost.
Both providers are told the enquiry is data to evaluate, never instructions.
Schema remains version 10, so no migration is required.

## Version 0.2.32 time and room to think

Version 0.2.32 lets reasoning actually run.

The provider timeout was capped at 60 seconds and the reasoning allowances were
small, both because a provider call could hang an administrator's request. That
constraint has been removed rather than worked around: classification, GA4
validation and now the site scan all run as queued jobs, so no provider call
happens inside a web request. The ceiling is 300 seconds, and standard and deep
reasoning receive 3000 and 8000 tokens of thinking on top of the answer (raised
again in 0.2.33).

This is a deliberate trade. Reasoning is what lets the model weigh an ambiguous
enquiry rather than pattern-match it, and these classifications feed advertising
bidding, where a confident wrong answer is more expensive than a slow right one.
Higher reasoning uses more tokens and costs more per enquiry. Schema remains
version 10, so no migration is required.

## Version 0.2.31 room for the answer

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

## Version 0.2.30 a result that survives a page load

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

## Version 0.2.29 an activity log, and validation off the request

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

## Version 0.2.28 a reason that stays put

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

## Version 0.2.27 settings that save what you set

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

## Version 0.2.26 GA4 validation reasons

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

## Version 0.2.25 visible operational state

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

## Version 0.2.24 Complianz consent capture

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

## Version 0.2.23 credential field state

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

## Version 0.2.22 reasoning token budgets

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

## Version 0.2.21 diagnosable setup failures

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

## Version 0.2.20 administration failure handling

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

## Version 0.2.19 guided administration and provider reasoning

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

## Version 0.2.18 diagnostics and delivery observability

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

## Version 0.2.17 queue and Consent Mode attribution fixes

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

## Version 0.2.16 WordPress-native administration refresh

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

## Version 0.2.15 Microsoft UET Conversion API validation hardening

Version 0.2.15 sends Microsoft events inside the documented `data` batch,
uses one stable event/transaction identity across retries, safely classifies
provider batch and nested validation failures, and adds an exact-confirmation,
zero-value Microsoft transport test. The diagnostic event is consent-denied,
contains no click ID or enhanced identity, and uses only a one-off synthetic
anonymous ID required by CAPI. Identifier-free events fail locally without a
network request. Account IDs, UET tag details,
credentials, and conversion-goal configuration remain outside reusable source.
Schema remains version 10, so no migration is required.

## Version 0.2.13 cross-site portability hardening

Version 0.2.13 scopes operational source-link tokens to each form and page,
binds GA identity to one configured direct-GA4 or server-GTM property,
generalizes persistent source locators, and qualifies bounded CFDB7 matching by
form, time and exact token.
It also extends the bounded public-site scanner through explicit filters and
adds real WordPress 6.9/7.0.2 browser coverage across PHP 8.1/8.3 and
Flamingo/CFDB7.
Schema remains version 10, so no migration is required.

## Version 0.2.12 deterministic evidence dimensions

Version 0.2.12 assigns the published policy dimension locally from each allowed
evidence code. Provider-supplied dimension is redundant metadata, so a mismatch
no longer discards otherwise allowed and grounded evidence. Unknown codes,
non-whitelisted fields, and ungrounded excerpts remain strictly rejected.

## Version 0.2.11 consent-independent source linking

Version 0.2.11 keeps the CF7-to-CFDB7 source-link token in page memory even
when analytics and marketing consent are denied or unavailable. The token is
operational rather than tracking data: it is submitted only with CF7, is not
stored in the plugin attribution cookie or localStorage, and rotates after a
completed submission. All tracking fields remain empty without consent.

## Version 0.2.10 DeepSeek supporting-evidence guidance

Version 0.2.10 tells DeepSeek not to stop after the chosen tier's required
evidence groups. When the supplied context contains grounded supporting
signals, it must include enough to satisfy `minimum_supporting_evidence`;
otherwise it must choose a tier whose rules are satisfied or return
insufficient information. Deterministic evidence evaluation remains unchanged.

## Version 0.2.9 DeepSeek evidence-schema guidance

Version 0.2.9 gives DeepSeek the exact published evidence code-to-dimension
mapping plus concrete JSON examples of every allowed pair. This prevents
intermittent fail-closed classifications in JSON mode without inventing tier,
outcome, evidence-excerpt, or enquiry semantics, while preserving strict local
schema, catalog, privacy, and grounding validation.

## Version 0.2.8 assisted setup provenance correction

Version 0.2.8 constrains assisted-setup provenance to the exact finalized
`pack:key`, `page:ID`, and `form:ID` identifiers supplied to the provider. Both
DeepSeek and OpenAI receive the same dynamic enum, preventing otherwise safe
suggestions from being discarded because the model cited a URL or invented
reference.

## Version 0.2.7 assisted setup response correction

Version 0.2.7 bounds assisted setup to 20 compact suggestions and gives
DeepSeek enough output headroom to complete its JSON response for larger public
site profiles. Partial or truncated provider output remains rejected.

## Version 0.2.6 WordPress admin input correction

Version 0.2.6 normalizes WordPress admin form input before any plugin handler
uses it. This fixes advanced policy JSON imports on normal WordPress requests,
where core recursively adds request slashes before dispatching the admin action.
The regression exercises a fully slashed Training and Education policy through
the real guarded import handler.

## Version 0.2.5 release archive safety correction

Version 0.2.5 supersedes 0.2.4. It uses a platform-safe test log sink and
rejects Windows-reserved or otherwise unsafe archive path components before a
release can be published. The release workflow independently reopens the built
ZIP and confirms one canonical plugin root with no unsafe entries.

## Version 0.2.4 consent-safe attribution

Public attribution is now fail-closed. A configured WordPress Consent API is
authoritative; only its explicit grant permits the relevant attribution category.
When that authority is unavailable or unqualified, the collector may use a known
Google Consent Mode grant as a fallback. Denied, withdrawn, and partial consent
remove disallowed local attribution, the plugin cookie, and injected Contact Form
7 fields. Stored landing and current-page URLs are accepted only for safe HTTP(S)
origins, preventing unsafe values from being carried forward after consent
filtering.

## Version 0.2.3 controlled Google testing

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

## Version 0.2.1 Google conversion routes

Google delivery now offers two deliberately separate routes:

- **Direct GA4 — recommended for most users:** Measurement ID plus GA4 Measurement Protocol API secret → GA4 key event → Google Ads import.
- **Existing server-side GTM — Advanced:** tagging-server URL plus Measurement ID/API secret → the server container's GA4 client → a native Google Ads Conversion Tracking tag.

The sGTM route assumes that the operator already has a server container and tagging server. The plugin does not provision Google Cloud, require a Google Cloud Console account, call the Google Ads API, use OAuth, or require a developer token. A tagging server may be hosted on Google Cloud, another managed provider, or operator-controlled infrastructure.

Transport alone is not bidding authorization. For Maximize Conversions, the mapped qualified-lead conversion action must be Primary and its goal must be selected by the campaign. Only the valuable qualified tier should normally be Primary; lower-value or diagnostic actions should remain Secondary. Existing signed webhooks remain available for custom receivers.

