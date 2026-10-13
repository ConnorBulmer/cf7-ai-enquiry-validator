# Intake and attribution

Intake runs inside the Contact Form 7 request, so it must be fast and must
never break the form. It saves one row per enquiry with enough context to
classify and attribute it later, then hands off to the queue.

Code: `src/Intake/`, `src/Sources/`, `assets/js/attribution.js`.

## Overview

```text
Page view ── attribution.js ──► localStorage + cookie "cf7aiv_attribution"  (consent-gated)
         └─ template_redirect ─► AttributionCookie::serverFallback          (same cookie, server side)

Form render ── wpcf7_form_elements ─► FieldNames::injectMissing (hidden cf7aiv_* inputs)
Form submit ── wpcf7beforesubmit (JS) ─► fills the hidden inputs in event.detail.formData
            ── wpcf7_after_flamingo ─► CaptureService::capture(…, 'flamingo', inboundId)
            ── wpcf7_submit (20) ────► CaptureService::capture(…) if Flamingo did not capture
                                       └─► do_action('cf7aiv_after_capture', itemId, source, reference)
```

Every intake hook body is wrapped in `try/catch (Throwable)` and only logs
errors (`[CF7AIV intake]` to `error_log`). A bug here must never stop CF7 mail
or storage.

## Hidden fields

`FieldNames::map()` lists the hidden inputs and the attribution keys they map
to:

| Field | Key | Consent category |
| --- | --- | --- |
| `cf7aiv_submission_id` | `submission_id` | none (a random per-submission token, not personal data) |
| `cf7aiv_gclid`, `cf7aiv_gbraid`, `cf7aiv_wbraid`, `cf7aiv_msclkid` | click IDs | marketing |
| `cf7aiv_ga_client_id`, `cf7aiv_ga_session_id` | GA identity | statistics |
| `cf7aiv_utm_source` … `cf7aiv_utm_id` | UTMs | statistics |
| `cf7aiv_first_landing_page`, `cf7aiv_current_page`, `cf7aiv_referrer` | navigation | statistics (URLs are stripped of click parameters without marketing consent) |
| `cf7aiv_ad_storage`, `cf7aiv_analytics_storage`, `cf7aiv_ad_user_data`, `cf7aiv_ad_personalization` | consent states | none |

`FieldNames::injectMissing()` adds any field the form does not already have.
You can therefore place a field in the form template yourself. Every
`cf7aiv_*` field is blocked from AI by `ContextBuilder::isForbiddenField()`.

To add a new attribution key, change all of these together: `FieldNames::map()`,
the collector in `attribution.js` (with its consent category in
`filterStored`), `Attribution::fromPosted()`, `AttributionCookie` (server
fallback), the identifier validation if it is an ID, and
`tests/AttributionJsTest.js` and `tests/IntakeTest.php`.

## attribution.js

The script runs on public pages. It decides consent, collects values, stores
them and fills the hidden fields at submit time.

### Consent: which source wins

`consentState()` checks the sources in this order and uses the first one that
gives an answer:

1. **WordPress Consent API**, but only when the consent type is opt-in
   (`wp_get_consent_type()` returns `optin`). This is the authoritative
   source.
2. **Complianz** (`cmplz_has_consent`), but only once the visitor has made a
   choice. This is detected through its `cmplz_*` cookies; the prefix comes
   from `window.complianz.prefix`.
3. **Google Consent Mode**, read from `dataLayer`. Only `consent update`
   entries count as a decision, judged per category. A `default` alone means
   "not decided yet".

`statistics` maps to `analytics_storage`. `marketing` maps to `ad_storage`.

`refused(consent, category)` separates *refused* from *not decided yet*.

- **Refused:** stored attribution for that category is deleted.
- **Undecided:** localStorage is kept, the cookie is expired, and the hidden
  fields are cleared.

Without this split, a consent manager that publishes late would wipe the click
ID captured on the landing page.

The script recalculates consent on:

- `DOMContentLoaded`;
- `wp_listen_for_consent_change`;
- the Complianz events `cmplz_event_marketing`, `cmplz_event_statistics`,
  `cmplz_status_change` and `cmplz_fire_categories`;
- any `dataLayer.push` (`watchConsentMode` wraps `push`).

`tests/IntegrationTest.php` asserts two things about the script source:

- `collect()` begins with `var consent = consentState(); if (!consent.statistics && !consent.marketing)`;
- the script listens for `wp_listen_for_consent_change`.

Keep both when you edit the script.

### Collecting

`collect()`:

- **Marketing consent:** reads click IDs from the URL. `zc_gad` is accepted
  as a gclid fallback.
- **Statistics consent:** reads UTMs, the first landing page, the current
  page, an *external* referrer (a same-site referrer is ignored), and the GA
  client and session IDs.

  The GA IDs are read from the `_ga` and `_ga_<stream>` cookies, and both the
  GS1 and GS2 session formats are parsed. This works only when
  `cfg.measurementId` holds exactly one GA4 measurement ID. That is
  `IntakeHooks::enqueue()`'s choice: the IDs of enabled `ga4`/`server_gtm`
  destinations, or any configured ID before one is enabled. With none or
  several, no GA IDs are captured.
- **Always:** records the four consent states.

### Storing

- localStorage key and cookie name are both `cf7aiv_attribution`
  (`cfg.cookieName`). `IntakeHooks::enqueue()` passes the config as
  `window.cf7aivAttribution`: `cookieName`, `retentionDays`, `measurementId`
  and the `fields` map.
