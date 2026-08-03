# CF7 AI Enquiry Validator 0.2.13 Portability Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Release a site-independent `0.2.13` patch that makes CF7 submission linking form- and attempt-safe, resolves persistent sources reliably, selects only the configured GA4 identity, extends assisted scanning safely, and proves the result across isolated and real WordPress tests.

**Architecture:** Keep attribution history consented and persistent, but move the operational submission token into form-local JavaScript memory. Let any registered persistent source implement a small locator contract, with CFDB7 using form-, time- and exact-token-qualified candidates. Pass only one unique configured Google Measurement ID to the browser, add bounded WordPress filters around the existing scanner, and preserve the shared result validator as the provider-independent canonicalization boundary.

**Tech Stack:** WordPress 6.5+, Contact Form 7, PHP 8.1/8.3, vanilla JavaScript, Node.js 20, WP-CLI, Docker Compose, MariaDB, GitHub Actions, Git.

## Global Constraints

- WordPress `6.5` or later and PHP `8.1` or later remain the supported floor.
- Contact Form 7 remains the intake system.
- Flamingo and Contact Form CFDB7 remain the built-in persistent submission sources; at least one remains required for production classification.
- DeepSeek and OpenAI remain supported AI providers.
- Plugin header, `CF7AIV\VERSION`, `readme.txt` stable tag and release tag must agree on `0.2.13`.
- `Migration::VERSION` remains `10`; no table, column or data migration is permitted.
- Existing settings, encrypted records, policies, mappings, classifications and deliveries remain valid.
- No runtime source, test fixture or release artifact may contain Mosaic domains, property IDs, Ads IDs, form IDs, policies, credentials or private submissions.
- Operational submission tokens are opaque 8-128 character values from `[A-Za-z0-9._~-]` and are never persisted in browser storage.
- Consent denial continues to remove click IDs, UTM values, URLs, referrers and GA identity.
- External AI and advertising APIs are not called by repeatable automated tests.
- Production is not modified until the release artifact passes the disposable WordPress matrix and live-site safeguards are complete.

## File and responsibility map

- Create `src/Contracts/SubmissionReferenceLocatorInterface.php`: generic exact source-link lookup contract.
- Modify `assets/js/attribution.js`: form-local attempt tokens, terminal-event rotation and Measurement-ID-aware GA cookie pairing.
- Modify `src/Intake/IntakeHooks.php`: expose only one validated Measurement ID shared by the configured GA4/server-GTM routes to attribution JavaScript.
- Modify `src/Classification/ClassificationWorker.php`: resolve generic CF7 items through registered locator sources without a CFDB7 class dependency.
- Modify `src/Sources/CFDB7Source.php`: implement form/time/token-qualified exact candidate lookup.
- Modify `src/Admin/SiteProfileScanner.php`: add bounded public-post and builder-content extension filters.
- Modify `tests/AttributionJsTest.js`: multi-form/tab, terminal lifecycle, legacy storage and GA cookie regression harness.
- Modify `tests/IntakeTest.php`: public Measurement ID localization and secret-exclusion checks.
- Modify `tests/ClassificationTest.php`: generic locator, CFDB7 high-volume lookup and provider-wire canonicalization regressions.
- Modify `tests/AssistedSetupTest.php`: scanner filter execution, custom post type, builder metadata and trust-boundary regressions.
- Modify `docs/EXTENDING.md` and `README.md`: document locator and scanner extension contracts.
- Create `tests/wordpress/docker-compose.yml`: disposable MariaDB and WordPress runtime.
- Create `tests/wordpress/bootstrap.ps1`: install WordPress, CF7 and one persistent-source mode with deterministic forms/pages.
- Create `tests/wordpress/runtime-assertions.php`: WordPress-loaded locator, provider and schema assertions.
- Create `tests/wordpress/portability.spec.js`: HTTP/browser end-to-end form, tab, mail and attribution checks.
- Create `tests/wordpress/run-matrix.ps1`: run PHP 8.1/8.3 and Flamingo/CFDB7 combinations serially.
- Modify `cf7-ai-enquiry-validator.php`, `readme.txt` and `CHANGELOG.md`: publish the patch identity and operator-facing change record.
- Modify `VERIFICATION.md`: record commands, versions, hashes and observed results without credentials or personal data.

---

### Task 1: Form-local operational submission lifecycle

**Files:**
- Modify: `tests/AttributionJsTest.js`
- Modify: `assets/js/attribution.js`

**Interfaces:**
- Consumes: `window.cf7aivAttribution.fields`, where `cf7aiv_submission_id` maps to `submission_id`.
- Produces: `submissionIdFor(form): string`, `populateForm(form): void`, and `rotateSubmission(event): void` internal JavaScript behavior.
- Produces: no persisted `submission_id` property in the plugin cookie or local storage.

