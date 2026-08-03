# Microsoft UET and Conversion API Hardening Design

Date: 2026-08-03
Status: Approved design, awaiting implementation-plan checkpoint

## Objective

Make the plugin's Microsoft Ads destination conform to Microsoft's current UET Conversion API contract, add a safe end-to-end transport test, and prove the integration on Mosaic Media Training without making the plugin site-specific.

The finished work must:

- keep the plugin installable and configurable on unrelated WordPress sites;
- send only AI-qualified, ads-eligible enquiries to the Microsoft destination;
- preserve the existing consent and idempotency controls;
- reuse Mosaic's active UET/GTM installation and existing campaigns;
- create a separate, initially non-bidding conversion goal for qualified enquiries;
- avoid secrets, account identifiers, and personal data in source control, logs, screenshots, or test fixtures.

## Evidence and problem statement

The plugin's current Microsoft destination sends one event object directly to:

`https://capi.uet.microsoft.com/v1/{tag-id}/events`

Microsoft's current request contract requires a top-level `data` array, even for a single event. The current implementation therefore does not match the documented wire format. It also assumes a top-level response `eventId`, which is not a dependable provider receipt contract.

Read-only Microsoft Ads inventory established that the Mosaic account already has:

- an active UET tag;
- that UET tag loading through Google Tag Manager on the public website;
- active Search campaigns capable of producing real `msclkid` traffic;
- a broad existing `submit_lead_form` event goal that must remain unchanged.

The Report Builder connection is healthy after OAuth renewal, but its website setup flow has a hierarchy bug: the frontend stores the selected advertising account ID as both account ID and customer ID, while the backend account response discards `ParentCustomerId`. Direct Microsoft Ads API calls using the actual parent customer ID return the Mosaic campaigns correctly. The earlier zero-campaign result was also affected by an incorrect local assumption about the SDK response shape and by requesting combined campaign-type flags where the REST operation expects supported values individually.

The Report Builder defect is recorded as evidence but is outside this plugin change.

## Scope

### Included

- Correct the Microsoft Conversion API request envelope.
- Retain deterministic event identity for retries and deduplication.
- Add `transactionId` to the Microsoft custom data using the same stable plugin event identity.
- Harden Microsoft success and error classification without retaining raw response bodies.
- Add a guarded, zero-value Microsoft transport test for administrators.
- Add unit and regression coverage for the Microsoft request and delivery lifecycle.
- Release the reusable plugin as version `0.2.14`, provided implementation verification passes.
- Configure Mosaic's existing UET tag for server-side events using its UET tag authentication key.
- Create a dedicated Mosaic Microsoft Ads event goal for the qualified-enquiry event.
- Deploy with live backups, dry-run verification, controlled transport verification, and a reversible enablement sequence.
- Verify browser-side UET and consent behaviour on desktop, tablet, and mobile.

### Excluded

- Modifying Report Builder as part of this release.
- Creating a new Microsoft Ads campaign or intentionally clicking a live ad.
- Replacing or duplicating the existing GTM-installed UET tag.
- Changing the existing `Contact Form Submission` / `submit_lead_form` goal.
- Enabling the new qualified goal for bidding during initial validation.
- Adding IP address, browser user agent, or new identity capture to plugin storage or payloads.
- Enabling enhanced matching by default.
- Adding Mosaic account IDs, tag IDs, credentials, or conversion-goal IDs to distributable plugin code.
- Changing the plugin database schema; schema remains version 10.

## Architectural separation

The solution has two deliberately separate layers.

### Reusable plugin layer

The plugin knows only provider-neutral enquiry data and administrator-supplied Microsoft settings. It builds the documented Microsoft payload, applies consent and eligibility gates, classifies delivery outcomes, and records sanitized operational evidence. No Mosaic identifier or assumption belongs in this layer.

### Mosaic account configuration layer

Microsoft Ads account objects, the UET authentication key, conversion-goal settings, and live enablement are Mosaic-specific operational configuration. These values are applied through Microsoft Ads APIs and encrypted WordPress settings, not committed to the plugin repository.

This boundary is what allows the same plugin release to work on any WordPress site with its own UET tag, token, mapping, and consent configuration.

## Microsoft event contract

For a normal qualified enquiry, the destination sends one event in Microsoft's required batch envelope:

```json
{
  "data": [
    {
      "eventType": "custom",
      "eventId": "stable-plugin-event-id",
      "eventName": "qualified_training_enquiry",
      "eventTime": 1700000000,
      "userData": {
        "msclkid": "validated-click-id"
      },
      "customData": {
        "value": 100,
        "currency": "GBP",
        "transactionId": "stable-plugin-event-id"
      },
      "adStorageConsent": "G"
    }
  ],
  "continueOnValidationError": false
}
```

The example values describe shape, not fixture values to reuse in production.

Contract rules:

- `data` is always a list containing exactly one event for the current worker invocation.
- `continueOnValidationError` is `false`; the plugin must not silently accept a partially invalid event.
- `eventId` is the existing deterministic plugin delivery identity and does not change across retries.
- `customData.transactionId` uses that same stable identity to strengthen provider-side deduplication.
- `eventTime` is an integer Unix timestamp derived from the normalized event.
- `eventName`, value, and currency come from the published delivery mapping, not hard-coded site values.
- `msclkid` is included only after existing validation and consent rules pass.
- Consented email or telephone hashes may be included only when the existing enhanced-matching setting is explicitly enabled and `ad_user_data` is granted.
- Raw email, raw telephone, form message, name, company, page contents, IP address, and browser user agent are never added by this change.
- `adStorageConsent` remains `G` for granted and `D` for denied, as determined by the existing consent model.
- Authorization continues to use the UET tag authentication key as a Bearer token in the HTTP header.

## Eligibility and consent

Normal Microsoft delivery continues to require all existing gates:

1. Microsoft destination enabled.
2. Tag ID and encrypted token configured.
3. Published, enabled Microsoft mapping.
4. Enquiry qualified or explicitly approved and marked ads-eligible.
5. Required advertising consent granted when consent-respect mode is active.
6. A valid `msclkid`, or explicitly enabled enhanced matching with granted `ad_user_data` and at least one permitted hash.
7. No prior successful delivery for the same destination and event identity.

No eligibility gate is weakened to make testing easier. The dedicated transport test is a separate administrator-only path and cannot promote or alter a real enquiry.

The existing GTM-installed UET tag must be checked under both denied and granted consent states. A `functions.php` UET snippet is not added because the site already loads the active UET tag through GTM; a second installation could double-count page views and events. A fallback snippet is considered only if later browser evidence proves the existing tag is broken and the duplicate risk is eliminated first.

## Response and error handling

Microsoft responses are interpreted conservatively:

- Any valid 2xx response is treated as transport success unless its documented body contains an explicit per-event rejection.
- HTTP 408, 425, 429, and 5xx outcomes are retryable under the existing backoff and maximum-attempt policy.
- Other 4xx outcomes are permanent configuration or validation failures and are not retried indefinitely.
- Network and timeout failures remain retryable.
- A malformed 2xx body must not crash processing or reveal data. Because Microsoft does not guarantee a top-level `eventId`, the plugin uses its own stable event ID as the delivery receipt when no documented scalar provider identifier exists.
- Provider validation structures such as `errors`, `partialErrors`, or operation-error collections are reduced to bounded, sanitized error codes and safe field paths/messages.
- Raw response bodies, authorization headers, tag IDs, click IDs, hashes, form data, and tokens are never stored in delivery records or WordPress logs.
- Sanitized messages are length-limited and pass through the existing secret-redaction boundary before persistence or display.

## Guarded Microsoft transport test

Microsoft has no equivalent of GA4's debug validation endpoint. The plugin therefore adds a deliberately non-converting transport test using the real Microsoft endpoint.

The test:

- is available only to an authenticated administrator with the plugin's existing settings capability;
- requires a valid nonce;
- requires the exact confirmation phrase `SEND_ZERO_VALUE_MICROSOFT_TEST`;
- requires Microsoft tag ID and encrypted token settings but does not change them;
- sends event name `cf7aiv_transport_test`;
- sends value `0` and currency `GBP`;
- generates a fresh synthetic event ID and uses it as `transactionId`;
- sends no `msclkid`, email hash, telephone hash, IP address, user agent, form field, or other personal data;
- sends `adStorageConsent` as `D`;
- does not use the normal mapping or enquiry lifecycle and cannot create, approve, or mutate a submission;
- does not toggle dry-run, destination enablement, mappings, or consent settings;
- returns only a sanitized pass/fail receipt and status classification.

