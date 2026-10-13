# Delivery and destinations

Delivery decides, for one classified item, whether each mapped destination may
receive a conversion, and sends it at most once. It runs in the
`cf7aiv_evaluate_delivery` job (first attempt) and the `cf7aiv_deliver_item`
job (retries). Both call `DeliveryWorker::process(itemId)`.

Code: `src/Delivery/`, `src/Destinations/`, `src/Domain/ConversionEvent.php`,
`src/Domain/DeliveryResult.php`.

## The worker, step by step

`DeliveryWorker::process()` works in two stages. First come item-level checks.
If any fails, the worker records the gate reason and stops.

| Check | Gate reason |
| --- | --- |
| Item exists | `item_not_found` |
| Item has a tier | `tier_not_classified` |
| Item was first classified in live mode (`is_shadow = 0`) | `shadow_item` |
| Not a calibration or historical item | `calibration_or_historical_item` |
| Approved: automatically or manually (see below) | `not_approved` |
| The tier has at least one mapping | `no_published_mapping` |

**Automatic approval:**

- `decision_state = 'strong_match'`;
- and, when verification mode is `automatic_candidates`,
  `verification_state = 'passed'`.

**Manual approval** requires all of the following:

- `review_state = 'approved_dispatch'`;
- the reviewed tier and outcome match the current tier and outcome;
- `reviewed_by` and `reviewed_at` are set;
- `dispatch_approval_id` is a 64-character hex string;
- `auditedManualApproval()` finds a matching accepted
  `approve_dispatch` row in `wp_cf7aiv_review_suggestions`.

A tampered item row cannot pass this alone.

Then each mapping for the tier is checked in turn. A failure skips only that
mapping, records a gate reason, and moves on to the next.

| Check | Gate reason |
| --- | --- |
| Mapping is published and enabled | `mapping_not_published` |
| Destination is not auto-paused | `destination_paused` |
| Destination is registered and `DestinationReadiness::configured()` | `destination_not_configured` |
| `ConsentPolicy::evaluate()` permits it | `consent_not_permitted` (or `consent_evaluation_failed`) |
| `DestinationReadiness::signalsReady()` | `signals_not_ready` |
| `WpDeliveryStore::claim()` acquired the lease | `claim_not_acquired` |

When every check passes, the worker:

1. builds the event (`EnvelopeBuilder::build`);
2. sends it (`$destination->send($event)`);
3. records the attempt (`WpDeliveryStore::record`);
4. fires `cf7aiv_after_delivery`;
5. queues a retry if the result is retryable;
6. checks the auto-pause threshold.

Gate reasons go through `DiagnosticsService::recordGate()`. That fires
`cf7aiv_delivery_gate`, which the activity log records. Use
`wp cf7aiv diagnose --item=<id>` to see every gate for one item.

## Readiness

`DestinationReadiness` is the single place that says whether a destination
can send.

`configured($key, $settings, $secret, $destination)`: for a built-in key,
the destination is `enabled` in settings, and:

| Destination | Also requires |
| --- | --- |
| `ga4` | Measurement ID and `ga4_api_secret`. In addition, it is either in dry run or `ga4Live()`. |
| `server_gtm` | A valid HTTPS endpoint (`ServerGtmDestination::isValidEndpoint`), a measurement ID and `server_gtm_api_secret` |
| `microsoft_uet` | Tag ID and `microsoft_uet_token` |
| `webhook` | A URL (HTTPS unless `allow_insecure`) and `webhook_secret` |

`ga4Live($config)` is true when `live_enabled` is set and either:

- the debug validation in `Ga4Validation` has passed; or
- validation is idle and the legacy `debug_validated` flag is set (sites
  validated before the result moved to its own option).

`signalsReady(...)` is the minimum matching data each destination needs:

| Destination | Needs |
| --- | --- |
| `ga4` | GA client ID |
| `server_gtm` | GA client ID, or a Google click ID (`gclid`/`gbraid`/`wbraid`) |
| `microsoft_uet` | `msclkid`; or enhanced matching on, `ad_user_data` granted and a valid email or phone hash |
| `webhook` | nothing |

Any other key (a destination registered by another plugin) is judged by the
destination object itself, if it implements
`Contracts\DestinationReadinessInterface`:

- `configured()` asks `isConfigured()`;
- `signalsReady()` asks `signalsReady($attribution, $consent)`;
- `requiredConsent()` asks `requiredConsent()`, keeps only the four known
  consent keys, and falls back to `analytics_storage` + `ad_storage` when
  nothing valid is left.

A throw, or a destination that does not implement the interface, counts as
"not ready". Built-in keys are always judged from settings, whatever object
is registered under them. `DiagnosticsService::diagnose()` looks up the
registered object for non-built-in keys and applies the same rules.

`DeliveryHooks::destinations()` registers the built-in destinations from
settings. A destination that is not enabled is not registered at all. GA4 is
registered in dry-run mode, or in live mode only when `ga4Live()` is true.

## Consent

Consent is checked against the snapshot stored at capture time, not the
visitor's current state.

`DestinationReadiness::requiredConsent($key, $destination)` lists the keys:

| Destination | Required keys |
| --- | --- |
| `ga4` | `analytics_storage` |
| `microsoft_uet` | `ad_storage` |
| `server_gtm`, `webhook` | `analytics_storage` and `ad_storage` |
| A registered destination implementing `DestinationReadinessInterface` | What its `requiredConsent()` declares (known keys only; empty means both) |
| Any other destination | `analytics_storage` and `ad_storage` |

`ConsentPolicy::evaluate($mode, $actual, $override, $required)` takes one of
three modes (`settings.consent.mode`):

- **`respect`** (default): every required key must be `granted`.
- **`always_actual`**: always permitted. The real consent states are still
  sent, so the platform can apply its own rules.
- **`legal_basis_override`**: always permitted, but only when
  `settings.consent.override` has an administrator ID, a date and a reason.
  The decision is attached to the event as `consent_override`. Saving the
  override appends it to the `cf7aiv_consent_override_audit` option, which
  keeps the last 500 entries.

Hashed email and phone are added to the event only when
`privacy.enhanced_matching` is on and `ad_user_data` is `granted`.

## The event and idempotency

`EnvelopeBuilder::build()` produces a `ConversionEvent`:

- **Identity.** `eventId` = `idempotencyKey` =
  `sha256(itemId | mappingKey | mappingVersion | eventName)`. The same item,
  mapping version and event name always produce the same key.
- **Attribution.** Copies only `client_id`, `session_id`,
  `session_started_at` and click IDs. Never UTMs or URLs.
- **Consent.** The four consent keys, each normalised to `granted`,
  `denied` or `unknown`.
- **Context.** `form_id` and `source_type` only.
- **Times.** `submission_at` and `classification_at`.

`ConversionEvent::toArray()` is the payload the webhook receives verbatim. It
is also stored encrypted in `wp_cf7aiv_deliveries.request_encrypted`.

## Claiming, recording, retrying

`WpDeliveryStore::claim()`:

1. Refuses if this item was already delivered under *another version* of the
   same mapping (`sentUnderAnotherVersion`). Republishing a mapping does not
   resend old conversions.
2. `INSERT IGNORE` a `pending` row keyed by the unique `idempotency_key`.
3. Takes a 2-minute lease in one conditional `UPDATE`:
   - **Allowed from:** `pending`, `retrying`, `dry_run`, or a `sending` row
     whose lease has expired.
   - **On success:** the row moves to `sending` with a random lease token.
4. Returns `acquired = true` only if this worker's `UPDATE` changed the row.

`record()` sets the final state (only if the lease token still matches):

| Result | State |
| --- | --- |
| Dry run | `dry_run` (can be claimed again later, for example after going live) |
| Successful | `delivered` |
| Failed, retryable | `retrying` |
| Failed, permanent | `failed` |

**Retries.** A retryable result with `attempts ≤ 5` queues `cf7aiv_deliver_item`
after `RetryPolicy::delay(attempt)` = `min(3600, 60 × 2^(attempt−1))` seconds.
`ResponseClassifier` treats HTTP 408, 425, 429, 5xx, timeouts and transport
errors as retryable. Microsoft has its own `MicrosoftUetResponseClassifier`.

**Auto-pause.** After a permanent (non-dry-run) failure, the worker counts
`failed` deliveries for that destination since the later of:

- its last successful delivery;
- its last manual resume.