- [ ] **Step 1: Extend the JavaScript harness to model forms, wrappers, tabs and event targets**

Replace the single `form` fixture with a `makeForm()` helper and accept
`formCount`, `storageMap` and `cookieMap` options. Return `forms`, `wrappers`,
`storedPayload()` and `fieldValue(formIndex, fieldName)`. The wrapper must expose
`querySelector('form')`, and each form must expose `matches('.wpcf7 form')`,
`closest('.wpcf7 form')` and field lookup:

```js
function makeForm(fields) {
	const inputs = {};
	Object.keys(fields).forEach((name) => { inputs[name] = { value: '' }; });
	const form = {
		inputs,
		matches: (selector) => selector === '.wpcf7 form',
		closest: (selector) => selector === '.wpcf7 form' ? form : null,
		querySelector: (selector) => {
			const match = selector.match(/^\[name="(.+)"\]$/);
			return match ? inputs[match[1]] || null : null;
		},
	};
	return form;
}
```

- [ ] **Step 2: Write failing lifecycle regressions**

Add assertions that:

```js
const first = page.fieldValue(0, 'cf7aiv_submission_id');
const second = page.fieldValue(1, 'cf7aiv_submission_id');
check(first !== '' && second !== '' && first !== second,
	'two forms must receive distinct operational tokens');
check(!Object.prototype.hasOwnProperty.call(page.storedPayload() || {}, 'submission_id'),
	'operational tokens must never enter persistent attribution');

page.emit('wpcf7mailfailed', { target: page.wrappers[0] });
check(page.fieldValue(0, 'cf7aiv_submission_id') !== first,
	'mail failure must rotate the failed form token');
check(page.fieldValue(1, 'cf7aiv_submission_id') === second,
	'mail failure must not rotate another form token');
```

Use the same shared `Map` for two separately constructed harnesses and assert
their form tokens differ. Assert submit, `wpcf7beforesubmit`, consent change,
`wpcf7invalid` and `wpcf7spam` retain a form token; `wpcf7mailsent` and
`wpcf7mailfailed` rotate only the targeted form; a fresh page harness after a
simulated non-AJAX navigation gets a new token; and stale persisted
`submission_id` is removed on the next write.

- [ ] **Step 3: Run the JavaScript test and verify RED**

Run:

```text
node tests/AttributionJsTest.js
```

Expected: FAIL messages for shared form tokens, persisted `submission_id`,
cross-form rotation and missing `wpcf7mailfailed` rotation.

- [ ] **Step 4: Separate stored attribution from form attempt state**

In `assets/js/attribution.js`, replace the page-global `submissionId` with:

```js
var submissionIds = new WeakMap();

function formElement(target) {
	if (!target) return null;
	if (target.matches && target.matches('.wpcf7 form')) return target;
	if (target.closest) {
		var closest = target.closest('.wpcf7 form');
		if (closest) return closest;
	}
	return target.querySelector ? target.querySelector('form') : null;
}

function submissionIdFor(form) {
	var value = submissionIds.get(form);
	if (!value) {
		value = createSubmissionId();
		submissionIds.set(form, value);
	}
	return value;
}
```

Make `collect()` return only consented attribution. Delete `submission_id` from
the result of `readStored()` before filtering or writing. Add the operational
token only to the values passed to the selected form:

```js
function populateForm(form) {
	var values = collect();
	values.submission_id = submissionIdFor(form);
	populate(form, values);
}
```

Make field clearing skip mappings whose value is `submission_id`. Replace the
global completion callback with:

```js
function rotateSubmission(event) {
	var form = formElement(event && event.target);
	if (!form) return;
	submissionIds.set(form, createSubmissionId());
	populateForm(form);
}
```

Register `rotateSubmission` for both `wpcf7mailsent` and
`wpcf7mailfailed`. Leave invalid, spam and aborted events unregistered.

- [ ] **Step 5: Run lifecycle tests and syntax check; verify GREEN**

Run:

```text
node tests/AttributionJsTest.js
node --check assets/js/attribution.js
```

Expected: `PASS AttributionJsTest` and both processes exit zero.

- [ ] **Step 6: Commit the isolated lifecycle change**

```text
git add assets/js/attribution.js tests/AttributionJsTest.js
git commit -m "fix: isolate CF7 submission attempt tokens"
```

### Task 2: Measurement-ID-aware GA browser identity

**Files:**
- Modify: `tests/AttributionJsTest.js`
- Modify: `tests/IntakeTest.php`
- Modify: `assets/js/attribution.js`
- Modify: `src/Intake/IntakeHooks.php`

**Interfaces:**
- Consumes: `settings.destinations.ga4.measurement_id`.
- Produces: public `window.cf7aivAttribution.measurementId: string` with no secret.
- Produces: `gaIdentity(): {ga_client_id?: string, ga_session_id?: string}` only for one unambiguous sibling cookie pair.

