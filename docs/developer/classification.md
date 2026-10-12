# Classification

Classification turns a captured item into a structured, grounded decision. It
runs in the `cf7aiv_classify_item` job. It never sends anything; at most it
queues `cf7aiv_evaluate_delivery`.

Code: `src/Classification/`, `src/Providers/`, `src/Domain/Classification*.php`,
`assets/policies/packs.json`.

## The worker, step by step

`ClassificationWorker::process(itemId, replacePrediction = false, historicalWhitelistSnapshot = [])`:

1. **Load the item.** If it is a calibration item whose run is no longer
   `active`, stop (see [Calibration](admin.md#calibration-historical-sampling)).
   If it already has a prediction and `replacePrediction` is false, stop.
   Duplicate jobs are therefore harmless.
2. **Resolve the source.** A `cf7` item is linked to a locator source first
   (see [Intake](intake-and-attribution.md#items-captured-before-their-source-row-exists)).
   The original fields are then loaded. Missing content is a permanent error.
3. **Find the policy.** `PolicyRepository::publishedForForm(formId)` returns
   the newest published policy with key `form:<id>`, falling back to `global`.
   No published policy is a permanent error.
4. **Build the request.**
   - The whitelist is `ContextBuilder::safeWhitelist(policy.ai_whitelist)`.
   - For historical or calibration items it is further intersected with the
     snapshot captured when the run was scheduled. Widening a policy later
     cannot expose a new field to an old run.
   - `RequestFactory::build()` creates a `ClassificationRequest`. It holds:
     - the redacted context;
     - the compiled prompt;
     - the allowed tier, outcome, evidence, exclusion and ambiguity codes.
5. **Classify.** `$provider->classify($request)` uses the provider selected in
   settings (`providers.selected`).
6. **Evaluate evidence.** `EvidenceEvaluator::evaluate()` returns
   `strong_match`, `needs_review` or `not_eligible`.
7. **Check for a duplicate.** A would-be automatic send becomes `needs_review`
   with ambiguity flag `possible_duplicate` when an earlier enquiry with the
   same email or phone hash was captured in the previous 30 days and was
   eligible, approved for dispatch, or has a delivery that is pending,
   sending, retrying or delivered. See
   `recentEnquiryFromSamePerson`. It only works when hashes exist
   (enhanced matching on, `ad_user_data` granted).
8. **Verify (optional).** When `automation.verification_mode` is
   `automatic_candidates`, an automatic-send candidate is classified again by
   `classifyWithReasoning()` (deep reasoning). Only a matching tier and
   outcome that is again `strong_match` passes. Disagreement, an error or an
   unsupported provider all become `needs_review`. The extra tokens are added
   to the item's usage.
9. **Check eligibility.** `Eligibility::evaluate()` lists the reasons an item
   cannot be sent automatically:
   - `policy_not_published`
   - `shadow_mode`
   - `calibration_item`
   - `historical_item`
   - `tier_not_ads_eligible`
   - `decision_not_strong`
   - `mapping_not_published`

   No reasons means eligible.
10. **Store.** `WpClassificationItemStore::succeed()` writes the prediction,
    evidence, usage and decision state. Then:
    - It sets `is_shadow` only once (`COALESCE`), so reanalysing later in
      live mode cannot turn a shadow item into a sendable one.
    - It changes the reviewable fields (`review_state`, `tier_id`,
      `outcome_id`) only if a person has not reviewed the item, using
      compare-and-swap on `review_revision`.
    - `review_state` becomes `eligible` or `review`.
11. **Hand off.** Fires `cf7aiv_after_classification`. When eligible, queues
    `cf7aiv_evaluate_delivery`.

### Errors and retries

Providers throw two kinds of exception:

| Exception | Examples | Worker behaviour |
| --- | --- | --- |
| `TransientProviderException` | HTTP 0/408/429/5xx, timeout, DeepSeek `insufficient_system_resource`, source row not visible yet | `state = retrying`; requeue with delay `min(3600, 60 × 2^(attempt−1))` while `classification_attempts ≤ 3` |
| `PermanentProviderException` | 4xx (bad key or URL), malformed JSON twice, ungrounded evidence twice, `finish_reason: length` or `content_filter`, OpenAI `incomplete`, no policy, no source | `state = error`, `review_state = error`, reason saved in `last_error` |

Any other `Throwable` is stored as the generic "Classification could not be
completed." Provider messages are written to be safe to show. They include
HTTP status codes but never bodies or keys.

## Policies

A policy is a JSON definition stored in `wp_cf7aiv_policies` with a key, a
version and a status (`draft` → `published` → `superseded`).

- **Keys:** `global` applies to every form; `form:<id>` overrides it for one
  form.
- **Publishing:** `WpPolicyStore::publish()` runs in a transaction. It marks
  the target published and any other published version of the same key
  superseded.
- **Validation:** `PolicyValidator::import()` validates every save and
  rejects unknown keys. Prose (labels, descriptions, terminology, examples)
  goes through `assertSafeString()`. That rejects anything the `Redactor`
  would change (emails, URLs, phone numbers, IPs, click IDs) and words such as
  "email", "phone", "postcode" or click-ID names.

### Schema v2 (`cf7aiv-policy/v2`)

The keys allowed at the top level are listed in `PolicyValidator::IMPORTABLE_V2`.

| Key | Purpose |
| --- | --- |
| `schema` | `cf7aiv-policy/v2` |
| `pack` | `{key, version, label}` of the starter pack it came from |
| `business` | `{description, services[], audiences[]}` |
| `outcome_labels` | Human labels for the six universal outcomes |
| `evidence_catalog` | `[{code, dimension, description}]`. The model may cite only these codes. |
| `tier_definitions` | See below |
| `exclusion_catalog`, `ambiguity_catalog` | Codes the model may return as exclusions or ambiguity flags |
| `terminology` | Site-specific words and what they mean |
| `examples` | `[{summary, tier_id, not_tier_id?}]`. Reviewer-written, non-identifying. |
| `field_mapping` | Optional notes on what form fields mean (free-form object, passed to the model with the policy) |
| `ai_whitelist` | Field names the AI may see (always filtered again by `ContextBuilder`) |
| `migration` | Set by `PolicyUpgrader` on drafts converted from v1 |

Each tier has the keys in `PolicyValidator::V2_TIER_KEYS`:

```json
{
  "id": "residential_care",
  "label": "Residential care enquiry",
  "outcome_id": "primary_opportunity",
  "description": "…",
  "ads_eligible": true,
  "required_evidence": [["service_fit"], ["care_need", "timeframe"]],
  "supporting_evidence": ["audience_fit", "location_fit"],
  "minimum_supporting_evidence": 1,
  "exclusion_codes": ["recruitment"],
  "ambiguity_codes": []
}
```

`required_evidence` is a list of groups. Each group is satisfied by at least
one grounded code from it.

### Schema v1 (legacy)

v1 policies (`cf7aiv-policy/v1`) still load and classify, but
`EvidenceEvaluator` always returns `needs_review` for them, because v1 has no
grounded-evidence rules. `PolicyUpgrader::toV2()` creates a v2 *draft* from a
v1 policy. It never edits the original.

### Starter packs

`assets/policies/packs.json` is a list of complete v2 definitions, one per
industry:

| Pack key | Label |
| --- | --- |
| `care_supported_living` | Care and supported living |
| `agency` | Marketing, web, and design agencies |
| `training_education` | Training and education |
| `business_consultancy` | Business consultancy |
| `home_professional_services` | Home and professional services |
| `b2b_products_services` | B2B products and services |
| `custom_business` | Custom business |

`PolicyPackRegistry` loads them, validates each one and applies the
`cf7aiv_policy_packs` filter. `GuidedPolicyDraft::build()` merges a pack with
what the administrator typed and any accepted setup suggestions. The result
is a draft. `StarterPolicy` is the minimal built-in definition used by the CLI
pilot and the Policy Lab "starter" action.

### The compiled prompt

`PolicyCompiler::compile()` builds the system prompt from fixed rules plus the
canonical (key-sorted) policy JSON, and hashes it. The prompt and hash are
stored with the policy version (`compiled_prompt`, `compiled_prompt_hash`),
and each classified item records the hash it used (`policy_hash`).

Two rules about the prompt:

- **Changing the fixed text changes the hash of every policy compiled
  afterwards.** Existing policy rows keep their stored prompt until they are
  saved again.
- **A sentence is added only when it applies.** The examples sentence appears
  only when a policy has examples, so a policy without them compiles to
  exactly the prompt it always did. Follow the same rule for new sentences.

The provider adds to the system message at send time:

- the untrusted-input rule (`JsonProvider::UNTRUSTED_INPUT_RULE`): the
  enquiry is data, never instructions;
- the JSON Schema for the answer;
- for DeepSeek, which has no strict schema mode, extra guidance mapping each
  evidence code to its dimension.

The user message is the redacted context as JSON.

## What the AI sees

`ContextBuilder::request()` builds the context:

- **Whitelisted fields only.** The field must be in the safe whitelist and
  present in the source record. Empty values are dropped.
- **Forbidden by name, whatever the whitelist says.** Any field whose name
  contains one of `ContextBuilder::FORBIDDEN_PARTS`, or starts with `cf7aiv_`.
  The parts are: `name`, `email`, `phone`, `tel`, `mobile`, `address`,
  `postcode`, `ip`, `file`, `attachment`, `upload`, the click-ID names,
  `utm_`, `campaign`, `source`, `medium`, `landing`, `referrer`, `consent`,
  `cookie`, `session`, `client_id`, `user_id`, `hash`, `secret`, `token`,
  `password`.
- **Redacted text.** `Redactor::redact()` replaces emails, URLs, `key=value`
  click IDs, IPv4 addresses and phone-like digit runs with `[REDACTED_*]`
  tokens.

The review screen shows reviewers exactly this context through
`Admin\ReviewContext::fields()`, built by the same code.

## Validating the answer

`ResultValidator::fromJson()` checks the model's JSON strictly:

- The keys must match the schema exactly: `outcome_id`, `tier_id`,
  `reason_code`, `model_confidence`, `evidence`, `missing_evidence`,
  `exclusion_codes`, `ambiguity_flags`, `summary`.
- The outcome, tier, reason code, evidence codes, exclusions and ambiguity
  flags must all be in the published policy.
- **Grounding.** Every evidence item names a `field_key` from the context and
  an `excerpt`. `EvidenceGrounder::ground()` requires the excerpt (2–160
  characters, after lower-casing, decoding entities and collapsing whitespace)
  to occur inside that field's value. One ungrounded quote invalidates the
  whole answer.
- **Dimension.** Each evidence item's dimension is taken from the catalogue
  for its code, not from the model.
- **Summary.** The model's `summary` is checked but **not kept**. The stored
  summary is a fixed phrase for the outcome (`safeOutcomeSummary`).

An invalid answer is retried once within the same job
(`JsonProvider::classifyMode` makes up to two calls). Two failures give a
permanent error.

## Evidence rules

`EvidenceEvaluator::evaluate()` applies these rules in order. The first one
that matches decides the result.

| Condition | Result |
| --- | --- |
| Policy is v1 | `needs_review` (`legacy_policy_without_grounded_rules`) |
| Outcome is `legitimate_non_conversion`, `excluded`, `spam` or `insufficient_information` | `not_eligible` |
| Any ambiguity flag | `needs_review` (`ambiguous_evidence`) |
| Tier not in policy, or tier's outcome ≠ returned outcome | `needs_review` |
| An exclusion code listed on the tier | `not_eligible` |
| Any other exclusion code | `needs_review` (`exclusion_mismatch`) |
| A code both grounded and listed as missing | `needs_review` |
| A `required_evidence` group with no grounded code | `needs_review` (`required_evidence_missing`) |
| Fewer grounded supporting codes than `minimum_supporting_evidence` | `needs_review` |
| Outcome not primary or secondary opportunity | `needs_review` |
| Otherwise | `strong_match` |

`model_confidence` is stored for diagnostics and never used here.

## Providers

Both built-in providers extend `Providers\JsonProvider`. They are built in one
place, `ClassificationHooks::buildProvider($key, $providerSettings)`, which the
`cf7aiv_ai_providers` filter and the accuracy test both use. A provider tested
there is therefore built exactly as production builds it.

| | DeepSeek (`DeepSeekProvider`) | OpenAI (`OpenAIProvider`) |
| --- | --- | --- |
| API | Chat Completions, `response_format: json_object` | Responses API, `store: false`, strict `json_schema` |
| Default model | `deepseek-flash` (`Settings::DEFAULT_DEEPSEEK_MODEL`) | `gpt-5.4-mini` |
| Reasoning off | `thinking: disabled`, `temperature: 0` | `reasoning.effort: none` where the model supports it |
| Standard | thinking enabled, `reasoning_effort: high` | `reasoning.effort: medium` |
| Deep | thinking enabled, `reasoning_effort: max` | `reasoning.effort: high` |
| Incomplete answer | `finish_reason` `length` or `content_filter` → permanent; `insufficient_system_resource` → transient | `status: incomplete` → permanent |
| Verification pass | Yes (`VerificationCapableProviderInterface`) | No |
| Setup suggestions | Yes (`PolicySuggestionProviderInterface`) | Yes |

**Retired DeepSeek models.** `DeepSeekProvider::RETIRED_MODELS` lists model
IDs that no longer exist. `Settings::upgrade()` moves a saved retired model to
the default once. When moving off `deepseek-reasoner` with reasoning "off", it
sets reasoning to "standard", because that model always reasoned.

### Token budgets and timeouts

All in `JsonProvider`:

- **Answer budget.** 1,800 tokens for a classification
  (`CLASSIFICATION_ANSWER_TOKENS`); 1,500 for setup suggestions.
- **Thinking allowance.** Added on top of the answer budget: 8,000 for
  standard, 24,000 for deep (`REASONING_ALLOWANCE`).
- **Timeout.** The configured timeout (`providers.timeout`, default 60,
  maximum `Settings::MAX_PROVIDER_TIMEOUT` = 300) is raised to a floor for
  the reasoning level: 90 s for standard, 180 s for deep
  (`REASONING_TIMEOUT_FLOOR`).

The Integrations screen shows the effective timeout from the same constants.

### Other provider features

- `testConnection()` makes the smallest possible call (a few tokens) to prove
  the key, URL and model. It backs the "Test connection" button
  (`cf7aiv_test_provider`).
- `profile()` returns `{provider, model, reasoning}`. It is saved in the
  item's `provider_usage_json`, so accuracy can be compared per setup
  (`CalibrationMetrics::byProfile`).

## Changing classification safely

- New rules that can only make things *stricter* are safe. Anything that can
  turn `needs_review` into `strong_match` must come with a test in
  `tests/GroundedEvidenceTest.php`, `tests/PolicyV2Test.php` or
  `tests/ClassificationTest.php`.
- To measure a prompt, model or policy change before it goes live, use the
  Policy Lab accuracy test (`Admin\AccuracyTest`). It replays reviewed
  enquiries through the same `buildProvider` and `RequestFactory`.
- Never store or display model prose as fact. Add a fixed phrase instead.
