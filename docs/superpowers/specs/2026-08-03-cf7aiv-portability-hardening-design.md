# CF7 AI Enquiry Validator 0.2.13 portability hardening design

## Objective

Release `0.2.13` as a generic portability and correctness patch for CF7 AI
Enquiry Validator. The patch must remove the cross-form and cross-tab submission
linking risks found during the second-site Mosaic Media Training trial, make
persistent-source fallback reliable on busy sites, select the correct GA4
browser identity when several Google tags or a custom cookie prefix are in use,
and expose safe extension points for builder-managed site content.

No runtime behavior, identifier, policy or integration may be specific to
Mosaic Media Training. The plugin must remain reusable on unrelated WordPress
sites.

## Supported environment and compatibility

- WordPress `6.5` or later and PHP `8.1` or later remain the supported floor.
- Contact Form 7 remains the intake system.
- Flamingo and Contact Form CFDB7 remain the built-in persistent submission
  sources. At least one is still required for production classification.
- DeepSeek and OpenAI remain supported AI providers.
- Existing GA4, server-side GTM, Microsoft UET and webhook destination behavior
  remains compatible.
- The plugin version, `CF7AIV\VERSION` and `readme.txt` stable tag all become
  `0.2.13`.
- `Migration::VERSION` remains `10`; this patch adds no tables or columns and
  requires no data migration.
- Existing settings, encrypted data, policies, mappings, classifications and
  delivery records remain valid.

## Scope

The patch contains five related changes:

1. form-local, attempt-local operational submission tokens;
2. a generic persistent-source locator contract and reliable CFDB7 lookup;
3. Measurement-ID-aware GA browser identity selection;
4. bounded extension points for site-profile scanning; and
5. cross-provider regression tests for canonical classification dimensions.

It does not redesign classification policy, consent resolution, delivery
eligibility or destination payloads.

## Operational submission token lifecycle

### State model

`cf7aiv_submission_id` is an operational source-link token, not attribution
history. JavaScript must therefore keep it in memory separately from the
consented attribution object.

- Each `.wpcf7 form` DOM element owns one current token in page memory.
- A `WeakMap` keyed by the actual form element is the preferred state holder.
  Equivalent form-element-local state is acceptable if it cannot be shared by
  another form or browser tab.
- The token is created lazily when that form is first populated or submitted.
- The same form reuses its token throughout one submission attempt, including
  repeated consent notifications, `submit` and `wpcf7beforesubmit` events.
- Different forms on one page always receive different tokens.
- Different browser tabs always receive different tokens.
- Tokens must never be written to the attribution cookie, `localStorage`,
  `sessionStorage` or another persistent/shared browser store.
- Token syntax remains compatible with the server validation rule: 8 to 128
  characters from `[A-Za-z0-9._~-]`.

Consent-independent token handling is intentional. Denial or withdrawal of
statistics and marketing consent clears stored attribution and tracking hidden
fields, but the operational hidden field is immediately populated with the
current in-memory token. No click ID, UTM value, URL, referrer or GA identity is
retained through that path.

### Terminal and non-terminal events

Both `wpcf7mailsent` and `wpcf7mailfailed` are terminal for the captured
attempt, because server intake stores both `mail_sent` and `mail_failed`.

For either event, JavaScript must identify only the event's CF7 form, discard
that form's completed token, create a fresh token and repopulate its operational
hidden field for a possible next attempt. Other forms on the page are untouched.

`wpcf7invalid`, `wpcf7spam` and other statuses that the server does not capture
are non-terminal and retain the current token. This lets a user correct and
resubmit the same attempt without creating an orphaned source-link token.

CF7 DOM events are AJAX behavior. A non-AJAX form submission normally navigates
or reloads the document; the new page context naturally has an empty form-state
map and creates a fresh token. Tests must prove this behavior without assuming
that a CF7 DOM completion event fires.

### Stored attribution format

Existing consented attribution storage keeps its current retention behavior,
but `submission_id` is removed on read before any stored object is reused and
is excluded on every write. This also cleans up a token left by an older plugin
version without propagating it to a new form or tab.

## Generic persistent-source linking

### Contract

Add `CF7AIV\Contracts\SubmissionReferenceLocatorInterface` with this method:

```php
public function findReferenceBySubmissionId(
    string $submissionId,
    int $formId,
    string $capturedAt
): ?string;
```

The implementing object must also be a registered
`SubmissionSourceInterface`. It returns its own stable source reference when it
can prove an exact match, and `null` while no match is visible.

For a generic `cf7` item, `ClassificationWorker` must:

1. validate that a source-link token is present;
2. iterate registered submission sources in their filtered registration order;
3. consider only sources implementing both interfaces;
4. call the locator with the token, captured form ID and item `created_at`;
5. link and use the first exact reference returned, storing the source's
   registered key rather than a hard-coded source type; and