- [ ] **Step 1: Write failing intake localization assertions**

In `tests/IntakeTest.php`, capture `wp_add_inline_script()` input. Set options to
include `measurement_id => 'G-ABC123'` and a sentinel API secret in a separate
setting/secret stub. Call `IntakeHooks::enqueue()` and assert:

```php
cf7aiv_intake_assert(str_contains($GLOBALS['cf7aiv_inline_script'], '"measurementId":"G-ABC123"'), 'The configured GA4 Measurement ID must be public attribution configuration.');
cf7aiv_intake_assert(! str_contains($GLOBALS['cf7aiv_inline_script'], 'PRIVATE-GA-SECRET'), 'No GA4 API secret may be localized.');
```

Repeat with `measurement_id => 'UA-invalid'` and require an empty localized ID.

- [ ] **Step 2: Write failing GA cookie-pair JavaScript regressions**

Pass `measurementId` through `makeHarness()`. Cover these exact cases:

```js
{
	const page = makeHarness({
		fields,
		measurementId: 'G-ABC123',
		dataLayer: consent(true, false),
		cookies: {
			_ga: 'GA1.1.111111111.1111111111',
			_ga_OTHER: 'GS1.1.s2222222222.o1',
			_ga_ABC123: 'GS1.1.s3333333333.o1',
		},
	});
	page.emit('DOMContentLoaded');
	check(page.storedPayload().ga_client_id === '111111111.1111111111', 'configured property must use its default client cookie');
	check(page.storedPayload().ga_session_id === '3333333333', 'configured property must use its matching session cookie');
}
```

Add `brand_ga` plus `brand_ga_ABC123` for a custom prefix. Add malformed base,
malformed session, no matching suffix, and both default/custom matching pairs;
each ambiguous case must omit both identity fields. Assert analytics denial
always omits identity.

- [ ] **Step 3: Run focused tests and verify RED**

Run:

```text
php tests/IntakeTest.php
node tests/AttributionJsTest.js
```

Expected: localization and configured-cookie assertions fail against `0.2.12`.

- [ ] **Step 4: Localize only a validated Measurement ID**

In `IntakeHooks::enqueue()`, derive the public value from current settings:

```php
$ga4 = (array) ($settings['destinations']['ga4'] ?? []);
$measurementId = strtoupper(trim((string) ($ga4['measurement_id'] ?? '')));
if (preg_match('/\AG-[A-Z0-9]+\z/', $measurementId) !== 1) {
	$measurementId = '';
}
```

Add `'measurementId' => $measurementId` to the existing inline configuration.
Do not read or localize `Crypto::secret('ga4_api_secret')`.

- [ ] **Step 5: Implement exact GA cookie pairing**

Replace first-cookie selection with name/value parsing. The matching core is:

```js
var measurement = typeof cfg.measurementId === 'string' ? cfg.measurementId.trim().toUpperCase() : '';
if (!/^G-[A-Z0-9]+$/.test(measurement)) return {};
var suffix = measurement.slice(2).replace(/[^A-Z0-9]/g, '_');
var ending = '_ga_' + suffix;
var pairs = [];

cookieEntries().forEach(function (entry) {
	if (!entry.name.endsWith(ending)) return;
	var prefix = entry.name.slice(0, -ending.length);
	var client = cookieEntries().filter(function (candidate) {
		return candidate.name === prefix + '_ga';
	});
	if (client.length !== 1) return;
	var clientMatch = client[0].value.match(/^GA\d+\.\d+\.(\d+\.\d+)$/);
	var sessionMatch = entry.value.match(/(?:^|\.)s(\d{6,20})(?:\.|$)/);
	if (clientMatch && sessionMatch) pairs.push({ ga_client_id: clientMatch[1], ga_session_id: sessionMatch[1] });
});
return pairs.length === 1 ? pairs[0] : {};
```

Parse each raw cookie with the existing safe URI-decoding behavior. Treat
duplicate cookie names as multiple candidates rather than silently choosing
one.

- [ ] **Step 6: Run focused and adjacent tests; verify GREEN**

Run:

```text
php tests/IntakeTest.php
php tests/CookieRegressionTest.php
node tests/AttributionJsTest.js
node --check assets/js/attribution.js
```

Expected: all four commands pass.

- [ ] **Step 7: Commit the GA identity change**

```text
git add src/Intake/IntakeHooks.php assets/js/attribution.js tests/IntakeTest.php tests/AttributionJsTest.js
git commit -m "fix: bind GA identity to configured property"
```

### Task 3: Generic persistent-source locator contract

**Files:**
- Create: `src/Contracts/SubmissionReferenceLocatorInterface.php`
- Modify: `tests/ClassificationTest.php`
- Modify: `src/Classification/ClassificationWorker.php`
- Modify: `docs/EXTENDING.md`

