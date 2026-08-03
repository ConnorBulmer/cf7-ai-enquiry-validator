# Microsoft UET and Conversion API Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Release a portable `0.2.14` plugin that sends Microsoft UET Conversion API events using the documented batch envelope, safely classifies provider responses, exposes a guarded zero-value transport test, and proves the result on Mosaic Media Training.

**Architecture:** Keep Microsoft wire-contract logic inside the destination boundary and add a Microsoft-specific response classifier so generic destinations retain their current semantics. Add an injectable test service analogous to the GA4 service, then expose it through both a nonced administrator form and WP-CLI. Configure Mosaic's UET tag and conversion goal only after the source release passes repeatable tests; keep every site identifier and credential outside the repository.

**Tech Stack:** WordPress 6.5+, PHP 8.1/8.3, Contact Form 7, Microsoft UET Conversion API, Microsoft Advertising Campaign Management API v13, WP-CLI, PowerShell, Node.js, Git.

## Global Constraints

- WordPress `6.5` or later and PHP `8.1` or later remain the supported floor.
- Plugin release identity becomes `0.2.14`; `Migration::VERSION` remains `10` and no database migration is permitted.
- Microsoft request bodies use one event inside top-level `data` and set `continueOnValidationError` to `false`.
- Normal event name, value, and currency come from the published mapping; Mosaic uses `qualified_training_enquiry`, `100`, and `GBP`.
- Stable `eventId` and `customData.transactionId` are identical and remain unchanged across retries.
- Existing consent, ads eligibility, click-ID/enhanced-matching, dry-run, retry, and idempotency gates remain in force.
- Enhanced matching remains off by default; this change adds no IP address, browser user agent, raw email, raw telephone, form content, or other identity capture.
- Provider response bodies, credentials, tag IDs, account IDs, click IDs, identity hashes, and form data are never persisted or logged.
- Automated tests make no external AI or advertising request.
- Mosaic reuses its existing active GTM-installed UET tag and active campaigns; no inline duplicate UET snippet or new campaign is created.
- The existing broad Microsoft `submit_lead_form` goal remains unchanged.
- The new Microsoft goal remains excluded from bidding during initial validation.
- Remote StackCP calls are serial; exact paths/IDs are inventoried and affected files/data are backed up before mutation.

## File and responsibility map

- Create `src/Destinations/MicrosoftUetResponseClassifier.php`: provider-specific 2xx batch rejection detection, HTTP retry classification, stable receipt identity, and bounded safe error summaries.
- Modify `src/Destinations/MicrosoftUetDestination.php`: documented `data` envelope, `continueOnValidationError`, stable transaction identity, and Microsoft-specific response classification.
- Create `src/Integration/MicrosoftUetTestService.php`: injectable, zero-value, identifier-free, consent-denied live transport test with exact confirmation phrase.
- Modify `src/Admin/AdminHooks.php`: secured Microsoft test POST action and sanitized redirect state.
- Modify `src/Admin/IntegrationsPage.php`: explicit confirmation form and bounded result notice.
- Modify `src/Cli/Commands.php`: `wp cf7aiv microsoft_test --confirm=SEND_ZERO_VALUE_MICROSOFT_TEST` command.
- Modify `tests/DeliveryTest.php`: Microsoft request-envelope, consent, enhanced-matching, response, retry, redaction, and dry-run regressions.
- Create `tests/MicrosoftUetTestServiceTest.php`: controlled transport-service safety and failure coverage.
- Modify `tests/AdminTest.php`: capability/nonce hook and guarded UI surface coverage.
- Modify `README.md`, `docs/SETUP.md`, `readme.txt`, `CHANGELOG.md`, and `VERIFICATION.md`: portable setup, operator safety, release identity, and fresh evidence.
- Modify `cf7-ai-enquiry-validator.php`: plugin header and runtime version `0.2.14`.
- Produce `dist/cf7-ai-enquiry-validator-0.2.14.zip` and matching checksum through the existing release builder.
- Do not create a repository file for Mosaic account IDs, UET tag ID/token, conversion-goal ID, OAuth tokens, or report-builder credentials.