6. retain the existing transient retry behavior if no locator can yet resolve
   the item.

If no registered source implements the locator contract, resolution is a
permanent configuration failure. Flamingo's direct reference path remains
unchanged and does not need to implement the locator.

This removes `instanceof CFDB7Source` and the literal `cfdb7` link decision from
the classification worker. Third-party persistent stores can participate by
registering an ordinary source that also implements the locator contract.

### CFDB7 exact-candidate lookup

`CFDB7Source` implements the locator contract. It must not search an arbitrary
newest 50 or 100 rows.

The lookup must:

- reject an invalid token or non-positive form ID before querying;
- constrain candidates to `form_post_id = $formId`;
- use `$wpdb->esc_like()` plus a prepared `LIKE` predicate for the full token to
  reduce candidates before PHP decoding;
- when `capturedAt` is a valid UTC MySQL timestamp, convert it to the WordPress
  site's local time and constrain `form_date` to ten minutes before through ten
  minutes after capture;
- if the timestamp cannot be parsed, omit only the date predicate so older or
  externally created items can still be resolved by form and token;
- order candidate rows by `form_id DESC` and return at most 20 candidate rows;
- decode serialized fields with classes forbidden; and
- accept a row only when its decoded `cf7aiv_submission_id` is scalar and
  `hash_equals()` the complete requested token.

The SQL `LIKE` is candidate selection, not proof. A partial token, a token in a
different field or a token belonging to another CF7 form must not link. The
form, time and token predicates remove the high-volume blind spot while keeping
the PHP result set bounded. A delayed classification retry still searches the
original capture window, rather than a moving newest-row window.

## GA4 browser identity selection

### Configuration supplied to JavaScript

`IntakeHooks::enqueue()` adds the configured GA4 Measurement ID to the public
attribution configuration. It exposes no API secret or other credential. An
empty or invalid Measurement ID disables GA cookie identity capture but does
not affect other attribution fields.

For a valid ID, JavaScript derives the Google session-cookie suffix from the ID
by removing the leading `G-` and mapping characters outside
`[A-Za-z0-9]` to underscores. For example, `G-ABC123` targets a cookie name
ending in `_ga_ABC123`.

### Cookie-pair resolution

The resolver inspects cookie names, not the first cookie whose name begins
`_ga_`.

- A session cookie candidate must end exactly in `_ga_<derived suffix>`.
- The optional text before that ending is the custom Google cookie prefix.
- Its client cookie must be the sibling `<same prefix>_ga`; an empty prefix
  therefore pairs `_ga_<suffix>` with the default `_ga` cookie.
- Both cookie values must pass their existing structural parsing rules.
- Exactly one valid sibling pair must exist. Zero pairs or multiple plausible
  pairs is ambiguous and yields neither `ga_client_id` nor `ga_session_id`.
- Unrelated GA4 properties and unrelated custom prefixes are ignored.
- Identity capture remains gated by statistics/analytics consent.

The fail-closed ambiguity rule prevents an event for one property being joined
to a visitor or session from another property. It does not block sending a
consented event when other attribution is sufficient; it merely omits the
unproven browser identity.

## Extensible site-profile scanning

The assisted-setup scanner remains optional and local. Its default behavior
continues to scan published, unprotected `page` and `post` content and CF7
forms. To support public custom post types and builders that store rendered
content or form references in post metadata, add these WordPress filters:

- `cf7aiv_site_profile_post_types(array $postTypes): array` — extends or narrows
  the default `['page', 'post']` query types.
- `cf7aiv_site_profile_posts(array $posts): array` — lets a local integration
  augment or replace raw post objects before normalization.
- `cf7aiv_site_profile_post_content(string $content, mixed $post): string` —
  supplies builder-managed text and shortcode markup for one post.
- `cf7aiv_site_profile_post_form_ids(array $formIds, mixed $post, string $content): array`
  — adds CF7 form IDs embedded outside ordinary shortcode content.

After every extension point, the scanner re-applies its trust boundaries:

- post type names and form IDs are sanitized;
- only published, password-free content with a local permalink is included;
- page, per-page character and total character limits are applied after
  extensions;
- form/page associations are de-duplicated and limited to positive IDs; and
- no scanner path performs a remote HTTP fetch or reads arbitrary files.

The injectable constructor loaders remain available for unit tests. The new
filters are the supported runtime extension surface and must be documented in
the maintainer guide with a builder-metadata and public-CPT example.

## Provider result canonicalization

The existing generic result validator remains the single authority for mapping
an allowed tier to the tier's canonical outcome/dimension. No provider-specific
or site-specific mapping is added.