**Interfaces:**
- Produces: `SubmissionReferenceLocatorInterface::findReferenceBySubmissionId(string $submissionId, int $formId, string $capturedAt): ?string`.
- Consumes: filtered `array<string,SubmissionSourceInterface>` from `cf7aiv_submission_sources`.
- Produces: `linkSource($itemId, $registeredKey, $reference)` for the first exact registered locator result.

- [ ] **Step 1: Write failing generic-locator tests**

In `tests/ClassificationTest.php`, add a source fixture implementing both
interfaces:

```php
final class CF7AIVTestLocatorSource implements SubmissionSourceInterface, SubmissionReferenceLocatorInterface {
	public array $calls = [];
	public function __construct(private readonly string $sourceKey, private readonly ?string $located) {}
	public function key(): string { return $this->sourceKey; }
	public function findReferenceBySubmissionId(string $submissionId, int $formId, string $capturedAt): ?string {
		$this->calls[] = compact('submissionId', 'formId', 'capturedAt');
		return $this->located;
	}
	public function get(string $reference): ?array { return ['form_id' => 5, 'fields' => ['message' => 'A business enquiry.'], 'is_historical' => false, 'is_calibration' => false]; }
	public function iterate(array $arguments = []): iterable { return []; }
}
```

Use a generic item containing form `5`, creation time
`2026-08-03 10:20:30` and an encrypted token. Assert the worker passes those
three exact values, links the custom registered key and classifies from it.
Register two locators and prove the first exact result wins. Register locators
that return `null` and prove transient retry scheduling. Register no locator
and prove permanent failure with no retry.

- [ ] **Step 2: Run the classification test and verify RED**

Run:

```text
php tests/ClassificationTest.php
```

Expected: failure because `SubmissionReferenceLocatorInterface` is absent and
the worker only accepts `CFDB7Source`.

- [ ] **Step 3: Add the locator interface**

Create the interface exactly as approved:

```php
<?php

declare(strict_types=1);

namespace CF7AIV\Contracts;

interface SubmissionReferenceLocatorInterface {
	public function findReferenceBySubmissionId(string $submissionId, int $formId, string $capturedAt): ?string;
}
```

- [ ] **Step 4: Replace the worker's CFDB7 dependency**

Remove `use CF7AIV\Sources\CFDB7Source`. In the generic `cf7` branch, build an
ordered locator list from registered string keys and objects implementing both
contracts. If the list is empty, throw the current generic persistent-source
configuration error as a `PermanentProviderException`. Otherwise:

```php
foreach ($locators as $registeredKey => $locator) {
	$reference = $locator->findReferenceBySubmissionId(
		$submissionId,
		(int) ($item['form_id'] ?? 0),
		(string) ($item['created_at'] ?? ''),
	);
	if ($reference === null || trim($reference) === '') { continue; }
	$this->items->linkSource($itemId, $registeredKey, $reference);
	$item['source_type'] = $registeredKey;
	$item['source_reference'] = $reference;
	return [$locator, $reference];
}
throw new TransientProviderException('Persistent source link is not visible yet.');
```

Reject empty or non-string registration keys instead of storing an invalid
source type.

- [ ] **Step 5: Document third-party locator registration**

In `docs/EXTENDING.md`, add the exact interface signature, state that the same
object must implement `SubmissionSourceInterface`, show registration through
`cf7aiv_submission_sources`, and specify exact-token proof, `null` for not-yet
visible records and stable opaque references.

- [ ] **Step 6: Run classification and intake tests; verify GREEN**

Run:

```text
php tests/ClassificationTest.php
php tests/IntakeTest.php
```

Expected: both pass and no classification worker import references
`CFDB7Source`.

- [ ] **Step 7: Commit the generic source contract**

```text
git add src/Contracts/SubmissionReferenceLocatorInterface.php src/Classification/ClassificationWorker.php tests/ClassificationTest.php docs/EXTENDING.md
git commit -m "feat: generalize persistent source linking"
```

### Task 4: Reliable CFDB7 exact-candidate lookup

**Files:**
- Modify: `tests/ClassificationTest.php`
- Modify: `src/Sources/CFDB7Source.php`

**Interfaces:**
- Consumes: locator arguments `(submissionId, formId, capturedAt)` from Task 3.
- Produces: a CFDB7 `form_id` string only after full decoded token equality.

- [ ] **Step 1: Replace newest-row fixtures with query-aware CFDB7 fixtures**

Extend the fake `$wpdb` with `esc_like()`, prepared `%s` handling and candidate
rows containing form IDs, post IDs, dates and serialized values. Record the
final SQL. Include a matching row plus at least 101 newer unrelated rows, a
wrong-form row, a partial-token row and a token stored under another field.

- [ ] **Step 2: Write failing lookup assertions**

Call:

```php
$reference = $cfdb7->findReferenceBySubmissionId(
	'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
	5,
	'2026-08-03 10:20:30',
);
```

