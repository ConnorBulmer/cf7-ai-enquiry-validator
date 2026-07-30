# Setup and rollout

## 1. Prerequisites

Use WordPress 6.5+, PHP 8.1+, Contact Form 7, and Flamingo or CFDB7. Activate the plugin, run `wp cf7aiv migrate`, then check `wp cf7aiv status` and `wp cf7aiv health`. Flamingo should be reported when active; CFDB7 being unavailable is informational, not an error.

## 2. Provider setup

For DeepSeek, create an API key, leave the default Chat Completions URL `https://api.deepseek.com/chat/completions`, and review the default `deepseek-v4-flash` model before production. The adapter explicitly disables V4 thinking for this narrow classification task and supplies the complete fixed JSON schema, preventing reasoning tokens from consuming the JSON output budget. For OpenAI, create an API key and use the Responses endpoint `https://api.openai.com/v1/responses`; the approved default is `gpt-5.4-mini` with strict Structured Outputs and `store: false`.

Prefer `CF7AIV_DEEPSEEK_API_KEY` or `CF7AIV_OPENAI_API_KEY` in `wp-config.php`, ideally sourced from environment variables. The admin secret inputs are write-only encrypted alternatives. Never paste credentials into policy text, logs, test fixtures, CLI arguments, tickets, or this repository.

## 3. Guided policy Setup

Open **Enquiry Validator > Setup**. The lifecycle is:

1. **Setup** — choose a starter pack, optionally analyse public site/form information, review every suggestion, and save a draft.
2. **Testing** — classify and review with automation still forced to shadow.
3. **Live** — indicates that the reviewed policy lifecycle is ready, but still leaves automation in shadow until the separate Integrations transition.

The included packs are Care and supported living; Marketing, web, and design agencies; Training and education; Business consultancy; Home and professional services; B2B products and services; and Custom business. Setup is skippable.

The optional site/form analysis reads public page copy and safe Contact Form 7 structure only; it never reads submission values. That analysis sends the bounded public profile to the selected capable provider. Suggestions are stored as pending records with page, form, or pack provenance. Administrators must accept, edit, or reject each suggestion; nothing is accepted or published automatically.

Simple mode presents guided business, service, audience, exclusion, and whitelist choices. Advanced mode exposes policy JSON/version controls plus evidence, ambiguity, provider-usage, and confidence diagnostics. The version 2 outcome vocabulary is universal across packs: `primary_opportunity`, `secondary_opportunity`, `legitimate_non_conversion`, `excluded`, `spam`, and `insufficient_information`. A **Strong match** depends on grounded required/supporting evidence and the absence of policy exclusions or unresolved ambiguity. Model confidence is diagnostic only, not a dispatch threshold.

`wp cf7aiv pilot` remains available for the care-home compatibility helper. `wp cf7aiv pilot --create-policy-draft` saves a draft only. Titles containing Care Enquiry, Brochure Request, Jobs, or General Enquiry receive suggestions; unknown forms are untouched.

Existing `cf7aiv-policy/v1` policies remain readable but lack grounded v2 evidence rules, so their classifications are held at **Needs review** and cannot auto-dispatch. `PolicyUpgrader` creates a `cf7aiv-policy/v2` draft for inspection. It does not publish the draft, alter the v1 policy, reanalyse historical results, or enable delivery.

Administrator reviews create durable review truth and, for corrections, draft policy suggestions. There is no automatic learning or automatic policy rewrite.

## 4. Historical calibration

Historical calibration is optional and separate from Setup completion. Its suggested maximum is 25. Before scheduling, choose the stored source, specific forms, date range, and maximum; preview the actual safe-field union from currently published policies; then explicitly select the authorization checkbox. The preview fingerprint and normalized field snapshot are bound to every queued stratum. Historical classification intersects that snapshot with the current policy, so later policy widening cannot send a field that was not displayed at opt-in. A changed preview must be reviewed and authorized again. An upgrade or newly published policy never opts the site in and never reanalyses the existing historical set.

Every imported item is marked both historical and calibration-only and is ineligible for every destination. Each attempt receives a new immutable run ID. A run becomes active only after every stratum has been queued. Cancellation and queued item/result/failure writes use the authoritative run lock, so cancelled or superseded work fails closed even if an action was already running or retried. Start a fresh administrator run instead of reviving a cancelled one.

For CLI-only v1 compatibility, `wp cf7aiv calibrate --source=flamingo --date-from=2026-01-01 --date-to=2026-07-22 --maximum=25 --explicit-opt-in=1 --form-ids=10,20` remains available. The `backfill` alias accepts the same explicit scope. A smaller available sample, including zero records, is valid, but it is still historical/calibration-only. Never use it to re-send or dispatch stored enquiries.

## 5. Optional deeper AI verification

In **Integrations**, capable providers can offer **Verify automatic conversion candidates with an additional deeper AI call**. This is off by default. It makes a second provider request only for an otherwise automatic candidate, uses additional tokens, and may increase provider cost. Disagreement, provider failure, or lost capability changes the item to **Needs review**. The setting is hidden/forced off for providers that do not implement the verification capability.