The Mosaic conversion goal is intentionally configured for `qualified_training_enquiry`, so `cf7aiv_transport_test` must not match or increment it. This proves authentication, endpoint reachability, request shape, and response handling without manufacturing a business conversion.

## Mosaic Microsoft Ads configuration

The live account setup is applied only after the plugin tests pass.

### Existing UET tag

- Reuse the active account UET tag already loaded through GTM.
- Retrieve or generate its Conversion API authentication key with Microsoft's `GetUetTagAuthKey` operation.
- Store the key only in the plugin's encrypted, write-only Microsoft token setting.
- Store the tag ID in normal plugin configuration without putting it in source control or evidence exports.
- Keep the destination in dry-run until the guarded transport test is ready.

### Dedicated conversion goal

Create a new account-scoped event goal with:

- name: `AI Qualified Training Enquiry`;
- event action: `qualified_training_enquiry`;
- status: active;
- count type: unique;
- revenue: variable value, default 100 GBP;
- exclude from bidding: true initially.

The goal matches the same logical event and value already used by Mosaic's GA4/Google Ads integration. It remains excluded from bidding until genuine attributed traffic has been observed and the user intentionally chooses to promote it.

Creating or updating an EventGoal enables Microsoft click-ID auto-tagging according to Microsoft's API documentation. Existing active Search campaigns are reused. Final advertising-attribution proof waits for natural traffic containing a real `msclkid`; no new campaign or self-click is needed.

## End-to-end data flow

```text
Visitor arrives (possibly with msclkid)
  -> attribution script validates and stores permitted click data
  -> visitor submits Contact Form 7 form
  -> plugin captures the submission and consent snapshot
  -> AI policy evaluation classifies the enquiry
  -> only qualified, ads-eligible outcome reaches delivery worker
  -> published Microsoft mapping supplies event/value/currency
  -> worker applies consent, identifier, retry, and idempotency gates
  -> Microsoft destination wraps one custom event in data[]
  -> UET Conversion API accepts or returns a classified error
  -> plugin stores only sanitized operational evidence
  -> Microsoft EventGoal matches qualified_training_enquiry
```

Browser UET remains a parallel Microsoft signal for page/session activity. Stable `eventId` and `transactionId` make retries deterministic and prepare the integration for deduplication where the same logical event is observable through more than one path.

## Test-first implementation strategy

Implementation follows red-green-refactor. Tests are written to fail against the existing destination before production code is changed.

### Contract tests

- Request body contains top-level `data` with exactly one event.
- `continueOnValidationError` is false.
- Stable `eventId` survives retries.
- `transactionId` equals the stable event ID.
- Normal mapped name/value/currency pass through unchanged.
- A valid consented `msclkid` appears only in `userData`.
- Enhanced matching remains absent by default and appears only under its explicit setting plus granted consent.
- Forbidden raw identity, secret, form, IP, and user-agent fields never appear.
- Test fixtures use synthetic values only.

### Response-classification tests

- 200/202 and empty or non-identifier success bodies.
- Explicit batch/event validation rejection in an otherwise successful HTTP response.
- Malformed JSON without fatal errors or raw-body persistence.
- 400 validation failure.
- 401/403 authentication failure with redacted diagnostics.
- 408/425/429 retry behaviour.
- 500/502/503 retry behaviour and retry exhaustion.
- Unexpected provider fields and oversized messages.
- Token, tag ID, click ID, and hash redaction in all observable errors.

### Delivery lifecycle tests

- Qualified enquiry with valid consent and `msclkid` sends once.
- Direct/organic enquiry without permitted enhanced matching does not send to Microsoft.
- Unqualified, spam, ambiguous, or non-ads-eligible outcomes do not send.
- Denied advertising consent does not send a normal conversion.
- Duplicate queue execution does not duplicate a successful event.
- Retry reuses the same event identity.
- Dry-run makes no network request.
- Existing GA4 and Google Ads behaviour remains unchanged.

### Transport-test tests

- Administrator capability, nonce, and exact phrase are all mandatory.
- Payload is zero-value, consent-denied, synthetic, and free of identifiers/PII.
- Test does not alter settings, mappings, enquiries, or production delivery state.
- Success, authentication failure, validation failure, rate limit, timeout, and malformed response are reported safely.

### Full local verification