Assert reference `90` is returned and SQL contains all of:

```php
form_post_id = 5
form_value LIKE '%aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee%'
form_date BETWEEN '2026-08-03 10:10:30' AND '2026-08-03 10:30:30'
ORDER BY form_id DESC LIMIT 20
```

Assert invalid token and form ID perform no query. Assert invalid timestamp
omits `form_date BETWEEN` but still uses form/token/limit predicates. Assert no
partial, wrong-field or wrong-form candidate matches. Re-run after simulated
60, 120 and 240 second worker delays and require the same original time window.

- [ ] **Step 3: Run the classification test and verify RED**

Run:

```text
php tests/ClassificationTest.php
```

Expected: argument-count or SQL assertions fail because `0.2.12` reads only the
newest 50 rows without form, token or capture-time predicates.

- [ ] **Step 4: Implement the locator contract and exact candidate query**

Make `CFDB7Source` implement `SubmissionReferenceLocatorInterface`. Validate
the token and form ID before calling `$wpdb`. Build prepared clauses with:

```php
$clauses = ['form_post_id = %d', 'form_value LIKE %s'];
$parameters = [$formId, '%' . $wpdb->esc_like($submissionId) . '%'];
```

Parse `capturedAt` with `DateTimeImmutable::createFromFormat()` in UTC and reject
warnings/errors. Convert the `-600` and `+600` second bounds through
`get_date_from_gmt()` when available, append `form_date BETWEEN %s AND %s`, then
query:

```php
SELECT form_id, form_value
FROM {$table}
WHERE <prepared clauses>
ORDER BY form_id DESC
LIMIT 20
```

Decode with `unserialize($value, ['allowed_classes' => false])`. Return only
when decoded `cf7aiv_submission_id` is scalar and full `hash_equals()` succeeds.

- [ ] **Step 5: Run classification and intake tests; verify GREEN**

Run:

```text
php tests/ClassificationTest.php
php tests/IntakeTest.php
```

Expected: both pass, including high-volume and delayed-resolution cases.

- [ ] **Step 6: Commit the CFDB7 lookup change**

```text
git add src/Sources/CFDB7Source.php tests/ClassificationTest.php
git commit -m "fix: qualify CFDB7 source link candidates"
```

### Task 5: Bounded scanner extension surface

**Files:**
- Modify: `tests/AssistedSetupTest.php`
- Modify: `src/Admin/SiteProfileScanner.php`
- Modify: `docs/EXTENDING.md`
- Modify: `README.md`

**Interfaces:**
- Produces: filters `cf7aiv_site_profile_post_types`, `cf7aiv_site_profile_posts`, `cf7aiv_site_profile_post_content`, and `cf7aiv_site_profile_post_form_ids`.
- Preserves: 50-page, 2,000-character-per-page and 40,000-character-total bounds.

- [ ] **Step 1: Make the assisted-setup harness execute filters**

Replace its no-op `add_filter()` with priority-aware storage and add an
`apply_filters()` stub that calls callbacks with the registered accepted
argument count. Reset scanner-specific filters between cases.

- [ ] **Step 2: Write failing public-CPT and builder-content tests**

Register:

```php
add_filter('cf7aiv_site_profile_post_types', static fn (array $types): array => [...$types, 'case_study']);
add_filter('cf7aiv_site_profile_post_content', static function (string $content, mixed $post): string {
	return ((int) $post->ID === 44) ? $content . ' Builder service details [contact-form-7 id="34"]' : $content;
}, 10, 2);
add_filter('cf7aiv_site_profile_post_form_ids', static fn (array $ids, mixed $post, string $content): array => ((int) $post->ID === 44 && str_contains($content, 'Builder service details')) ? [...$ids, 34, -1, 34] : $ids, 10, 3);
```

Assert `get_posts()` receives the sanitized three types, the public local case
study appears once with bounded builder text, and form `34` gains page `44` once.
Through `cf7aiv_site_profile_posts`, inject draft, password-protected,
remote-permalink, unselected-post-type and 60 valid posts; assert the first four
are rejected and the normalized output remains at most 50 pages/40,000
characters. Return non-array filter values and prove defaults/bounds fail
closed without throwing.

- [ ] **Step 3: Run the assisted-setup test and verify RED**

Run:

```text
php tests/AssistedSetupTest.php
```

Expected: custom post type, builder content and form association assertions
fail because no runtime scanner filters are applied.

- [ ] **Step 4: Implement post loading and content filters**

Add private sanitizers for post types and positive form IDs. Filter defaults
before `get_posts()`, then filter the raw post array. For each post, enforce its
status, password, selected type and local URL before inclusion. Obtain content
with:

```php
$content = (string) $this->read($page, ['post_content', 'content'], '');
if (function_exists('apply_filters')) {
	$filtered = apply_filters('cf7aiv_site_profile_post_content', $content, $page);
	$content = is_string($filtered) ? $filtered : $content;
}
```