When the count reaches `delivery.pause_threshold` (default 3), the destination
is paused (`cf7aiv_paused_destinations`).

- **Resume:** `WpDeliveryStore::resume()`, from the Tracking Health screen
  (`cf7aiv_resume_destination`). It records the time in
  `cf7aiv_resumed_destinations`, so earlier failures stop counting.

## Mappings

A mapping (`DeliveryMapping`, table `wp_cf7aiv_mappings`) says: send tier
`tier_id` to `destination_key` as `event_name` worth `event_value` in
`currency` (ISO 4217, upper case).

- **Identity:** a mapping is identified by `mapping_key` + `version`.
- **Status:** `draft` → `published` → `superseded`, plus a separate
  `enabled` flag. `isPublished()` means published *and* enabled.
- **Transitions:** `WpMappingLifecycle::publish/enable/disable()` run in a
  transaction with `SELECT … FOR UPDATE`. Publishing supersedes the other
  published version of the same key.
- **Lookup:** `WpMappingRepository::publishedForTier()` returns the mappings
  for one tier.

Publishing a mapping does not turn on automation, and turning on automation
does not bypass any gate above.

## Destinations

Each destination implements `DestinationInterface::send(ConversionEvent): DeliveryResult`.
In dry run it returns `DeliveryResult(dryRun: true)` with the request it
*would* have sent, with secrets redacted.

### GA4 (`GA4Destination`)

- **Request.** Measurement Protocol `POST https://www.google-analytics.com/mp/collect?measurement_id=…&api_secret=…`.
  - Event params: `event_id`, `value`, `currency`, `engagement_time_msec` and,
    when known, `session_id`.
  - Body: `client_id` and `consent` (`ad_user_data`, `ad_personalization` as
    `GRANTED`/`DENIED`).
  - `timestamp_micros` is set when the submission was under 72 hours ago.
  - `user_data` hashes are added when allowed.
- **Warnings.** Session attribution is weak when the session ID is missing or
  more than 24 hours old. `ConversionEvent::sessionStartedAt()` falls back to
  the session ID, which is the session's start time.
- **Validation.** Debug mode uses `/debug/mp/collect` with
  `validation_behavior: ENFORCE_RECOMMENDATIONS` and fails on any
  `validationMessages`. This backs the Integrations "Validate" button
  (`cf7aiv_validate_ga4` → job `cf7aiv_validate_ga4_job`). The result is
  stored in the `cf7aiv_ga4_validation` option (`Ga4Validation`), not in
  settings. Changing the measurement ID, the secret or the enabled state
  invalidates it.

### Server-side GTM (`ServerGtmDestination`)

Sends the same GA4-shaped request to `<endpoint_url>/mp/collect` on an
existing tagging server. The container owns the Google Ads tag. The endpoint
must be an HTTPS base URL with no query string or fragment
(`isValidEndpoint`). Warnings
from the response are kept on the result.

### Microsoft UET (`MicrosoftUetDestination`)

- **Request.** `POST https://capi.uet.microsoft.com/v1/{tagId}/events`, with
  the token as a Bearer header.
- **Identifiers.** `userData` uses `msclkid`, `anonymousId` and, when allowed,
  `em`/`ph` hashes. At least one identifier is required.
- **Event.** `eventId` and `customData.transactionId` are the event ID, so
  Microsoft can de-duplicate. `continueOnValidationError` is false.
- **Testing.** `MicrosoftUetTestService` (`wp cf7aiv microsoft_test`, admin
  `cf7aiv_test_microsoft`) sends one zero-value test event, after a typed
  confirmation.

### Webhook (`WebhookDestination`)

- **Body.** `POST` of `ConversionEvent::toArray()` as JSON, with a 10 s
  timeout.
- **Headers.** `X-CF7AIV-Event-ID`, `X-CF7AIV-Timestamp`, and
  `X-CF7AIV-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<body>">`
  keyed by the webhook secret.
- **HTTPS.** Required unless `allow_insecure` is set.

A receiver should check the signature and the timestamp age, and de-duplicate
on the event ID.

## Adding a destination

See [Recipes](recipes.md#add-a-destination), and
[Extending](../EXTENDING.md#making-a-registered-destination-sendable) for one
registered by another plugin.