---

### Task 1: Microsoft request and response contract

**Files:**
- Modify: `tests/DeliveryTest.php`
- Create: `src/Destinations/MicrosoftUetResponseClassifier.php`
- Modify: `src/Destinations/MicrosoftUetDestination.php`

**Interfaces:**
- Consumes: `MicrosoftUetDestination::send(ConversionEvent $event): DeliveryResult` and `HttpResponse`.
- Produces: `MicrosoftUetResponseClassifier::classify(HttpResponse $response, string $eventId): DeliveryResult`.
- Produces: `MicrosoftUetResponseClassifier::transportFailure(Throwable $error): DeliveryResult`.

- [ ] **Step 1: Write failing Microsoft envelope and identity assertions**

Change the existing successful Microsoft request fixture in `tests/DeliveryTest.php` to decode the body and assert the new contract:

```php
$msBody = json_decode($msCall['arguments']['body'], true, 32, JSON_THROW_ON_ERROR);
$msEvent = $msBody['data'][0] ?? [];
cf7aiv_delivery_assert(
	count($msBody['data'] ?? []) === 1
	&& ($msBody['continueOnValidationError'] ?? null) === false
	&& ($msEvent['eventId'] ?? '') === $event->eventId
	&& ($msEvent['customData']['transactionId'] ?? '') === $event->eventId,
	'Microsoft CAPI must wrap one event and use one stable identity for event and transaction deduplication.',
);
```

Also assert that mapped name/value/currency, integer event time, `msclkid`, consent code, and Bearer header retain their existing expected values.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```powershell
php tests/DeliveryTest.php
```

Expected: FAIL because the current body has no top-level `data` and no `transactionId`.

- [ ] **Step 3: Write failing privacy and optional-enhanced-matching assertions**

Add assertions for two real `MicrosoftUetDestination` requests:

```php
cf7aiv_delivery_assert(
	! str_contains($defaultJson, 'email_sha256')
	&& ! str_contains($defaultJson, 'phone_sha256')
	&& ! isset($defaultEvent['userData']['em'], $defaultEvent['userData']['ph']),
	'Microsoft enhanced matching must remain absent by default.',
);
cf7aiv_delivery_assert(
	($enhancedEvent['userData']['em'] ?? '') === str_repeat('a', 64)
	&& ($enhancedEvent['userData']['ph'] ?? '') === str_repeat('b', 64),
	'Explicit enhanced matching may send only pre-hashed identity under granted ad_user_data consent.',
);
```

Assert that neither payload contains raw email, raw telephone, IP, user agent, form message, token, or tag ID as a JSON field.

- [ ] **Step 4: Write failing response-classification assertions**

Add table-driven cases for:

```php
$cases = [
	[200, '', true, false],
	[202, '{}', true, false],
	[400, '{"errors":[{"code":"InvalidEvent","field":"data[0].eventName","message":"token=secret raw@example.test"}]}', false, false],
	[401, '{"message":"Bearer hidden-secret"}', false, false],
	[408, '', false, true],
	[425, '', false, true],
	[429, '', false, true],
	[500, '', false, true],
];
```

Assert that 2xx bodies containing `errors`, `partialErrors`, or `operationErrors` fail safely; malformed JSON on 2xx does not crash; every successful result uses the supplied local event ID as `remoteId`; and no error contains the fixture secret, email, response body, click ID, hash, or tag ID.

- [ ] **Step 5: Run the focused test and verify the new classifier assertions are RED**

Run `php tests/DeliveryTest.php`.

Expected: FAIL because the generic classifier accepts every 2xx response and the destination assumes only top-level `eventId` receipts.

- [ ] **Step 6: Implement the Microsoft-specific classifier**

Create `MicrosoftUetResponseClassifier` with this public shape:

```php
final class MicrosoftUetResponseClassifier {
	public static function classify(HttpResponse $response, string $eventId): DeliveryResult;
	public static function transportFailure(Throwable $error): DeliveryResult;
}
```

Implementation rules:

- classify 408/425/429/5xx as retryable and other non-2xx as permanent;
- parse JSON only when non-empty and never propagate JSON exceptions;
- recursively detect case-insensitive `errors`, `partialErrors`, and `operationErrors` collections;
- summarize at most one safe provider code and field using `/[^A-Za-z0-9_.\[\]-]/` removal and maximum lengths of 64 and 128;
- never include provider message text or raw body in a `DeliveryResult`;
- return `new DeliveryResult(true, false, substr($eventId, 0, 191))` for accepted 2xx responses;
- delegate transport failures to `ResponseClassifier::transportFailure()`.

- [ ] **Step 7: Implement the documented destination envelope**

Change the body construction to:

```php
$providerEvent = [
	'eventType' => 'custom',
	'eventId' => $event->eventId,
	'eventName' => $event->eventName,
	'eventTime' => (int) strtotime($event->occurredAt),
	'userData' => $userData,
	'customData' => [
		'value' => $event->value,
		'currency' => $event->currency,
		'transactionId' => $event->eventId,
	],
	'adStorageConsent' => ($event->consent['ad_storage'] ?? 'unknown') === 'granted' ? 'G' : 'D',
];
$body = ['data' => [$providerEvent], 'continueOnValidationError' => false];
```

Replace generic result classification and top-level `eventId` parsing with `MicrosoftUetResponseClassifier::classify($response, $event->eventId)`.

- [ ] **Step 8: Run focused tests and verify GREEN**

Run:

```powershell
php tests/DeliveryTest.php
php tests/DeliveryReviewRegressionTest.php
```

Expected: both print their `PASS` markers and exit 0.

- [ ] **Step 9: Commit the contract change**

```powershell
git add -- tests/DeliveryTest.php src/Destinations/MicrosoftUetResponseClassifier.php src/Destinations/MicrosoftUetDestination.php
git commit -m "fix: conform Microsoft UET CAPI delivery"
```

---

### Task 2: Guarded Microsoft transport-test service

**Files:**
- Create: `tests/MicrosoftUetTestServiceTest.php`
- Create: `src/Integration/MicrosoftUetTestService.php`

**Interfaces:**
- Consumes: `Settings::withDefaults()`, `Crypto::secret('microsoft_uet_token')`, `MicrosoftUetDestination`, and injected clock/event-ID resolvers.
- Produces: `MicrosoftUetTestService::transport(string $confirmation): array<string,mixed>`.
- Produces constants `EVENT_NAME = 'cf7aiv_transport_test'` and `CONFIRMATION = 'SEND_ZERO_VALUE_MICROSOFT_TEST'`.

- [ ] **Step 1: Create the standalone failing service test**

Model the GA4 test-service harness but supply one Microsoft response per invocation. Assert that a wrong confirmation throws before any request:

```php
try {
	$service->transport('yes');
} catch (InvalidArgumentException $error) {
	$refused = str_contains($error->getMessage(), MicrosoftUetTestService::CONFIRMATION);
}
cf7aiv_microsoft_test_assert($refused && $transport->requests === [], 'Exact confirmation must be required before transport.');
```

For an accepted response, assert exactly one request and this body shape:

```php
$event = $body['data'][0] ?? [];
cf7aiv_microsoft_test_assert(
	$event['eventName'] === 'cf7aiv_transport_test'
	&& $event['customData']['value'] === 0.0
	&& $event['customData']['currency'] === 'GBP'
	&& $event['customData']['transactionId'] === $event['eventId']
	&& $event['adStorageConsent'] === 'D'
	&& ($event['userData'] ?? []) === [],
	'The controlled Microsoft event must be zero-value, synthetic, consent-denied, and identifier-free.',
);
```