Use that same filtered content for normalization and shortcode extraction.
Filter the discovered IDs, normalize to positive unique integers and merge into
`$formPageIds`. Apply page and character limits after all filters.

- [ ] **Step 5: Document exact extension examples and boundaries**

Add copy-paste examples for a `case_study` CPT and builder metadata to
`docs/EXTENDING.md`. In `README.md`, link the extension guide and state that the
scanner is local, optional, public-only, bounded and never fetches page URLs.

- [ ] **Step 6: Run assisted setup and privacy-adjacent tests; verify GREEN**

Run:

```text
php tests/AssistedSetupTest.php
php tests/AdminPrivacyTest.php
php tests/AdminTest.php
```

Expected: all pass, including existing provider-corpus privacy bounds.

- [ ] **Step 7: Commit scanner extensibility**

```text
git add src/Admin/SiteProfileScanner.php tests/AssistedSetupTest.php docs/EXTENDING.md README.md
git commit -m "feat: extend bounded site profile scanning"
```

### Task 6: Provider-wire canonicalization regression lock

**Files:**
- Modify: `tests/ClassificationTest.php`

**Interfaces:**
- Consumes: `ResultValidator::fromJson()` through both real provider adapters.
- Produces: canonical evidence dimensions from `ClassificationRequest::allowedEvidence`.

- [ ] **Step 1: Add a v2 request with canonical evidence mapping**

Construct a request whose `allowedEvidence` maps `service_fit` to the
`service_fit` dimension. Create a syntactically valid provider JSON response
that uses the allowed `service_fit` code but claims the separately allowed
`specificity` dimension. Keep the tier and outcome valid.

- [ ] **Step 2: Pass the mismatched response through both wire adapters**

Wrap the JSON in the DeepSeek `choices[0].message.content` envelope and the
OpenAI `output[].content[].text` envelope. Assert:

```php
foreach ([$deepSeekResult, $openAiResult] as $result) {
	cf7aiv_classification_assert($result->tierId === 'website_rebuild', 'Provider adapter must preserve the allowed tier.');
	cf7aiv_classification_assert($result->outcomeId === 'primary_opportunity', 'Provider adapter must preserve the allowed outcome.');
	cf7aiv_classification_assert($result->evidence[0]->code === 'service_fit' && $result->evidence[0]->dimension === 'service_fit', 'Provider adapter must canonicalize evidence dimension from its allowed code.');
}
```

Also retain existing malformed-response retry and refusal assertions.

- [ ] **Step 3: Run the characterization test**

Run:

```text
php tests/ClassificationTest.php
```

Expected: PASS. This task locks the already-correct shared validator behavior;
if either provider fails, fix only the shared provider/validator boundary and
do not add provider- or site-specific mappings.

- [ ] **Step 4: Commit the regression lock**

```text
git add tests/ClassificationTest.php
git commit -m "test: lock provider outcome canonicalization"
```

### Task 7: Disposable real-WordPress portability matrix

**Files:**
- Create: `tests/wordpress/docker-compose.yml`
- Create: `tests/wordpress/bootstrap.ps1`
- Create: `tests/wordpress/bootstrap.php`
- Create: `tests/wordpress/runtime-assertions.php`
- Create: `tests/wordpress/portability.spec.js`
- Create: `tests/wordpress/run-matrix.ps1`
- Create: `tests/wordpress/package.json`
- Create: `tests/wordpress/package-lock.json`
- Modify: `VERIFICATION.md`

**Interfaces:**
- Consumes: `CF7AIV_WORDPRESS_IMAGE` (`wordpress:6.9-php8.1-apache` or `wordpress:7.0.2-php8.3-apache`) and `CF7AIV_SOURCE_MODE` (`flamingo` or `cfdb7`).
- Produces: an isolated WordPress site on a loopback port, deterministic test-only forms and zero retained containers/volumes after each matrix cell.

- [x] **Step 1: Create the disposable Compose topology**

Define MariaDB `10.11`, one WordPress service using
`${CF7AIV_WORDPRESS_IMAGE}`, a bind mount of the repository at
`/var/www/html/wp-content/plugins/cf7-ai-enquiry-validator`, an isolated named
database volume and loopback-only port `${CF7AIV_HTTP_PORT}:80`. Use test-only
database credentials (`cf7aiv_test`) and no production hostnames or secrets.

- [x] **Step 2: Create deterministic WordPress bootstrap**

`bootstrap.ps1` must wait for MariaDB and WordPress configuration, use the
disposable WP-CLI service,
container, run `wp core install` against `http://127.0.0.1:$CF7AIV_HTTP_PORT`,
activate CF7 AI Enquiry Validator and Contact Form 7, then activate exactly one
of Flamingo or Contact Form CFDB7 according to `CF7AIV_SOURCE_MODE`. Create two
CF7 forms, one normal mail path and one mail-failure path controlled by a
test-only MU plugin. Create one page containing both shortcodes and flush
permalinks.