Provider-wire regression tests must feed both DeepSeek and OpenAI a syntactically
valid response whose tier is allowed but whose outcome/dimension conflicts with
that tier. Both adapters must produce the canonical tier/outcome pair defined by
the request policy. Existing malformed-response and unavailable-provider
behavior remains unchanged.

## Test strategy

Implementation follows red-green-refactor. New tests must fail for the present
`0.2.12` behavior before production code changes are made.

### JavaScript behavior

The attribution harness must cover:

- two CF7 forms on one page receiving distinct stable tokens;
- two independent page/tab contexts with shared persistent storage receiving
  distinct tokens;
- repeated populate, consent-change, submit and `wpcf7beforesubmit` events
  preserving one attempt's token;
- `wpcf7mailsent` rotating only the completed form;
- `wpcf7mailfailed` rotating only the failed form;
- validation and spam events retaining the token;
- consent denial/withdrawal removing tracking values while retaining a valid
  in-memory operational token;
- cleanup of a legacy persisted `submission_id`;
- a new page context after non-AJAX navigation receiving a fresh token;
- the configured Measurement ID selecting the correct default GA cookie pair;
- multiple GA properties not causing first-cookie selection;
- a matching custom-prefix pair being accepted;
- mismatched, malformed and multiply plausible cookie pairs failing closed; and
- no GA identity without analytics consent.

### PHP behavior

PHP tests must cover:

- generic worker resolution through a custom registered locator with no CFDB7
  class dependency;
- deterministic registered-source order;
- permanent failure when no locator exists and transient retry while locators
  exist but no reference is visible;
- the worker passing token, form ID and capture timestamp exactly;
- CFDB7 SQL form, escaped-token, time-window, ordering and result-limit bounds;
- exact-match rejection for partial tokens, wrong fields and wrong forms;
- resolution when more than 100 newer unrelated rows exist;
- resolution during the existing 60, 120 and 240 second retry schedule;
- invalid token, form and timestamp inputs;
- scanner defaults remaining unchanged;
- a filtered public custom post type;
- builder metadata contributing bounded text and form/page associations;
- rejection of draft, protected, remote-URL and over-limit filtered content;
- Measurement ID localization without exposing destination secrets; and
- mismatched-dimension provider-wire responses for both DeepSeek and OpenAI.

### Full suite and disposable WordPress matrix

Before release, run:

1. every standalone PHP test file;
2. PHP syntax checks for every tracked non-vendor PHP file;
3. the attribution JavaScript behavior suite and `node --check`;
4. secret and site-identifier scans over distributable source;
5. the GitHub release archive checks; and
6. a disposable real-WordPress matrix on PHP `8.1` and `8.3` with current
   WordPress and Contact Form 7.

The real-WordPress matrix must exercise Flamingo-only and CFDB7-only capture,
AJAX success, AJAX mail failure, non-AJAX submission, two forms, two simulated
tabs, delayed CFDB7 visibility, both AI providers through controlled transports,
and GA cookie selection. External AI or advertising APIs are not required for
repeatable CI; controlled transport fixtures must prove the exact outgoing
payloads.

## Privacy and security

- The operational token remains opaque, random and contains no personal data.
- Removing it from cookies and storage reduces correlation and stale-link risk.
- Consent denial still removes optional attribution and browser identity.
- GA API secrets, AI keys and destination credentials are never localized to
  the browser or written to tests, documentation or release artifacts.
- Builder extensions remain local WordPress filters subject to the scanner's
  existing content and origin bounds.
- Serialized CFDB7 data is decoded with object instantiation disabled.

## Non-goals

This patch does not:

- remove the requirement for a persistent submission plugin;
- add a new database-backed submission store;
- make the site-profile scanner crawl public URLs;
- add builder-specific adapters to core;
- alter consent policy or legal-basis decisions;
- change classification tiers, outcomes or delivery eligibility;
- send new production analytics or advertising events during development; or
- contain Mosaic property IDs, Ads IDs, domains, forms, policies or credentials.

## Release and deployment acceptance

The release is acceptable only when:

- all automated and disposable-site tests above pass;
- distributable source contains no site-specific identifiers or secrets;
- the package root and version identities pass the existing release contract;
- upgrade from `0.2.12` preserves settings and data without a schema migration;
- a code review finds no remaining page-global token, persisted operational
  token, `instanceof CFDB7Source` worker dependency or first-`_ga_` selection;
  and
- verification evidence distinguishes controlled transport proof from any
  optional observation in third-party analytics interfaces.

After those conditions are met, produce the verified `0.2.13` release artifact
and checksum. Test it first on a disposable third WordPress installation. Only
then may Mosaic be upgraded, following its live-site backup, exact-path,
serial-remote-call and post-deployment verification safeguards. Production
deployment is not part of the implementation commit itself.
