# Data model

The plugin keeps its own tables, prefixed `{$wpdb->prefix}cf7aiv_`, plus a few
options and transients. Table definitions are in
`Infrastructure\Schema::definitions()`. Upgrades are in `Infrastructure\Migration`.

## Tables

### `cf7aiv_items`: one row per captured enquiry

| Column | Meaning |
| --- | --- |
| `fingerprint` | Unique. sha256 of the submission token (or canonical posted data) plus the form ID. |
| `source_type`, `source_reference` | `flamingo` + inbound ID, `cfdb7` + row ID, or `cf7` + NULL until linked |
| `form_id` | CF7 form post ID |
| `calibration_run_id`, `is_calibration`, `is_historical` | Calibration items. These can never be sent. |
| `policy_id`, `policy_version`, `policy_hash` | The policy version and compiled-prompt hash used for classification |
| `state` | Pipeline state (below) |
| `review_state` | Review state (below) |
| `tier_id`, `outcome_id` | The *current* classification: the prediction, or a reviewer's correction |
| `predicted_tier_id`, `predicted_outcome_id` | What the AI said. Never changed by review. |
| `reviewed_tier_id`, `reviewed_outcome_id`, `reviewed_by`, `reviewed_at` | The human decision |
| `review_revision` | Compare-and-swap counter. Bumped by every write that changes review fields. |
| `dispatch_approval_id` | Unique 64-hex ID of an approve-and-dispatch, matched by an audit row |
| `decision_state` | `strong_match`, `needs_review` or `not_eligible` |
| `verification_state` | `off`, `not_required`, `passed`, `disagreed`, `failed`, `unsupported` |
| `evidence_json` | `[{dimension, code, field_key, grounded}]`. The excerpts are *not* stored. |
| `missing_evidence_json`, `exclusion_json`, `ambiguity_json` | Codes from the model |
| `provider_usage_json` | `{input_tokens, output_tokens, reasoning_tokens, calls, profile: {provider, model, reasoning}}` |
| `confidence` | Model confidence. Diagnostic only. |
| `summary` | A fixed phrase for the outcome, never model prose |
| `attribution_encrypted`, `consent_encrypted` | `Crypto` payloads |
| `attribution_ready` | Capture-time readiness flag (`AttributionDiagnostics`) |
| `email_hash`, `phone_hash` | sha256, only with enhanced matching and `ad_user_data` granted |
| `is_shadow` | Set once at first classification: 1 if automation was not live. Never changed afterwards. |
| `classification_attempts`, `last_error` | Failure tracking |
| `created_at`, `updated_at`, `expires_at` | UTC |

`state` values:

| State | Meaning |
| --- | --- |
| `captured` | Waiting for classification |
| `classified` | Classified |
| `retrying` | Transient failure; a retry is queued |
| `error` | Permanent failure, or retries exhausted |

`review_state` values:

| State | Meaning |
| --- | --- |
| `pending` | Not yet classified |
| `review` | Classified, needs a person |
| `eligible` | Classified, eligible for automatic delivery |
| `error` | Classification failed |
| `confirmed` | A reviewer agreed with the AI |
| `corrected` | A reviewer changed the tier and outcome |
| `approved` | Approved, not to be sent |
| `approved_dispatch` | Approved and sent for delivery |
| `rejected` | Rejected |

Classification may overwrite `review_state`, `tier_id` and `outcome_id` only
while `review_state` is `pending`, `review` or `error` and no reviewer is
recorded.

### `cf7aiv_deliveries`: one row per (item, mapping version, event)

| Column | Meaning |
| --- | --- |
| `idempotency_key` | Unique. `sha256(itemId|mappingKey|version|eventName)`. Also the event ID sent to platforms. |
| `item_id`, `destination_key`, `mapping_key` | `mapping_key` stores `key:version` |
| `state` | `pending`, `sending`, `delivered`, `retrying`, `failed`, `dry_run` |
| `attempts`, `last_error`, `response_id` | |
| `request_encrypted` | The `ConversionEvent` as sent (encrypted) |
| `lease_token`, `lease_expires_at` | A 2-minute claim lease |
| `next_attempt_at` | Reserved |

### `cf7aiv_policies`