The MU plugin must return `false` from `pre_wp_mail` only when posted field
`test-mail-mode` equals `fail`; it must not alter production plugin files.

- [x] **Step 3: Add WordPress-loaded runtime assertions**

In `runtime-assertions.php`, run under `wp eval-file` and assert:

- plugin/schema versions load and schema remains `10`;
- the active persistent source is available;
- a CFDB7 table with 101 newer unrelated rows still resolves the exact
  form/time/token candidate;
- the browser lifecycle creates classification items after both successful and
  failed-mail CF7 submissions.

The custom-source locator contract and controlled DeepSeek/OpenAI adapter
assertions remain deterministic unit-level integration coverage, where their
in-memory dependencies can be inspected without external services.

Exit nonzero on any assertion and print only deterministic record IDs and
states, never submitted names, email addresses, phone numbers or message text.

- [x] **Step 4: Add browser end-to-end assertions**

Install the pinned disposable browser from `tests/wordpress/package-lock.json`,
then run Playwright against the loopback site. The spec must:

- load the page with both forms and assert distinct hidden submission IDs;
- submit the success form and assert only its ID rotates;
- submit the forced mail-failure form and assert its ID rotates;
- submit an invalid form and assert its ID remains;
- reload the public page and assert the new page lifecycle gets a fresh ID;
- set default, unrelated and custom-prefix GA cookies for Measurement ID
  `G-TEST123`, then inspect hidden fields to prove exact configured-property
  selection. Custom-prefix and ambiguity behavior remain covered in the
  JavaScript unit suite; and
- inspect only item counts through WordPress-loaded assertions, never
  submission content.

- [x] **Step 5: Create the serial four-cell runner**

`run-matrix.ps1` loops in this order:

```text
wordpress:6.9-php8.1-apache + flamingo
wordpress:6.9-php8.1-apache + cfdb7
wordpress:7.0.2-php8.3-apache + flamingo
wordpress:7.0.2-php8.3-apache + cfdb7
```

For each cell, assign a unique Compose project and port, run bootstrap, runtime
assertions and Playwright, then execute `docker compose down --volumes
--remove-orphans` in a shell `trap`. Remote or shared services are never used.

- [x] **Step 6: Run the matrix and verify GREEN**

Run from a shell with Docker available:

```text
powershell -NoProfile -ExecutionPolicy Bypass -File tests/wordpress/run-matrix.ps1
```

Expected: four named cells print PASS for bootstrap, runtime and browser tests;
`docker ps -a --filter name=cf7aiv-portability` and `docker volume ls --filter
name=cf7aiv-portability` show no leftovers.

- [x] **Step 7: Record sanitized matrix evidence**

Append the UTC date, exact WordPress/CF7/Flamingo/CFDB7/PHP versions, four cell
results and test commit to `VERIFICATION.md`. Record controlled transport proof
as controlled proof, not as third-party dashboard observation.

- [x] **Step 8: Commit disposable integration coverage**

```text
git add tests/wordpress VERIFICATION.md
git commit -m "test: add disposable WordPress portability matrix"
```

### Task 8: Patch identity, release documentation and complete verification

**Files:**
- Modify: `cf7-ai-enquiry-validator.php`
- Modify: `readme.txt`
- Modify: `CHANGELOG.md`
- Modify: `README.md`
- Modify: `VERIFICATION.md`

**Interfaces:**
- Produces: exact version identity `0.2.13` and release tag contract `v0.2.13`.
- Consumes: every automated and real-WordPress test from Tasks 1-7.

- [x] **Step 1: Update all version identities and user-facing changes**

Change the plugin header, `CF7AIV\VERSION` and stable tag to `0.2.13`. Add a
`0.2.13` changelog entry covering form-local tokens, both mail terminal events,
generic source locators, qualified CFDB7 lookup, configured GA cookie pairing,
scanner extension filters and provider regression coverage. State that no
schema migration is required.

- [x] **Step 2: Run every standalone PHP test**

Run in PowerShell:

```powershell
Get-ChildItem tests\*Test.php | Sort-Object Name | ForEach-Object {
	& php $_.FullName
	if ($LASTEXITCODE -ne 0) { throw "Failed: $($_.Name)" }
}
```

Expected: every test prints its PASS marker and the loop exits zero.

- [x] **Step 3: Lint every tracked non-vendor PHP file**

Run:

```powershell
git ls-files '*.php' | Where-Object { $_ -notlike 'vendor/*' } | ForEach-Object {
	& php -l $_
	if ($LASTEXITCODE -ne 0) { throw "Lint failed: $_" }
}
```