Assert the receipt contains mode, safety profile, event name/ID, successful, retryable, error, and `live_request_sent`, but not the token or tag ID. Assert the injected settings array is unchanged.

Add failure cases for missing configuration, empty generated event ID, 400, 401, 429, 500, malformed 2xx, and thrown timeout. No case may disclose fixture secrets or identifiers.

- [ ] **Step 2: Run the new test and verify RED**

Run `php tests/MicrosoftUetTestServiceTest.php`.

Expected: fatal class-not-found for `MicrosoftUetTestService`.

- [ ] **Step 3: Implement the minimal injectable service**

Create a final class with constructor defaults matching `GA4TestService`:

```php
public function __construct(
	private readonly HttpTransportInterface $transport = new WordPressHttpTransport(),
	?callable $settingsResolver = null,
	?callable $secretResolver = null,
	?callable $clock = null,
	?callable $eventIdGenerator = null,
)
```

`configuration()` reads only `destinations.microsoft_uet.tag_id` and encrypted `microsoft_uet_token`. `event()` creates a `ConversionEvent` with zero GBP, empty attribution and user data, all consent fields denied, and a synthetic stable checksum. `transport()` validates the exact phrase, sends once with `MicrosoftUetDestination(..., enhancedMatching: false, dryRun: false)`, and returns the sanitized receipt without changing options.

- [ ] **Step 4: Run the service test and verify GREEN**

Run `php tests/MicrosoftUetTestServiceTest.php`.

Expected: `PASS MicrosoftUetTestServiceTest` and exit 0.

- [ ] **Step 5: Commit the service**

```powershell
git add -- tests/MicrosoftUetTestServiceTest.php src/Integration/MicrosoftUetTestService.php
git commit -m "feat: add guarded Microsoft transport test"
```

---

### Task 3: Secure administrator and WP-CLI surfaces

**Files:**
- Modify: `tests/AdminTest.php`
- Modify: `src/Admin/AdminHooks.php`
- Modify: `src/Admin/IntegrationsPage.php`
- Modify: `src/Cli/Commands.php`

**Interfaces:**
- Consumes: `MicrosoftUetTestService::transport()`.
- Produces: `admin_post_cf7aiv_test_microsoft` secured action.
- Produces: `Commands::microsoft_test(array $args, array $assocArgs): void`.

- [ ] **Step 1: Write failing administrator UI and hook assertions**

Extend `tests/AdminTest.php` to require:

```php
foreach (['cf7aiv_test_microsoft', 'SEND_ZERO_VALUE_MICROSOFT_TEST', 'Microsoft UET transport test'] as $control) {
	admin_assert(str_contains($integrations, $control), "Integrations must render guarded Microsoft control {$control}.");
}
admin_assert(isset($GLOBALS['admin_actions']['admin_post_cf7aiv_test_microsoft']), 'The secured Microsoft test action must be registered.');
```

Assert the form includes `wp_nonce_field('cf7aiv_test_microsoft')`, a text confirmation input named `confirmation`, and no hidden pre-filled confirmation phrase.

- [ ] **Step 2: Run the administrator test and verify RED**

Run `php tests/AdminTest.php`.

Expected: FAIL because no Microsoft test hook/form exists.

- [ ] **Step 3: Register and implement the administrator action**

Import `MicrosoftUetTestService`, register `admin_post_cf7aiv_test_microsoft`, and implement:

```php
public static function testMicrosoft(): void {
	$post = self::post();
	AdminRequestGuard::authorize((string) ($post['_wpnonce'] ?? ''), 'cf7aiv_test_microsoft');
	$status = 'failed';
	try {
		$receipt = (new MicrosoftUetTestService())->transport((string) ($post['confirmation'] ?? ''));
		$status = ! empty($receipt['successful']) ? 'success' : (! empty($receipt['retryable']) ? 'retryable' : 'failed');
	} catch (InvalidArgumentException) {
		$status = 'refused';
	}
	self::redirect('admin.php?page=cf7aiv-integrations&microsoft_test=' . $status);
}
```