`policy_key` (`global` or `form:<id>`), `version` (unique per key), `status`
(`draft`, `published`, `superseded`), `policy_format` (schema), `pack_key`,
`pack_version`, `definition_json`, `compiled_prompt`, `compiled_prompt_hash`,
`thresholds_json`, `examples_json`, `created_by`, timestamps.

### `cf7aiv_mappings`

`mapping_key` + `version` (unique), `tier_id`, `destination_key`, `event_name`,
`event_value` (decimal 12,2), `currency` (char 3), `status` (`draft`,
`published`, `superseded`), `enabled`, timestamps.

### `cf7aiv_review_suggestions`: review audit and correction examples

`item_id`, `administrator_id`, `action`, `previous_tier`, `corrected_tier`,
`reviewed_outcome_id`, `example_summary`, `status`, `dispatch_approval_id`
(unique).

- **`change_tier` rows** (status `draft`) are the corrections that "learn
  from corrections" reads.
- **`approve_dispatch` rows** (status `accepted`) are the audit that the
  delivery worker checks.

### `cf7aiv_setup_suggestions`

`suggestion_type`, `value_json`, `provenance_json` (source references such as
`form:12` or page IDs), `status` (`pending`, `accepted`, `rejected`),
`created_by`.

### `cf7aiv_calibration_runs`

`run_id` (primary key), `source_key`, `state` (`scheduling`, `active`,
`cancelled`, `complete`), `administrator_id`, `criteria_hash`,
`scheduled_quota`, `activated_at`, `cancelled_at`.

## Options

| Option | Written by | Contents |
| --- | --- | --- |
| `cf7aiv_settings` | `Plugin::ensureSettings`, admin handlers | All settings (see [Administration](admin.md#settings)) |
| `cf7aiv_secrets` | `saveSettings` | Encrypted API keys and secrets, by name |
| `cf7aiv_schema_version` | `Migration::migrate` | Installed schema (currently `10`) |
| `cf7aiv_paused_destinations` | `WpDeliveryStore::pause` | `{destinationKey: reason}` |
| `cf7aiv_resumed_destinations` | `WpDeliveryStore::resume` | `{destinationKey: UTC time}` |
| `cf7aiv_consent_override_audit` | `saveConsent` | The last 500 legal-basis overrides |
| `cf7aiv_ga4_validation` | `Ga4Validation` | `{state: idle/queued/passed/failed, error, at}` |
| `cf7aiv_accuracy_test` | `AccuracyTest` | The current or last accuracy test run and its results |
| `cf7aiv_event_log` | `EventLog` | The last 200 activity entries |

All are saved with `autoload = false`.

## Transients

| Transient | Purpose |
| --- | --- |
| `cf7aiv_scan_outcome_{userId}` | Site-scan progress and result for one administrator |
| `cf7aiv_provider_test_{userId}` | Result of the last AI connection test |
| `cf7aiv_examples_notice_{userId}` | Skipped-correction reasons after "learn from corrections" |
| `cf7aiv_ai_usage_totals` | Cached token totals for Overview (10 minutes) |

## Migrations

`Migration::VERSION` is the schema version.
`Plugin::upgrade()` (on `plugins_loaded`) runs `Migration::migrate()` whenever
the stored `cf7aiv_schema_version` differs from it. Activation always runs it.

`migrate()`:

1. runs `dbDelta()` with `Schema::definitions()`;
2. runs targeted repairs that `dbDelta` cannot do (`ensureCalibrationSchema`,
   `ensureApprovalSchema`, `ensureFingerprintIndex`);
3. writes the version only if every step succeeded.

To change the schema, see [Recipes](recipes.md#change-the-database-schema).

## Uninstall

`uninstall.php` does nothing unless `privacy.delete_on_uninstall` is true.
When it is, it drops all seven tables and deletes these options:

- `cf7aiv_settings`
- `cf7aiv_secrets`
- `cf7aiv_schema_version`
- `cf7aiv_paused_destinations`
- `cf7aiv_resumed_destinations`
- `cf7aiv_accuracy_test`
- `cf7aiv_consent_override_audit`
- `cf7aiv_event_log`
- `cf7aiv_ga4_validation`

It also deletes the `cf7aiv_ai_usage_totals` transient. A `LIKE` query on the
options table removes the per-administrator transients
(`_transient_cf7aiv_*` and their timeouts). `tests/UninstallTest.php` covers
it. Add any new option, transient or table to both.