Expected: every file reports no syntax errors.

- [x] **Step 4: Run JavaScript, release and identifier checks**

Run:

```text
node tests/AttributionJsTest.js
node --check assets/js/attribution.js
php tests/ReleasePackageTest.php
rg -n -i "mosaicmediatraining|276763795|sk-[A-Za-z0-9_-]{24,}|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY" --glob '!docs/superpowers/**' --glob '!VERIFICATION.md' .
```

Expected: both Node checks and release test pass. The identifier scan returns no
runtime, test-fixture or release-document hits; any changelog/history-only hit
must be removed from distributable content or explicitly excluded by the
packager before proceeding.

- [x] **Step 5: Re-run the disposable WordPress matrix at the release commit**

Run:

```text
powershell -NoProfile -ExecutionPolicy Bypass -File tests/wordpress/run-matrix.ps1
```

Expected: all four cells pass again with no remaining containers or volumes.

- [x] **Step 6: Build and inspect the release artifact**

Create a fresh temporary directory outside the repository and run in
PowerShell:

```powershell
$releaseDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ("cf7aiv-0.2.13-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $releaseDirectory | Out-Null
php tools/build-release.php --tag=v0.2.13 --output-dir=$releaseDirectory
if ($LASTEXITCODE -ne 0) { throw 'Release build failed.' }
```

Verify the JSON says version `0.2.13`, recompute SHA-256, reopen the ZIP, and
assert no `tests/`,
`tools/`, `.github/`, `docs/superpowers/`, `VERIFICATION.md` or credential
pattern exists inside its single `cf7-ai-enquiry-validator/` root.

- [x] **Step 7: Update verification evidence and commit the patch identity**

Record exact pass counts, runtime versions, matrix cells, artifact filename and
SHA-256 in `VERIFICATION.md`, then commit:

```text
git add cf7-ai-enquiry-validator.php readme.txt CHANGELOG.md README.md VERIFICATION.md
git commit -m "release: prepare CF7 AI Enquiry Validator 0.2.13"
```

### Task 9: Review, release and production acceptance

**Files:**
- Modify only if review finds a concrete defect; repeat that task's red/green cycle and commit separately.

**Interfaces:**
- Consumes: verified `v0.2.13`-compatible source and release ZIP/checksum.
- Produces: reviewed GitHub release and, only after safeguards, the Mosaic live upgrade.

- [ ] **Step 1: Run two-stage code review against the approved specification**

Review first for requirement coverage, then for code quality/security. Confirm
with searches that runtime code has no page-global operational token, persisted
`submission_id`, `instanceof CFDB7Source` worker dependency or first-`_ga_`
cookie selection. Resolve every high/medium defect with a failing regression
test and rerun its adjacent suite.

- [ ] **Step 2: Verify branch cleanliness and commit scope**

Run `git status --short`, `git diff main...HEAD --check`, `git log --oneline
main..HEAD`, and a distributable secret/site-identifier scan. Expected: clean
worktree, only planned plugin/test/documentation changes, no credentials,
personal data, generated ZIPs or unrelated files.

- [ ] **Step 3: Publish the reviewed release**

Merge through the repository's normal reviewed branch flow, tag the resulting
main commit `v0.2.13`, push the tag, and verify GitHub Actions publishes exactly
`cf7-ai-enquiry-validator-0.2.13.zip` plus its `.sha256`. Download both, verify
the checksum and compare the archive entry list to the locally verified build.

- [ ] **Step 4: Back up the exact Mosaic production scope**

Before upgrade, use serial remote calls. Confirm document root
`/home/sites/25b/3/3f7a206908/public_html`, record current plugin version, create
a database backup outside `public_html`, and archive only the existing
`wp-content/plugins/cf7-ai-enquiry-validator` directory outside the public
plugin path. Stop if any command has missing output or an unexpected resolved
path.

- [ ] **Step 5: Upgrade from the verified artifact and hash-check it**

Install the downloaded release ZIP through WP-CLI, activate it, retrieve the
deployed main file and changed runtime files, and compare SHA-256 values with
the release archive. Do not clean, reset, commit or depend on the server-side
Git checkout.

- [ ] **Step 6: Run production-safe acceptance checks**

Run WP-CLI health/status, PHP lint on changed plugin PHP, one public desktop and
mobile form-render check, hidden-field uniqueness, no-consent token isolation,
admin settings load, queue visibility and recent plugin error-log checks. Do
not send additional real AI, GA4 or Ads events unless the user separately asks
for a new production conversion send; use dry-run/self-test paths for payload
shape and configuration checks.

- [ ] **Step 7: Record deployment outcome and recovery path**

Record deployed version, release SHA-256, backup locations, acceptance results
and whether rollback was needed in the live-project verification log without
credentials or submission data. Keep the exact pre-upgrade plugin archive and
database backup until the user confirms the observation period is complete.