The route relies on `AdminRequestGuard` for `manage_options` and nonce enforcement. The redirect exposes only one of four fixed status words.

- [ ] **Step 4: Add the explicit Microsoft test form and notice**

Render a separate fieldset after the Microsoft destination settings. The form uses action `cf7aiv_test_microsoft`, its own nonce, a blank `confirmation` input with `autocomplete="off"`, and explanatory copy that it sends one zero-value, consent-denied, identifier-free event without changing live settings. Render only a fixed escaped notice for `success`, `retryable`, `failed`, or `refused` query values.

- [ ] **Step 5: Add the WP-CLI command**

Import `MicrosoftUetTestService` and add:

```php
public function microsoft_test(array $args, array $assocArgs): void {
	try {
		$result = (new MicrosoftUetTestService())->transport((string) ($assocArgs['confirm'] ?? ''));
	} catch (InvalidArgumentException $error) {
		\WP_CLI::error($error->getMessage());
		return;
	}
	$this->json($result);
	if (empty($result['successful'])) {
		\WP_CLI::error('Microsoft UET controlled transport test failed.');
	}
	\WP_CLI::success('One dedicated zero-value Microsoft UET transport test was sent.');
}
```

- [ ] **Step 6: Run focused admin, CLI registration, and service tests**

Run:

```powershell
php tests/AdminTest.php
php tests/MicrosoftUetTestServiceTest.php
php tests/FoundationTest.php
```

Expected: all print `PASS` and exit 0.

- [ ] **Step 7: Commit the secured surfaces**

```powershell
git add -- tests/AdminTest.php src/Admin/AdminHooks.php src/Admin/IntegrationsPage.php src/Cli/Commands.php
git commit -m "feat: expose safe Microsoft transport test"
```

---

### Task 4: Release identity, documentation, and repeatable verification

**Files:**
- Modify: `cf7-ai-enquiry-validator.php`
- Modify: `readme.txt`
- Modify: `README.md`
- Modify: `docs/SETUP.md`
- Modify: `CHANGELOG.md`
- Modify: `VERIFICATION.md`

**Interfaces:**
- Produces: plugin/runtime/stable-tag version `0.2.14` and release tag contract `v0.2.14`.
- Produces: operator instructions for Microsoft goal matching and controlled test usage.

- [ ] **Step 1: Write the release-package identity failure**

Before changing version files, run:

```powershell
php tools/build-release.php --tag=v0.2.14 --output-dir=dist
```

Expected: FAIL because source identity is still `0.2.13`.

- [ ] **Step 2: Update release identity and changelog**

Set both plugin header and `CF7AIV\VERSION` to `0.2.14`, set `Stable tag: 0.2.14`, update the README current-release identity, and prepend a `0.2.14 — 2026-08-03` changelog entry covering the CAPI envelope, stable transaction identity, safe response handling, and guarded transport test. State explicitly that schema remains 10.

- [ ] **Step 3: Document portable Microsoft setup**

Add exact operator guidance to `README.md` and `docs/SETUP.md`:

```text
1. Install one browser UET tag through the site's consent-aware tag manager.
2. Enter that tag ID and its UET Conversion API authentication key in write-only plugin settings.
3. Keep Microsoft dry-run enabled while publishing a Microsoft mapping.
4. Create an EventGoal whose action exactly matches the mapping event name.
5. Run wp cf7aiv microsoft_test --confirm=SEND_ZERO_VALUE_MICROSOFT_TEST.
6. Confirm the diagnostic event is accepted but does not match the business goal.
7. Disable dry-run only after consent, eligibility, retry, and duplicate tests pass.
```

Document that normal Microsoft delivery needs a consent-permitted `msclkid` unless explicitly consented enhanced matching is enabled, and that sites must not install a duplicate UET tag.

- [ ] **Step 4: Run the complete standalone suite**

Run:

```powershell
Get-ChildItem -LiteralPath tests -Filter '*Test.php' | Sort-Object Name | ForEach-Object {
	php $_.FullName
	if ($LASTEXITCODE -ne 0) { throw "Failed: $($_.Name)" }
}
node tests/AttributionJsTest.js
```

Expected: every PHP test and the JavaScript test prints `PASS` and exits 0.

- [ ] **Step 5: Lint all distributable PHP**

Run:

```powershell
$files = Get-ChildItem -Recurse -File -Filter '*.php' | Where-Object { $_.FullName -notmatch '[\\/]dist[\\/]' }
$failed = @()
foreach ($file in $files) {
	php -l $file.FullName | Out-Host
	if ($LASTEXITCODE -ne 0) { $failed += $file.FullName }
}
if ($failed.Count -gt 0) { throw "PHP lint failed: $($failed -join ', ')" }
```

Expected: no syntax errors and no failed files.

- [ ] **Step 6: Build and inspect the release**

Run:

```powershell
php tools/build-release.php --tag=v0.2.14 --output-dir=dist
```

Expected: exit 0 with one canonical plugin root and generated ZIP/checksum. Reopen the ZIP using the existing release verifier, assert no tests or unsafe paths are included, and scan the source diff/archive for known credential patterns and Mosaic account/tag/property IDs. Expected match count: zero.

- [ ] **Step 7: Record fresh evidence and commit the release**

Update the top of `VERIFICATION.md` with exact command counts, version/schema identities, release filename, SHA-256, and test outcomes. Do not include secrets, account IDs, tag IDs, click IDs, private submissions, or raw provider responses.

```powershell
git add -- cf7-ai-enquiry-validator.php readme.txt README.md docs/SETUP.md CHANGELOG.md VERIFICATION.md
git commit -m "release: prepare Microsoft UET hardening 0.2.14"
```

---

### Task 5: Mosaic Microsoft account configuration

**Files:**
- No repository files containing credentials or identifiers.
- Read-only credential source: existing Report Builder local configuration and refreshed token store.
- Microsoft API operations: Campaign Management v13.

**Interfaces:**
- Consumes: Mosaic account ID, actual parent customer ID, developer token, OAuth access token, and existing active UET tag ID from local private configuration.
- Produces: one UET tag authentication key stored encrypted in WordPress and one account-scoped EventGoal.

- [ ] **Step 1: Re-inventory exact Microsoft objects**

Use direct API calls with the actual parent customer ID to read the account, active UET tag, existing conversion goals, and Search campaigns. Stop if more than one plausible Mosaic account/tag exists or if the expected active UET tag is missing.

- [ ] **Step 2: Retrieve the UET authentication key**

Call Campaign Management `GetUetTagAuthKey` for the exact active tag. Capture the key only in process memory and pass it directly to the encrypted WordPress write-only path. Never print it or write it to the repository, command history, evidence, or an intermediate plaintext file.

- [ ] **Step 3: Create the dedicated EventGoal only if absent**

Read goals again and use idempotent create-or-verify logic keyed by the exact goal name/action. If absent, call `AddConversionGoals` with:

```text
Name: AI Qualified Training Enquiry
Type: Event
Action: qualified_training_enquiry
Scope: Account
Status: Active
CountType: Unique
RevenueType: VariableValue
DefaultValue: 100
CurrencyCode: GBP
ExcludeFromBidding: true
```

Do not alter the existing `submit_lead_form` goal. Read the new goal back and compare every field before proceeding.

- [ ] **Step 4: Verify browser UET and consent without mutation**

On the public site, verify that GTM loads exactly one expected UET tag. Test fresh sessions with advertising consent denied and granted; inspect browser requests/state to confirm the tag follows the site's consent policy. Check desktop, tablet, and mobile widths. Do not add `functions.php` code unless the existing tag is proven absent/broken and duplication has first been ruled out.

---

### Task 6: Controlled live deployment and end-to-end verification