- Run the focused Microsoft tests first.
- Run the complete PHP test suite.
- Run JavaScript attribution and WordPress portability tests.
- Run PHP syntax checks and project release/package validation.
- Confirm no credential-like material is present in the release archive or Git diff.
- Confirm database schema remains version 10.

## Live rollout and safeguards

Remote operations are serialized because of the StackCP SSH limits.

1. Inventory the exact active plugin path, version, settings, mappings, theme/GTM state, and relevant WordPress IDs.
2. Back up the installed plugin files and affected database tables/options; keep database backups outside `public_html`.
3. Build the verified `0.2.14` release locally and hash the archive.
4. Upload to a temporary server path, verify the remote hash, and replace only the exact plugin directory using a recoverable sequence.
5. Confirm plugin activation, version, schema 10, PHP 8.3 compatibility, and no new PHP errors.
6. Apply the UET tag ID and authentication key through a write-only path; never echo the key in a command, log, screenshot, or repository file.
7. Keep Microsoft dry-run enabled while confirming settings and mapping readiness.
8. Use the direct Microsoft Ads API to create/read back the dedicated conversion goal and verify every field.
9. Run the guarded `cf7aiv_transport_test`; verify API acceptance and sanitized local evidence.
10. Confirm the test event did not match the qualified-enquiry conversion goal.
11. Publish the Microsoft mapping for `qualified_training_enquiry`, 100 GBP, then disable dry-run.
12. Exercise non-converting rejection paths and one controlled qualified pipeline path without using real personal data.
13. Verify public rendering, form operation, UET loading, and consent behaviour on desktop, tablet, and mobile.
14. Monitor delivery records and Microsoft diagnostics. Final click-attribution proof is completed when natural real traffic supplies a valid `msclkid`.

Any missing command output, mismatched ID/path, failed hash, unexpected live state, or unsanitized data stops the rollout.

## Rollback

If plugin behaviour or live delivery is unsafe:

1. Re-enable Microsoft dry-run or disable only the Microsoft destination.
2. Leave GA4 and Google Ads configuration untouched.
3. Restore the exact prior plugin directory from the verified backup if code rollback is required.
4. Restore only the affected plugin options/tables if configuration rollback is required.
5. Pause or disable the new Microsoft conversion goal; do not delete or alter the pre-existing broad goal.
6. Re-run plugin activation, form, consent, and delivery checks.

The existing GTM UET installation and campaigns are not changed by plugin rollback.

## Acceptance criteria

The work is complete only when all of the following are evidenced:

- Microsoft payload matches the documented `data` envelope.
- Stable `eventId` and `transactionId` are present and identical across retries.
- No new default personal-data collection or enhanced matching is introduced.
- Safe response classification covers success, provider validation, authentication, rate limit, transient, and malformed-body cases.
- The guarded zero-value test reaches Microsoft and records only sanitized evidence.
- All focused and full local test suites pass.
- The release package contains no credentials or Mosaic-specific identifiers.
- Mosaic reuses its single active UET tag through GTM without duplicate inline tracking.
- The dedicated account-scoped goal exists with action `qualified_training_enquiry`, unique counting, 100 GBP default variable value, and exclusion from bidding.
- The old `submit_lead_form` goal is unchanged.
- Live plugin version, schema, settings, mappings, and delivery records are verified after deployment.
- Normal denied-consent, unqualified, direct/organic-without-permitted-identifier, duplicate, and dry-run paths do not emit Microsoft conversions.
- A controlled qualified pipeline event is accepted without exposing personal data.
- Desktop, tablet, and mobile form/UET/consent checks pass.
- Natural `msclkid` traffic is identified as the remaining condition if no genuine Microsoft ad visit occurs during the test window.

## Authoritative references

- [Microsoft UET Conversion API integration](https://learn.microsoft.com/en-us/advertising/guides/uet-conversion-api-integration?view=bingads-13)
- [GetUetTagAuthKey](https://learn.microsoft.com/en-us/advertising/campaign-management-service/getuettagauthkey?view=bingads-13)
- [AddConversionGoals](https://learn.microsoft.com/en-us/advertising/campaign-management-service/addconversiongoals?view=bingads-13)
- [EventGoal](https://learn.microsoft.com/en-us/advertising/campaign-management-service/eventgoal?view=bingads-13)
- [UET consent mode](https://learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_conc_uet_consent)
- [Complianz third-party integration guide](https://complianz.io/developers-guide-for-third-party-integrations/)
