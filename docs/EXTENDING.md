# Extending CF7 AI Enquiry Validator

Extensions should load after the plugin, implement the public contract, and register an instance through the matching filter. Never return raw identity from a provider result or log request bodies/secrets.

## Submission sources

Implement `CF7AIV\Contracts\SubmissionSourceInterface` with `key()`, `get(string $reference)`, and `iterate(array $arguments)`, then register it:

```php
add_filter('cf7aiv_submission_sources', function (array $sources): array {
    $sources['my_source'] = new MySubmissionSource();
    return $sources;
});
```

`get()` returns the canonical `source`, `reference`, `form_id`, `submitted_at`, `fields`, `is_historical`, and `is_calibration` keys. Historical iteration must mark both flags true.

Sources that can resolve a generic CF7 capture after their own persistent row
becomes visible may also implement
`CF7AIV\Contracts\SubmissionReferenceLocatorInterface`:

```php
public function findReferenceBySubmissionId(
    string $submissionId,
    int $formId,
    string $capturedAt
): ?string;
```

The same registered object must implement `SubmissionSourceInterface`. It must
return its stable opaque source reference only after proving the complete
submission token belongs to the supplied form and capture time, or `null` while
the record is not visible. Registration order is deterministic: the first
registered locator that proves a reference owns the link. Never return raw
submission content from the locator or use partial token matching.

## AI providers

Implement `CF7AIV\Contracts\AIProviderInterface::classify(ClassificationRequest): ClassificationResult` and register with `cf7aiv_ai_providers`. Providers must use the already-redacted request, validate the fixed result contract, classify 429/timeouts/5xx as transient, and never log the body or API key.

Providers may additionally implement `CF7AIV\Contracts\VerificationCapableProviderInterface` to support the optional independent verification pass, and `CF7AIV\Contracts\PolicySuggestionProviderInterface` to produce reviewable setup suggestions. These interfaces are optional capabilities; extensions must not infer that an ordinary classification provider supports them.

Policy v2 uses the universal outcome codes `primary_opportunity`, `secondary_opportunity`, `legitimate_non_conversion`, `excluded`, `spam`, and `insufficient_information`. Deterministic evidence evaluation produces `strong_match`, `needs_review`, or `not_eligible`. Model confidence remains an AI diagnostic and is never the auto-dispatch gate. Version 1 policies cannot auto-dispatch because they do not provide grounded evidence; only a live-origin v2 `strong_match` may enter the automatic path, with `verification_state=passed` additionally required when automatic-candidate verification is configured.

## Destinations

Implement `CF7AIV\Contracts\DestinationInterface::send(ConversionEvent): DeliveryResult` and register with `cf7aiv_destinations`. Respect dry-run, return a retryable result only for transient errors, and use the event idempotency key at the remote boundary where supported.

Built-in destination keys are `ga4`, `server_gtm`, `microsoft_uet`, and `webhook`. `server_gtm` uses GA4 Measurement Protocol shape but replaces Google's collection hostname with the configured existing tagging-server base URL. Its event parameters expose the stable event ID as both `event_id` and `transaction_id`, available Google click identifiers, non-identifying form/source context, value/currency, client/session context, and actual consent. Extensions must preserve these privacy and deduplication boundaries.

The delivery worker rechecks eligibility independently. Extensions must not treat an earlier queue decision as authorization. Shadow, calibration, and historical records are never deliverable. The only non-automatic path is an explicit administrator `approve_dispatch` review that locks the item, compares the exact monotonic `review_revision` rendered in the administrator's single or per-item bulk form, advances that revision, and atomically writes reviewer truth plus one immutable approval ID to both the item and its accepted audit record. Missing or malformed revisions are rejected. A concurrent reject, approve-without-send, correction, classifier result/failure, or other review-state mutation advances the durable revision and makes the stale form fail even when tier and outcome are unchanged. Classifier writes preserve existing administrator truth. Manual approval remains subject to origin, consent, configuration, signal, pause, claim, idempotency, and retry gates.

Calibration scheduling persists an immutable run in `scheduling` state before it queues a stratum. The run becomes `active` only after every stratum is accepted. Any queue or activation failure durably changes it to `cancelled` before best-effort unscheduling. Local item, result, and failure writes use `CalibrationRunStoreInterface::whileActive()`: the implementation starts a transaction, locks the run row, requires `active`, performs the short local mutation, and commits. Cancellation locks the same row before changing state, so cancellation and worker persistence have one linear order rather than a check/write race. Source preparation and remote AI calls remain outside that database lock; final persistence, retry, hook, and enqueue authorization are guarded afterward. Extensions must preserve `calibration_run_id` and must not mutate missing, cancelled, or superseded runs. An action that observes `scheduling` is deferred with a bounded activation wait rather than consuming its stratum.

## Consent resolvers

Implement `CF7AIV\Contracts\ConsentResolverInterface::resolve(ClientSignals): ConsentSnapshot` and register with `cf7aiv_consent_resolvers`. Conflicts must resolve to the most restrictive state; extensions must never synthesize a grant.

## Lifecycle actions

```php
add_action('cf7aiv_after_capture', function (int $itemId): void {});
add_action('cf7aiv_after_classification', function (int $itemId, $result, $decision): void {} , 10, 3);
add_action('cf7aiv_after_review', function (int $itemId, string $action, int $administratorId, $decision): void {}, 10, 4);
add_action('cf7aiv_after_delivery', function (int $deliveryId, int $itemId, string $destination, $result): void {}, 10, 4);
add_action('cf7aiv_after_setup_suggestions', function (int $count, string $packKey, int $administratorId): void {}, 10, 3);
add_action('cf7aiv_after_calibration_scheduled', function (string $runId, string $sourceKey, int $scheduled, int $administratorId, array $criteria): void {}, 10, 5);
```

The setup-suggestions action fires only after an atomic suggestion batch is stored. The calibration action fires only after every stratum in the explicitly opted-in run has been scheduled and the durable run has transitioned to `active`; cancelled and partially queued runs never fire it. These are additive to the existing capture, classification, review, and delivery actions. Lifecycle listeners should be idempotent and must not make CF7 submission callbacks depend on external services.
