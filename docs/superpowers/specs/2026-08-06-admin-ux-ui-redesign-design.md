# CF7 AI Enquiry Validator Admin UX/UI Redesign

## Status

Design approved through collaborative review. Implementation is intentionally not included in this specification.

## Goal

Make the CF7 AI Enquiry Validator understandable and safe to operate for both first-time setup and repeat administration. The redesign must clarify relationships between fields, reduce irrelevant controls, make saving obvious, preserve WordPress-native safety boundaries, and expose provider capabilities honestly.

## Current Problems

The current staging UI has these confirmed problems:

- Setup presents unrelated business, qualification, whitelist, and lifecycle fields in dense side-by-side layouts.
- Integrations is one long page containing provider, destination, consent, lifecycle, mapping, and retention controls.
- DeepSeek and OpenAI fields are displayed together even when only one provider is selected.
- Ordinary save behavior is not persistently discoverable while editing.
- Google route and other integration sections have inconsistent spacing and weak grouping.
- High-risk transitions compete visually with ordinary settings saves.
- Reasoning is represented only as an optional binary verification action; ordinary provider reasoning effort is not configurable.
- Advanced technical controls are mixed with first-time setup decisions.

## Approved UX Model

Use a hybrid model:

- Guided, step-based Setup for first-time configuration.
- Compact, tabbed Integrations for repeat administration.
- WordPress-native forms and server-side handlers remain the source of truth.
- No replacement SPA or client-side-only persistence layer.

## Information Architecture

### Setup

Setup becomes a guided sequence with one primary purpose per step:

1. **Choose context**
   - Select starter pack.
   - Explain what the starter pack supplies.
   - Show the current lifecycle stage.

2. **Define qualification**
   - Business description.
   - Services/products.
   - Intended audiences.
   - Primary opportunity definition.
   - Exclusions.
   - Keep related qualification fields together rather than auto-fitting unrelated controls.

3. **Choose AI fields**
   - Present the AI whitelist as grouped, explainable choices.
   - Include a visible privacy callout describing excluded identity, attribution, consent, secrets, files, and `cf7aiv_` fields.
   - Keep advanced policy JSON behind an Advanced disclosure.

4. **Review draft**
   - Show a read-only summary of selected pack, business context, qualification rules, exclusions, whitelist, and evidence requirements.
   - Provide the primary **Save guided draft** action.
   - Keep **Enter Testing** separate and explain that it does not enable delivery.

5. **Test and finish**
   - Show links to Integrations, Policy Lab, Review Queue, and Tracking Health.
   - Show current provider/destination safety summary.
   - Provide the next recommended action rather than another dense form.

Desktop Setup uses a compact progress rail or step navigation. Mobile uses a horizontal or stacked progress indicator. Each step has one primary action and a secondary back action. Draft persistence remains server-side and nonce-protected.

### Integrations

Integrations becomes a tabbed dashboard with a persistent context header:

- Current lifecycle stage.
- Automation mode.
- Selected AI provider.
- Destination safety summary.
- Unsaved/saved state.

Tabs:

1. **AI provider**
2. **Google**
3. **Microsoft**
4. **Webhook**
5. **Consent & privacy**

Advanced-only content remains progressively disclosed inside the relevant tab rather than appearing globally.

## Integrations Interaction Design

### Global save

All ordinary provider, destination, consent, privacy, retention, and interface settings submit through one global save action.

A sticky bottom action bar appears while editing:

- **Save changes** — primary action.
- **Discard changes** — secondary action, with confirmation if changes exist.
- Status text: Saved, Unsaved changes, Saving, Saved just now, or Save failed.

The save action must be available at the top and bottom on long desktop pages, and remain reachable on mobile without obscuring form controls.

### High-risk actions

These remain separate forms/actions and must not be triggered by ordinary Save changes:

- Validate GA4 debug endpoint.
- Send controlled provider/destination transport test.
- Publish policy.
- Publish mapping.
- Enable mapping.
- Enable automation live.
- Enable GA4 live.
- Schedule historical calibration.

High-risk controls use distinct warning/confirmation styling and retain individual nonces, server-side guards, and explicit copy describing their effect.

### Conditional provider controls

The selected provider controls visible fields:

- DeepSeek selected:
  - DeepSeek model.
  - DeepSeek base URL.
  - DeepSeek API key.
  - DeepSeek reasoning effort.
  - DeepSeek capability note.
- OpenAI selected:
  - OpenAI model.
  - OpenAI base URL.
  - OpenAI API key.
  - OpenAI reasoning effort where supported.
  - OpenAI capability note.

Irrelevant provider controls are hidden from the active form and excluded from submitted settings where practical. Switching provider must not delete the other provider's saved settings; returning to that provider restores them.

## Reasoning Controls

Reasoning is configurable per provider and distinct from optional second-call verification.

Proposed stored values:

- `off`
- `standard`
- `deep`
- `unsupported` where a selected model does not expose a compatible control

The UI uses the label **Reasoning effort** and explains provider-specific behavior.

### DeepSeek mapping

- `off` → `thinking.type = disabled`.
- `standard` → `thinking.type = enabled` with a bounded standard output/reasoning budget.
- `deep` → `thinking.type = enabled` with a higher bounded budget.

The implementation must reserve enough output budget for the required structured JSON response. Reasoning controls must not allow unbounded token requests.

### OpenAI mapping

- Use the selected model's supported reasoning parameter only when the adapter and model support it.
- If unsupported, render the control disabled with an explanation rather than sending an invented parameter.
- Preserve `store: false` and strict structured output behavior.

### Cost and verification disclosure

The UI explains that:

- higher reasoning effort can increase token use and cost;
- optional automatic-candidate verification is a separate second provider call;
- reasoning effort applies to the provider call itself and does not override grounded policy, consent, attribution, mapping, lifecycle, or delivery gates.

Provider usage diagnostics distinguish input, output, reasoning tokens, and call count.

## Visual System

Use the existing plugin visual language, refined into a consistent system:

- Strong page header with title, purpose, lifecycle/safety badges, and next action.
- Cards represent one domain only.
- Fieldsets group directly related controls.
- Consistent internal spacing tokens.
- Full-width fields when a value needs context or prose.
- Two-column grids only for genuinely paired short fields.
- Descriptions sit immediately beneath the relevant field or group.
- Primary action styling is reserved for the dominant action per screen.
- Warning styling is reserved for live transitions, deletion, legal overrides, and external sends.
- Tables remain locally scrollable and are not allowed to create page-level overflow.
- Avoid raw JSON, long unstructured prose, and repeated explanatory paragraphs.

## Accessibility and Responsive Requirements

Every redesigned screen must satisfy:

- semantic headings with one clear page `h1`;
- labels associated with every non-hidden control;
- visible keyboard focus;
- fieldsets/legends for related controls;
- status messages exposed through appropriate live/status semantics;
- no color-only state communication;
- no unexpected horizontal overflow at 1440px, 1024px, or 390px;
- tables remain usable through labelled local scroll regions where tabular content cannot reflow;
- sticky save bars do not cover focused controls or mobile keyboard input;
- reduced-motion and forced-colour behavior remain safe.

## Data, Safety, and Compatibility

- Preserve existing option keys where possible.
- Add provider reasoning settings through defaults and sanitization without exposing secrets.
- No database migration is required unless implementation proves one unavoidable; prefer schema-free settings changes.
- Preserve encrypted write-only secret storage.
- Preserve current queue, Consent Mode, attribution, idempotency, review, mapping, and lifecycle behavior.
- Preserve server-side nonces and capability checks.
- Do not make ordinary settings saves enable live automation or publish mappings.
- Do not make reasoning settings a dispatch or eligibility threshold.

## Testing Acceptance Criteria

### Repository

- All existing framework-free PHP tests pass.
- New tests cover settings defaults/sanitization for reasoning effort.
- New tests cover DeepSeek payload mapping for off/standard/deep.
- New tests cover OpenAI supported/unsupported reasoning handling.
- New tests cover conditional provider rendering and preservation of inactive provider settings.
- Non-vendor PHP lint, JavaScript tests, JavaScript syntax checks, and release packaging pass.

### Playwright/admin

At desktop, tablet, and mobile:

- Setup steps render in the intended order with related fields grouped.
- Setup draft save works and reports status.
- Integrations tabs work and preserve selected tab state after validation errors or save redirects where applicable.
- Selecting DeepSeek hides OpenAI fields; selecting OpenAI hides DeepSeek fields.
- Reasoning controls update the correct provider settings and show capability/cost copy.
- Save changes becomes active after edits, persists values, and restores the saved state.
- Discard changes restores the last saved values without a request when possible.
- High-risk controls remain visibly separate from Save changes.
- Nonce and accessibility audits remain clean.
- No plugin-specific console errors or unexpected request failures occur.
- Screenshots are captured for visual review.

### End-to-end integration

- Public visitor form remains functional at desktop and mobile.
- Consent-denied and consent-granted attribution paths remain correct.
- Synthetic visitor submission reaches CFDB7 and the validator queue.
- Safe shadow/dry-run queue processing remains functional.
- Existing configured DeepSeek and GA4 validation paths remain green.
- Synthetic records and temporary test endpoints are removed after testing.

## Rollout

1. Implement on a feature branch from the current release branch.
2. Run repository tests/lint/package checks.
3. Deploy the exact verified build to staging with a remote backup.
4. Run Playwright visual and interaction checks at all target viewports.
5. Run reversible settings and provider-switch checks.
6. Review screenshots and diagnostics.
7. Merge and release only after all acceptance criteria pass.
