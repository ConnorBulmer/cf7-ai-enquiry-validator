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

## Assisted site-profile scanning

The optional setup scanner reads only local, published, password-free content.
Its default post types are `page` and `post`. A public custom post type can opt
in without replacing the defaults:

```php
add_filter('cf7aiv_site_profile_post_types', function (array $postTypes): array {
    $postTypes[] = 'case_study';
    return $postTypes;
});
```

Builders that keep public copy or CF7 references in post metadata can supply
that data without making the scanner fetch a rendered URL:

```php
add_filter(
    'cf7aiv_site_profile_post_content',
    function (string $content, WP_Post $post): string {
        $builderCopy = get_post_meta($post->ID, '_my_builder_public_copy', true);
        return is_string($builderCopy) ? $content . ' ' . $builderCopy : $content;
    },
    10,
    2
);

add_filter(
    'cf7aiv_site_profile_post_form_ids',
    function (array $formIds, WP_Post $post, string $content): array {
        $builderFormId = (int) get_post_meta($post->ID, '_my_builder_cf7_id', true);
        if ($builderFormId > 0) { $formIds[] = $builderFormId; }
        return $formIds;
    },
    10,
    3
);
```

`cf7aiv_site_profile_posts` may augment the local objects returned by
`get_posts()`. Every extension result is rechecked for selected post type,
published status, empty password and a same-host permalink. The scanner then
reapplies its maximum 50 pages, 100,000 input characters and 2,000 output
characters per page, 40,000 total output characters, and 100 raw form-reference
entries inspected per page. Content is bounded before normalization and before it
is passed to the form-reference filter. These filters must return local
WordPress data; they must not load remote URLs, files, submissions, identity
fields or secrets.

## AI providers

Implement `CF7AIV\Contracts\AIProviderInterface::classify(ClassificationRequest): ClassificationResult` and register with `cf7aiv_ai_providers`. Providers must use the already-redacted request, validate the fixed result contract, classify 429/timeouts/5xx as transient, and never log the body or API key.

Providers may additionally implement `CF7AIV\Contracts\VerificationCapableProviderInterface` to support the optional independent verification pass, and `CF7AIV\Contracts\PolicySuggestionProviderInterface` to produce reviewable setup suggestions. These interfaces are optional capabilities; extensions must not infer that an ordinary classification provider supports them.

Policy v2 uses the universal outcome codes `primary_opportunity`, `secondary_opportunity`, `legitimate_non_conversion`, `excluded`, `spam`, and `insufficient_information`. Deterministic evidence evaluation produces `strong_match`, `needs_review`, or `not_eligible`. Model confidence remains an AI diagnostic and is never the auto-dispatch gate. Version 1 policies cannot auto-dispatch because they do not provide grounded evidence; only a live-origin v2 `strong_match` may enter the automatic path, with `verification_state=passed` additionally required when automatic-candidate verification is configured.

## Destinations

Implement `CF7AIV\Contracts\DestinationInterface::send(ConversionEvent): DeliveryResult` and register with `cf7aiv_destinations`. Respect dry-run, return a retryable result only for transient errors, and use the event idempotency key at the remote boundary where supported.

Built-in destination keys are `ga4`, `server_gtm`, `microsoft_uet`, and `webhook`. `server_gtm` uses GA4 Measurement Protocol shape but replaces Google's collection hostname with the configured existing tagging-server base URL. Its event parameters expose the stable event ID as both `event_id` and `transaction_id`, available Google click identifiers, non-identifying form/source context, value/currency, client/session context, and actual consent. Extensions must preserve these privacy and deduplication boundaries.

### Making a registered destination sendable

The delivery worker sends only to a destination it knows is configured, for
an enquiry that carries the identifiers the destination needs, under the
consent it needs. For the built-in keys it reads that from settings. For any
other key, the destination must answer for itself by implementing
`CF7AIV\Contracts\DestinationReadinessInterface` (which extends
`DestinationInterface`):

| Method | Answers | If it throws |
| --- | --- | --- |
| `isConfigured(): bool` | Has an endpoint, credentials and is switched on, so it can send or dry-run | Gated `destination_not_configured` |
| `signalsReady(array $attribution, array $consent): bool` | This enquiry carries the identifiers it attributes with | Gated `signals_not_ready` |
| `requiredConsent(): array` | Consent keys that must be `granted` under the default `respect` mode | Requires `analytics_storage` and `ad_storage` |

A destination that does not implement the interface is gated
`destination_not_configured` on every item, as before. `requiredConsent()`
may name only `ad_storage`, `analytics_storage`, `ad_user_data` and
`ad_personalization`; an empty or unrecognised list falls back to requiring
both `analytics_storage` and `ad_storage`, so a destination cannot opt out of
consent. The `always_actual` and `legal_basis_override` consent modes apply
to registered destinations exactly as to built-in ones. Built-in keys are
always judged from settings, even if another object is registered under them.

Keep the three methods fast and free of side effects: the worker calls them
for every item, and `wp cf7aiv diagnose` calls them to explain a decision.