## 6. Destinations

### GA4

Create a GA4 Measurement Protocol API secret and configure the measurement ID plus `CF7AIV_GA4_API_SECRET`. Credentials are required for worker preflight even in dry-run because the concrete adapters reject empty credentials before producing their sanitized payload. Keep GA4 disabled/dry-run, run the direct debug validation, and inspect ValidationMessages. Debug validation never claims a live delivery. Save the GA4 mapping as a draft, then publish and enable it through the separate nonced transitions. The separate GA4-live action remains blocked until debug validation succeeds. Changing the measurement ID, API secret, or enabled state clears prior validation/live state and forces dry-run.

To import the event into Google Ads: link the GA4 property to the correct Google Ads account, mark the configured GA4 event as a key event, open Google Ads **Goals > Conversions > Summary**, choose **Create conversion action > Import > Google Analytics 4 properties > Web**, select the event, configure counting/value/window settings, and verify attribution/reporting before bidding against it. The plugin does not call the Google Ads conversion API in v1.

### Existing server-side GTM

Enable Advanced mode only when a server-side GTM tagging server already exists. Configure:

1. its HTTPS base URL, such as `https://metrics.example.com`;
2. the GA4 Measurement ID;
3. `CF7AIV_SERVER_GTM_API_SECRET`;
4. destination key `server_gtm` in the tier mapping.

The plugin sends a GA4 Measurement Protocol-shaped event to that first-party tagging-server domain. The request contains the mapped event name, value/currency, client/session IDs, available `gclid`/`gbraid`/`wbraid`, actual consent, a stable event/transaction ID, and optional consented hashes. It never contains enquiry text or raw identity.

The server container needs its GA4 client, a Conversion Linker, and a native Google Ads Conversion Tracking tag configured with the conversion action's ID and label. Limit that Ads tag's custom-event trigger to the qualified event name and map `transaction_id` for deduplication. Inspect the local dry-run payload first, then use sGTM Preview without enabling the Ads trigger for `cf7aiv_configuration_check` or other test events.

This route does not require the plugin to provision Google Cloud, call the Google Ads API, use OAuth, or obtain a developer token. Operators may host sGTM on Google Cloud, another managed provider, or their own compatible infrastructure.

For Maximize Conversions, set the genuinely qualified-lead conversion action to **Primary** and ensure its goal is selected by the campaign. Keep lower-value/general actions Secondary. If materially different tiers are all bidding targets, assign defensible values and evaluate Maximize Conversion Value instead. A delayed human qualification performed days after submission is better suited to Google Ads Data Manager/offline enhanced conversions than a web-tag event.

### Microsoft UET CAPI

Configure the UET tag ID and `CF7AIV_MICROSOFT_UET_TOKEN`. Keep the destination dry-run, verify event name/currency/value and consent behavior, then publish its mapping deliberately. Enhanced identity is optional and requires granted `ad_user_data`.

### Signed webhook

Use an HTTPS URL and `CF7AIV_WEBHOOK_SECRET`. The plugin signs the normalized PII-free JSON envelope. Insecure HTTP is disabled by default. Verify signature, timestamp/idempotency handling, retry semantics, and receiver logs in dry-run before publishing the mapping.

## 7. Consent modes

`respect` requires the destination's applicable captured consent grants. `always_actual` may send while preserving denied/unknown actual states in the event. `legal_basis_override` requires an explicit operator reason and appends administrator/date/reason to the dedicated audit history while preserving actual consent in the event; it never changes denied/unknown into a fake grant. Obtain legal review before enabling an override.

## 8. Tracking Health

Use read-only checks and non-uploadable synthetic identifiers. `wp cf7aiv dry_run` sends no network request. Destination validation must remain dry-run/debug. Confirm Action Scheduler is available, CF7/source plugins are active, queues are healthy, and paused destinations are understood.

## 9. Retention and uninstall

Choose a retention period appropriate to the site; the default is 90 days. Test privacy export/erasure. **Delete on uninstall** is off by default and should be enabled only with an approved data-deletion plan.

## 10. Production change gate

Before leaving shadow, require a reviewed published v2 policy, grounded-evidence review, explicitly published/enabled mappings, destination-specific identifiers or permitted identity, verified consent mode, completed destination dry-runs, idempotency evidence, queue monitoring, rollback ownership, and a documented approval. Confidence alone is never approval. Use the separate automation-live transition only after that review; the same screen provides explicit mapping-disable, GA dry-run, and return-to-shadow controls. Publishing a policy or entering the Live lifecycle does not enable automatic delivery. Never enable AI or Ads using real credentials in an untrusted testbed.

Concurrent work fails closed: global fingerprints prevent cross-source duplicates; classification compare-and-swap revisions preserve concurrent human review; manual approve-and-dispatch requires an atomically linked unique audit record; historical-run locks serialize cancellation and writes; and delivery idempotency keys plus lease tokens prevent stale workers from recording another worker's claim.