**Files:**
- Local artifact: `dist/cf7-ai-enquiry-validator-0.2.14.zip` and checksum.
- Live exact plugin directory under `/home/sites/25b/3/3f7a206908/public_html/wp-content/plugins/`.
- Backups: timestamped plugin archive and database export outside `public_html`.

**Interfaces:**
- Consumes: verified release artifact, encrypted UET token, tag ID, existing v2 policy, and exact qualified tier/mapping keys.
- Produces: active live `0.2.14`, published/enabled Microsoft mapping, accepted controlled transport event, and sanitized delivery evidence.

- [ ] **Step 1: Inventory and back up live state**

Using serial `wp-mosaic-media-training`/StackCP calls, confirm document root, active plugin version/path, PHP 8.3, schema 10, active policy, form/source IDs, Microsoft settings, mappings, queue state, and current error log timestamp. Create an exact plugin backup and affected database backup outside `public_html`; retrieve/hash-check the backups and stop on missing output.

- [ ] **Step 2: Upload and verify the release artifact**

Upload the ZIP to a unique temporary path, compare local and remote SHA-256, extract to a unique temporary directory, verify one canonical plugin root, and replace only the exact plugin directory with a recoverable rename sequence. Do not use server-side Git.

- [ ] **Step 3: Verify activation and code health**

Run WP-CLI status/plugin checks, PHP lint for changed files, schema/version checks, and public form smoke tests. Confirm no new PHP error since the pre-deployment timestamp.

- [ ] **Step 4: Configure Microsoft while retaining dry-run**

Write the exact UET tag ID and authentication key through an encrypted write-only WordPress path that does not echo values. Set Microsoft enabled with dry-run true. Create/publish/enable the exact mapping for the qualified tier using event `qualified_training_enquiry`, value `100`, and currency `GBP`. Read back only non-secret state.

- [ ] **Step 5: Exercise negative and dry-run paths**

Using synthetic, non-personal test submissions/fixtures, verify unqualified, spam, ambiguous, non-ads-eligible, denied-consent, missing-identifier, and duplicate paths emit no Microsoft request. Verify a qualified dry-run records the redacted envelope but makes no network call.

- [ ] **Step 6: Run the guarded Microsoft transport test**

Execute:

```text
wp cf7aiv microsoft_test --confirm=SEND_ZERO_VALUE_MICROSOFT_TEST
```

Verify one 2xx provider acceptance, stable synthetic event/transaction identity, zero GBP, denied ad-storage consent, no click/identity data, and a sanitized receipt. Confirm Microsoft diagnostics do not count it against `AI Qualified Training Enquiry`.

- [ ] **Step 7: Enable normal Microsoft delivery and test the controlled pipeline**

Disable only the Microsoft dry-run flag after the transport test passes. Run one controlled qualified pipeline fixture through capture/classification/delivery with synthetic non-personal content and a syntactically valid test `msclkid`; verify one Microsoft delivery record and idempotent duplicate suppression. Treat provider acceptance as transport proof, not paid-click attribution proof.

- [ ] **Step 8: Verify site rendering and ongoing state**

Recheck desktop/tablet/mobile form operation, GTM/UET loading, denied/granted consent, Action Scheduler, plugin health, delivery records, and PHP logs. Confirm GA4 and Google Ads delivery state remains unchanged.

- [ ] **Step 9: Record the attribution boundary**

If no genuine ad visit occurs during the test window, record that final campaign attribution remains pending natural traffic with a real `msclkid`. Do not self-click an ad or create a campaign. When natural traffic arrives, verify that the same qualified event appears in delivery evidence and Microsoft goal diagnostics before considering bidding inclusion.

- [ ] **Step 10: Roll back on any failed gate**

For unsafe behaviour, enable Microsoft dry-run or disable only Microsoft, restore the exact prior plugin backup if necessary, and pause the new goal. Leave GA4, Google Ads, GTM, campaigns, and the existing broad Microsoft goal unchanged.