- The cookie wins over localStorage. localStorage fills keys the cookie lacks.
- The cookie payload is kept under 3,500 encoded characters. Long URLs are
  dropped first.
- Lifetime is `settings.attribution.retention_days` (default 90, max 365).

### Submitting

- `wpcf7beforesubmit` writes the values into `event.detail.formData`. The DOM
  inputs are not enough, because CF7 has already built its `FormData`.
- Each form gets a `submission_id` (a UUID) that rotates only on
  `wpcf7mailsent`. A failed validation and resubmit reuses the token, so the
  server can de-duplicate.

Test harness: `node tests/AttributionJsTest.js` runs the script in a small
fake DOM. In that harness, an empty `search` uses the default URL, so tests
that need a URL without parameters use something like `?page=2`.

## Server-side cookie fallback

`AttributionCookie::serverFallback()` runs on `template_redirect` for
navigation requests only (not REST, AJAX or feeds). It resolves consent from
the WordPress Consent API and the consent states already in the cookie, then
merges the query string into the cookie under the same category rules.
Without consent it expires the cookie. It means a click ID on the landing
URL is captured even if the visitor submits before the script has run.

## Capture

`CaptureService::capture($contactForm, $result, $source = 'cf7', $reference = null)`:

1. Only runs for CF7 statuses `mail_sent` and `mail_failed`. Spam and
   validation failures are ignored.
2. **Attribution:** `Attribution::fromPosted()` reads and validates the hidden
   fields. Click IDs, GA client ID and GA session ID go through
   `IdentifierValidator`.
3. **Consent:** gathers signals from three places:
   - the posted Consent Mode fields;
   - the WordPress Consent API (`resolveWordPressConsent`, opt-in type only);
   - Complianz (`cmplz_has_consent`).

   `Intake\ConsentResolver::resolveWith()` merges them, *most restrictive
   wins*: any `denied` gives denied; otherwise any `unknown` gives unknown;
   otherwise granted.

   Resolvers registered on `cf7aiv_consent_resolvers` take part as extra
   sources named `resolver:<key>`. Each receives the same signals and
   contributes only the keys it decided (`granted` or `denied`); its
   `unknown` is ignored. A resolver that throws, or a filter that throws,
   contributes `unknown` for every key, so a broken integration fails closed.
   The built-in resolver in the filter is skipped, because it always runs.
4. **Diagnostics:** `AttributionDiagnostics::inspect()` sets
   `attribution_ready`. It lists issues such as `missing_valid_click_identifier`,
   `missing_ga_identity` (only when a GA destination is enabled, see
   `gaIdentityNeeded`), `missing_consent` and `consent_denied`.
5. **Fingerprint:** `SubmissionFingerprint::make(formId, posted, submissionId)`
   returns sha256 of the submission token, or of the canonical (key-sorted,
   trimmed) posted data when there is no token, plus the form ID. CF7's
   `get_posted_data_hash()` is used when the token is missing.
6. **Identity hashes:** `identityHashes()` produces them only when
   `privacy.enhanced_matching` is on *and* `ad_user_data` is granted.
   - **Email:** the first field whose name contains `email` and whose value is
     a valid address; sha256 of the lower-cased address.
   - **Phone:** the first field matching
     `/phone|mobile|(?:^|[^a-z])tel(?:e(?:phone|fon|fono))?(?:[^a-z]|$)/`, and
     only when the number is written internationally (`+` or `00`). It is
     normalised to E.164 and hashed. A national number is dropped, because a
     hash of it can never match.
7. **Persist:** `ItemRepository::persist()` inserts with
   `ON DUPLICATE KEY UPDATE` on the unique fingerprint. A second capture of
   the same submission returns the existing ID. It can upgrade a `cf7` item to
   a `flamingo` reference but never downgrade one. Attribution and consent are
   stored encrypted (`Crypto::encrypt`, AES-256-GCM).
8. Fires `cf7aiv_after_capture`, which queues `cf7aiv_classify_item`.

The raw enquiry is **not** copied. The item keeps `source_type` and
`source_reference`.

## Submission sources

`Sources\FlamingoSource` and `Sources\CFDB7Source` implement
`SubmissionSourceInterface`:

- `key()`
- `get($reference)`: returns `['fields' => [...], 'form_id' => …]` or `null`
- `iterate($arguments)`: used for calibration

Both also provide `available()` and `count()` for health checks. Each one
builds its records with `SourceNormalizer::fromArray()`, so every source
returns the same shape.

`CFDB7Source` also implements `SubmissionReferenceLocatorInterface`
(`findReferenceBySubmissionId()`). That is how items captured without a
reference get linked.

### Items captured before their source row exists

When Flamingo is not active, capture happens on `wpcf7_submit` with
`source_type = 'cf7'` and no reference, because CFDB7 writes its row
separately.

The classification worker then asks every source that implements the locator
interface to find the row by the stored `submission_id`. It links the item
with `WpClassificationItemStore::linkSource()` (see
`ClassificationWorker::resolveSource`).

- **"Not found yet"** is a *transient* error, so the job retries.
- **An item with no submission token** cannot be linked; that is a
  *permanent* error.
- **No locator source registered** is also permanent.

## Consent at capture versus consent at send

Capture *records* consent. It does not decide whether a conversion may be
sent; delivery decides that, per destination, from the stored snapshot. See
[Delivery](delivery.md#consent).

A CF7 acceptance checkbox is never treated as statistics or marketing consent.