```php
use CF7AIV\Contracts\DestinationReadinessInterface;
use CF7AIV\Domain\ConversionEvent;
use CF7AIV\Domain\DeliveryResult;

final class CrmDestination implements DestinationReadinessInterface {
    public function key(): string { return 'acme_crm'; }

    public function isConfigured(): bool {
        return get_option('acme_crm_url') !== '' && defined('ACME_CRM_TOKEN');
    }

    public function signalsReady(array $attribution, array $consent): bool {
        return true; // the CRM records every qualified lead, with or without click ids
    }

    public function requiredConsent(): array {
        return ['analytics_storage'];
    }

    public function send(ConversionEvent $event): DeliveryResult {
        // POST $event->toArray(); return new DeliveryResult(true, false, $remoteId),
        // or DeliveryResult(false, true, null, 'reason') for a transient failure.
    }
}

add_filter('cf7aiv_destinations', function (array $destinations): array {
    $destinations['acme_crm'] = new CrmDestination();
    return $destinations;
});
```

Then create a mapping whose destination key is `acme_crm`, publish it and
enable it.

The delivery worker rechecks eligibility independently. Extensions must not treat an earlier queue decision as authorization. Shadow, calibration, and historical records are never deliverable. The only non-automatic path is an explicit administrator `approve_dispatch` review that locks the item, compares the exact monotonic `review_revision` rendered in the administrator's single or per-item bulk form, advances that revision, and atomically writes reviewer truth plus one immutable approval ID to both the item and its accepted audit record. Missing or malformed revisions are rejected. A concurrent reject, approve-without-send, correction, classifier result/failure, or other review-state mutation advances the durable revision and makes the stale form fail even when tier and outcome are unchanged. Classifier writes preserve existing administrator truth. Manual approval remains subject to origin, consent, configuration, signal, pause, claim, idempotency, and retry gates.

Calibration scheduling persists an immutable run in `scheduling` state before it queues a stratum. The run becomes `active` only after every stratum is accepted. Any queue or activation failure durably changes it to `cancelled` before best-effort unscheduling. Local item, result, and failure writes use `CalibrationRunStoreInterface::whileActive()`: the implementation starts a transaction, locks the run row, requires `active`, performs the short local mutation, and commits. Cancellation locks the same row before changing state, so cancellation and worker persistence have one linear order rather than a check/write race. Source preparation and remote AI calls remain outside that database lock; final persistence, retry, hook, and enqueue authorization are guarded afterward. Extensions must preserve `calibration_run_id` and must not mutate missing, cancelled, or superseded runs. An action that observes `scheduling` is deferred with a bounded activation wait rather than consuming its stratum.

## Policy packs

Starter packs come from `assets/policies/packs.json`. Add or replace packs
with the `cf7aiv_policy_packs` filter, which receives the list of
already-validated pack definitions keyed by pack key. Each returned definition
is validated again as `cf7aiv-policy/v2`. An invalid definition, or one
whose `pack.key` differs from its array key, throws, and the pack list fails
to load, so test your filter. A
pack only seeds new drafts: changing a pack never changes a saved or
published policy.

```php
add_filter('cf7aiv_policy_packs', function (array $packs): array {
    $packs['veterinary'] = $myValidatedV2Definition; // must include pack.key = 'veterinary'
    return $packs;
});
```

## Consent resolvers

Implement `CF7AIV\Contracts\ConsentResolverInterface::resolve(ClientSignals): ConsentSnapshot` and register it on `cf7aiv_consent_resolvers`, to bring a consent platform the plugin does not read natively into the consent recorded with each enquiry.

At capture, each registered resolver receives the same `ClientSignals` the built-in resolver sees (posted Consent Mode values, WordPress Consent API and Complianz decisions) and returns a `ConsentSnapshot`. Its answer counts as one more consent source, combined most-restrictively with the built-in ones:

- a `denied` from any source wins;
- a `granted` counts only where no other source refused or was explicitly unknown;
- an `unknown` from a resolver means it has no answer for that key and is ignored, so a resolver that only knows marketing consent does not block analytics;
- a resolver that throws records every key as `unknown`, which holds conversions back under the default `respect` consent mode.

Report only what the visitor actually chose: never synthesize a grant. The resolver is named in the stored consent source (for example `google+resolver:acme_cmp`). The built-in resolver, registered as `most_restrictive`, is always applied and is skipped if found in the filter.

A resolver affects only the consent recorded at submission. The browser collector decides whether to store attribution from the WordPress Consent API, Complianz or Google Consent Mode, so a site whose platform supports none of those will not have attribution to send.

```php
add_filter('cf7aiv_consent_resolvers', function (array $resolvers): array {
    $resolvers['acme_cmp'] = new class implements CF7AIV\Contracts\ConsentResolverInterface {
        public function key(): string { return 'acme_cmp'; }
        public function resolve(CF7AIV\Domain\ClientSignals $signals): CF7AIV\Domain\ConsentSnapshot {
            $marketing = acme_cmp_choice('marketing'); // true, false or null when undecided
            $state = $marketing === null ? 'unknown' : ($marketing ? 'granted' : 'denied');
            return new CF7AIV\Domain\ConsentSnapshot(['ad_storage' => $state, 'ad_user_data' => $state], 'acme_cmp', gmdate('c'));
        }
    };
    return $resolvers;
});
```

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
