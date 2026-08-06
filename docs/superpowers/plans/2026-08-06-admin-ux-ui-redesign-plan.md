# CF7 AI Validator Admin UX/UI Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the dense CF7 AI Validator admin experience with a guided Setup flow, conditional tabbed Integrations dashboard, visible save states, and provider-capability-aware reasoning controls without weakening existing safety or delivery behavior.

**Architecture:** Keep WordPress-rendered PHP as the source of truth and use narrowly scoped admin JavaScript only for tab switching, conditional field visibility, dirty-state tracking, and discard behavior. Preserve existing `admin-post.php` handlers, nonces, encrypted write-only secrets, lifecycle transitions, and schema version 10. Add provider reasoning settings to the existing settings tree and map them inside provider adapters with bounded provider-specific payloads.

**Tech Stack:** PHP 8.1+, WordPress admin HTML/PHP, scoped CSS in `assets/css/admin.css`, vanilla JavaScript in a new `assets/js/admin.js`, framework-free PHP tests, Node/Playwright browser scripts using the existing project Playwright dependency.

## Global Constraints

- Treat WordPress 6.5+/PHP 8.1+ as the supported runtime.
- Preserve the existing queue, Consent Mode, attribution, idempotency, review, mapping, and lifecycle behavior.
- Preserve encrypted write-only secret storage; never render or log stored secrets.
- Ordinary Save changes must not publish mappings, enable live automation, enable GA4 live delivery, or send transport tests.
- High-risk actions retain separate forms, nonces, capability checks, and explicit confirmation copy.
- Reasoning effort is not an eligibility, dispatch, consent, attribution, or mapping threshold.
- Use provider capability-aware controls; never send unsupported reasoning parameters to an API.
- No database migration is required; add settings through `Settings::defaults()` and sanitization.
- No global CSS selectors, `!important`, or page-level overflow hacks.
- Do not add comments to production code unless explicitly required by an existing project convention.
- Run tests/lint/build checks before claiming a task is complete.

---

### Task 1: Add reasoning settings and provider capability contracts

**Files:**
- Modify: `src/Infrastructure/Settings.php`
- Modify: `src/Admin/SettingsSanitizer.php`
- Modify: `src/Providers/DeepSeekProvider.php`
- Modify: `src/Providers/OpenAIProvider.php`
- Modify: `src/Classification/ClassificationHooks.php`
- Modify: `src/Admin/IntegrationsPage.php`
- Test: `tests/IntegrationTest.php`
- Test: `tests/ProviderTest.php` (create)

**Interfaces:**
- Settings keys:
  - `providers.deepseek_reasoning_effort`: `off|standard|deep`
  - `providers.openai_reasoning_effort`: `off|standard|deep|unsupported`
- Provider constructors accept the existing settings-derived reasoning value without changing public provider interface methods.
- Add provider capability helpers with exact signatures:

```php
public static function reasoningOptions(string $model): array;
public static function reasoningDescription(string $model, string $effort): string;
```

- Provider payload behavior:
  - DeepSeek `off` sends `thinking.type=disabled`.
  - DeepSeek `standard`/`deep` send `thinking.type=enabled` with bounded output budgets that leave room for structured JSON.
  - OpenAI sends the supported reasoning field only for models/capabilities that support it; unsupported combinations omit the field and render as unavailable.

- [ ] **Step 1: Write failing settings and payload tests**

Add assertions to `tests/ProviderTest.php` for defaults, sanitization, and fake transports:

```php
$defaults = CF7AIV\Infrastructure\Settings::defaults();
provider_assert($defaults['providers']['deepseek_reasoning_effort'] === 'off', 'DeepSeek reasoning defaults off.');
provider_assert($defaults['providers']['openai_reasoning_effort'] === 'unsupported', 'OpenAI unsupported reasoning defaults honestly.');

$sanitized = CF7AIV\Admin\SettingsSanitizer::sanitize([
    'providers' => [
        'deepseek_reasoning_effort' => 'deep',
        'openai_reasoning_effort' => 'invalid',
    ],
]);
provider_assert($sanitized['providers']['deepseek_reasoning_effort'] === 'deep', 'DeepSeek reasoning effort is retained.');
provider_assert($sanitized['providers']['openai_reasoning_effort'] === 'unsupported', 'Invalid OpenAI reasoning effort fails closed.');
```

Use the existing fake HTTP transport pattern to decode DeepSeek and OpenAI request bodies and assert that the reasoning field is present/absent as specified. Ensure API keys and enquiry identity do not appear in assertions or output.

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```powershell
php tests/ProviderTest.php
```

Expected: FAIL because reasoning settings and provider payload mappings do not exist.

- [ ] **Step 3: Implement settings defaults and sanitization**

Add the two provider settings to `Settings::defaults()`. In `SettingsSanitizer::sanitize()`, accept only the exact allowed values and fail invalid values to the provider’s safe default. Do not add reasoning settings to `secrets()`.

- [ ] **Step 4: Implement DeepSeek reasoning mapping**

Read the selected effort from the settings resolver in the provider construction path. Add a bounded mapping in `DeepSeekProvider`:

```php
$thinking = match ($reasoningEffort) {
    'standard', 'deep' => ['type' => 'enabled'],
    default => ['type' => 'disabled'],
};
$payload['thinking'] = $thinking;
$payload['max_tokens'] = $reasoningEffort === 'deep' ? 1600 : 900;
```

Keep structured JSON instructions intact. Do not expose arbitrary token budgets through the admin yet.

- [ ] **Step 5: Wire settings into provider construction**

Modify `src/Classification/ClassificationHooks.php::registerProviders()` so each provider receives its sanitized reasoning effort from `Settings::withDefaults()`:

```php
(string) ($settings['providers']['deepseek_reasoning_effort'] ?? 'off')
(string) ($settings['providers']['openai_reasoning_effort'] ?? 'unsupported')
```

Extend the provider constructors with an optional final reasoning-effort parameter so existing test and extension callers remain valid.

- [ ] **Step 6: Implement OpenAI capability-aware mapping**

Add a model capability check. For unsupported models, omit reasoning parameters and return `unsupported` in the UI capability data. For supported models, use the provider’s documented reasoning parameter and retain `store=false`, strict JSON schema, and bounded output.

- [ ] **Step 7: Add provider UI capability data**

Update `IntegrationsPage` to expose the selected provider, model, allowed reasoning options, and capability copy to the rendered page. Keep this server-derived and escaped.

- [ ] **Step 7: Run focused tests and full PHP suite**

Run:

```powershell
php tests/ProviderTest.php
foreach ($file in Get-ChildItem tests -Filter '*Test.php' -File) { php $file.FullName }
```

Expected: focused provider tests and all existing tests pass.

- [ ] **Step 8: Commit**

```powershell
git add src/Infrastructure/Settings.php src/Admin/SettingsSanitizer.php src/Providers/DeepSeekProvider.php src/Providers/OpenAIProvider.php src/Admin/IntegrationsPage.php tests/IntegrationTest.php tests/ProviderTest.php
git commit -m "feat: add provider reasoning controls"
```

---

### Task 2: Build the guided Setup step structure

**Files:**
- Modify: `src/Admin/SetupPage.php`
- Modify: `src/Admin/AdminHooks.php` only if step-specific return routing is required
- Modify: `assets/css/admin.css`
- Modify: `assets/js/admin.js` (create)
- Test: `tests/AdminTest.php`
- Test: `tests/AdminUiTest.php`

**Interfaces:**
- Render a stable root: `.cf7aiv-admin.cf7aiv-setup-page`.
- Step containers use `.cf7aiv-setup-step[data-step="context|qualification|fields|review|finish"]`.
- Progress items use `.cf7aiv-setup-progress` and expose `aria-current="step"`.
- Each step has one primary action and a secondary back action.
- Existing handlers remain `cf7aiv_setup_scan`, `cf7aiv_save_guided_policy`, `cf7aiv_lifecycle_transition`, and `cf7aiv_backfill`.

- [ ] **Step 1: Write failing rendering tests**

Add assertions to `tests/AdminTest.php`/`tests/AdminUiTest.php` that Setup output contains:

```php
admin_assert(str_contains($setup, 'cf7aiv-setup-page'), 'Setup needs a dedicated root.');
admin_assert(str_contains($setup, 'cf7aiv-setup-progress'), 'Setup needs progress navigation.');
admin_assert(substr_count($setup, 'cf7aiv-setup-step') >= 5, 'Setup needs five guided steps.');
admin_assert(str_contains($setup, 'Save guided draft'), 'Setup needs a clear draft-save action.');
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
php tests/AdminTest.php
php tests/AdminUiTest.php
```

Expected: FAIL because the current Setup output is a sequence of unrelated cards without guided step structure.

- [ ] **Step 3: Refactor SetupPage rendering into five semantic steps**

Keep existing form names and nonce actions. Group fields as:

- context: pack selection and stage summary;
- qualification: business, services, audiences, primary definition, exclusions;
- fields: whitelist and privacy callout;
- review: read-only summary and draft save;
- finish: next actions, safety summary, lifecycle transition, and optional calibration.

Use one-column prose fields and grouped short controls. Do not duplicate settings or create a second persistence model.

- [ ] **Step 4: Add setup step navigation behavior**

Create scoped vanilla JS that:

- activates one step at a time;
- updates `aria-current` and focus heading on step changes;
- prevents advancing when required visible fields are invalid;
- does not hide server-side validation errors;
- works without JavaScript by rendering the complete step sequence and server actions.

- [ ] **Step 5: Add Setup CSS**

Add only `.cf7aiv-setup-page`-prefixed rules for progress rail, step cards, grouped fields, summary panels, and mobile stacking. Use existing spacing tokens.

- [ ] **Step 6: Run tests and lint**

Run:

```powershell
php tests/AdminTest.php
php tests/AdminUiTest.php
node --check assets/js/admin.js
```

- [ ] **Step 7: Commit**

```powershell
git add src/Admin/SetupPage.php src/Admin/AdminHooks.php assets/css/admin.css assets/js/admin.js tests/AdminTest.php tests/AdminUiTest.php
git commit -m "feat: restructure setup as guided workflow"
```

---

### Task 3: Convert Integrations into tabbed conditional settings

**Files:**
- Modify: `src/Admin/IntegrationsPage.php`
- Modify: `src/Admin/AdminHooks.php` only if save redirect/tab preservation needs a server-side return parameter
- Modify: `assets/css/admin.css`
- Modify: `assets/js/admin.js`
- Modify: `tests/AdminTest.php`
- Modify: `tests/AdminUiTest.php`

**Interfaces:**
- Root: `.cf7aiv-admin.cf7aiv-integrations-page`.
- Tabs: `.cf7aiv-integration-tabs` with buttons/links carrying `data-tab` and `aria-controls`.
- Panels: `.cf7aiv-integration-tab-panel[data-tab="provider|google|microsoft|webhook|consent"]`.
- Save bar: `.cf7aiv-save-bar` with `data-dirty`, `data-save-state`, and buttons named `cf7aiv-save`/`cf7aiv-discard` where client-only discard is safe.
- Keep one ordinary `cf7aiv_save_settings` POST containing all ordinary settings.

- [ ] **Step 1: Write failing UI tests**

Add assertions that Integrations renders:

```php
admin_assert(str_contains($integrations, 'cf7aiv-integrations-page'), 'Integrations needs a dedicated root.');
admin_assert(str_contains($integrations, 'cf7aiv-integration-tabs'), 'Integrations needs tab navigation.');
admin_assert(str_contains($integrations, 'cf7aiv-save-bar'), 'Integrations needs a persistent save bar.');
admin_assert(str_contains($integrations, 'data-provider="deepseek"'), 'DeepSeek controls need a provider scope.');
admin_assert(str_contains($integrations, 'data-provider="openai"'), 'OpenAI controls need a provider scope.');
```

- [ ] **Step 2: Run focused tests to verify failure**

```powershell
php tests/AdminTest.php
php tests/AdminUiTest.php
```

Expected: FAIL because current Integrations renders one long form with no tab/save-bar structure.

- [ ] **Step 3: Split Integrations into tab panels**

Render ordinary settings in one global form. Move:

- provider settings and reasoning into AI provider;
- GA4 and optional sGTM into Google;
- Microsoft fields/test into Microsoft;
- webhook fields into Webhook;
- consent, retention, enhanced matching, uninstall into Consent & privacy.

Keep mappings and lifecycle controls in the Google tab under a clearly marked “High-risk actions” region. Keep transport tests as separate forms.

- [ ] **Step 4: Implement conditional provider rendering**

Render provider panels with stable `data-provider` attributes and a server-selected active state. The JS toggles visibility and `aria-hidden`; server output must still contain valid fields for the selected provider if JS is unavailable. Do not submit hidden inactive fields as active values where this would overwrite settings.

- [ ] **Step 5: Add global save bar and dirty state**

In `admin.js`:

- snapshot form values on load;
- mark dirty after any ordinary control change;
- enable Save changes only when dirty;
- make Discard restore the initial snapshot without submitting;
- update status text accessibly;
- preserve the active tab in the URL/query or hidden return field.

On server redirect, show a saved/failed notice and return to the active tab.

- [ ] **Step 6: Add Integrations styling**

Use scoped CSS for tab navigation, status badges, save bar, grouped fieldsets, high-risk sections, and mobile stacking. Remove reliance on one wide auto-fit grid for unrelated controls.

- [ ] **Step 7: Run focused tests and lint**

```powershell
php tests/AdminTest.php
php tests/AdminUiTest.php
node --check assets/js/admin.js
```

- [ ] **Step 8: Commit**

```powershell
git add src/Admin/IntegrationsPage.php src/Admin/AdminHooks.php assets/css/admin.css assets/js/admin.js tests/AdminTest.php tests/AdminUiTest.php
git commit -m "feat: reorganize integrations dashboard"
```

---

### Task 4: Add save/error/status affordances and provider-specific reasoning UX

**Files:**
- Modify: `src/Admin/IntegrationsPage.php`
- Modify: `src/Admin/SetupPage.php`
- Modify: `src/Admin/SettingsSanitizer.php`
- Modify: `assets/js/admin.js`
- Modify: `assets/css/admin.css`
- Test: `tests/IntegrationTest.php`
- Test: `tests/AdminTest.php`

**Interfaces:**
- Reasoning UI fields:
  - `cf7aiv[providers][deepseek_reasoning_effort]`
  - `cf7aiv[providers][openai_reasoning_effort]`
- Save statuses are present in HTML and updated by JS with `role="status"`.
- High-risk controls use existing action handlers and remain separate forms.

- [ ] **Step 1: Write failing tests for field visibility and sanitized reasoning**

Assert that:

- DeepSeek output contains only DeepSeek fields in the active provider panel.
- OpenAI output contains only OpenAI fields in its active panel.
- Both inactive provider settings are retained server-side when switching back.
- Invalid reasoning effort sanitizes to the safe provider default.
- Ordinary settings HTML includes a global save action and distinct high-risk action labels.

- [ ] **Step 2: Run focused tests to verify failure**

```powershell
php tests/IntegrationTest.php
php tests/AdminTest.php
```

- [ ] **Step 3: Implement capability-aware reasoning controls**

Render effort controls and descriptions from provider capability helpers. Include cost/token warnings and distinguish normal reasoning from optional automatic-candidate verification.

- [ ] **Step 4: Implement save notices and inline error placement**

Ensure server redirects carry success/failure state without exposing exception details or secrets. Place validation messages beside the relevant group and retain the active tab.

- [ ] **Step 5: Run all PHP tests and lint**

```powershell
foreach ($file in Get-ChildItem tests -Filter '*Test.php' -File) { php $file.FullName }
$files = Get-ChildItem -Recurse -Filter '*.php' -File | Where-Object { $_.FullName -notmatch '\\vendor\\' }
foreach ($file in $files) { php -l $file.FullName }
```

- [ ] **Step 6: Commit**

```powershell
git add src/Admin/IntegrationsPage.php src/Admin/SetupPage.php src/Admin/SettingsSanitizer.php assets/js/admin.js assets/css/admin.css tests/IntegrationTest.php tests/AdminTest.php
git commit -m "feat: add provider-aware reasoning and save states"
```

---

### Task 5: Add Playwright UX regression coverage

**Files:**
- Create: `tests/wordpress/ux-admin.spec.ts`
- Modify: `tests/wordpress/package.json` only if the existing test harness needs a script
- Create: `tests/wordpress/playwright.config.ts` if no config exists
- Test data: use existing staging-safe synthetic values and restore all changed settings

**Interfaces:**
- Base URL and authenticated state come from existing project test conventions or explicit environment variables.
- Tests must not submit live destination events or publish mappings.
- Screenshots go to a test output directory ignored by Git.

- [ ] **Step 1: Write failing Playwright tests**

Add tests for:

```ts
for (const viewport of [{ width: 1440, height: 1000 }, { width: 1024, height: 900 }, { width: 390, height: 844 }]) {
  test(`Setup has grouped steps at ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await page.goto('/wp-admin/admin.php?page=cf7aiv-setup');
    await expect(page.locator('.cf7aiv-setup-progress')).toBeVisible();
    await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  });
}

test('provider switch hides irrelevant fields', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=cf7aiv-integrations');
  await page.getByLabel('Provider').selectOption('openai');
  await expect(page.locator('[data-provider="deepseek"]')).toBeHidden();
  await expect(page.locator('[data-provider="openai"]')).toBeVisible();
});

test('ordinary save is distinct from high-risk actions', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=cf7aiv-integrations');
  await expect(page.getByRole('button', { name: 'Save changes' })).toBeVisible();
  await expect(page.getByRole('button', { name: /Enable live|Send controlled|Publish/ }).first()).toBeVisible();
});
```

Add tests for dirty/save/discard state, reasoning control visibility, labels/nonces, no overflow, screenshot capture, and public contact form rendering.

- [ ] **Step 2: Run Playwright tests to verify the current UI fails**

Run:

```powershell
npx playwright test tests/wordpress/ux-admin.spec.ts
```

Expected: current UI fails on missing progress/tabs/save-bar/conditional-field assertions.

- [ ] **Step 3: Implement or refine browser behavior until tests pass**

Use resilient role/label locators, avoid brittle visual coordinates, record console errors and failed requests, and preserve/revert all settings altered by tests.

- [ ] **Step 4: Run the full Playwright UX suite**

Run desktop, tablet, mobile, authenticated admin, logged-out redirect, public contact, consent-denied, and consent-granted cases. Store screenshots and review them visually.

- [ ] **Step 5: Commit**

```powershell
git add tests/wordpress

git commit -m "test: cover redesigned admin ux with Playwright"
```

---

### Task 6: Run integrated staging verification and release checks

**Files:**
- Modify: documentation only if test commands or UI behavior changed materially.
- Test: repository suite, Playwright suite, staging WP-CLI/web runtime.

**Interfaces:**
- Deploy the exact built ZIP, not an untracked source directory.
- Preserve a remote backup before replacing staging files.
- Keep automation in shadow and destinations in dry-run.

- [ ] **Step 1: Run repository verification**

```powershell
foreach ($file in Get-ChildItem tests -Filter '*Test.php' -File) { php $file.FullName }
node tests/AttributionJsTest.js
node --check assets/js/attribution.js
$files = Get-ChildItem -Recurse -Filter '*.php' -File | Where-Object { $_.FullName -notmatch '\\vendor\\' }
foreach ($file in $files) { php -l $file.FullName }
php tools/build-release.php --tag=v0.2.18 --output-dir=dist
```

Expected: zero failures, valid canonical ZIP, metadata agreement, and no credential-like distributable content.

- [ ] **Step 2: Deploy the verified ZIP to staging with backup**

Back up the current plugin directory, upload the ZIP, replace only the plugin directory, verify active version, schema, shadow mode, dry-run destinations, and no captured-unclassified backlog.

- [ ] **Step 3: Run staging runtime checks**

Run through the PHP 8.3 web runtime if the staging CLI PHP is below the plugin minimum:

- `doctor` report;
- isolated pipeline;
- configured DeepSeek pipeline where credentials are available;
- GA4 validation;
- dry-run destination checks;
- safe queue pass;
- per-item diagnosis;
- invalid nonce returns HTTP 403.

- [ ] **Step 4: Run front-end and admin Playwright checks**

Verify all redesigned pages at desktop/tablet/mobile, provider switching, reasoning controls, save/discard, setup steps, public CF7 attribution, and no plugin-specific console/network failures.

- [ ] **Step 5: Clean synthetic records and temporary endpoints**

Delete only test rows matched by exact marker/ID guards. Verify zero remaining synthetic records and no temporary runner files.

- [ ] **Step 6: Commit verification evidence and release only after green results**

Record the exact test commands, screenshots, staging version, and remaining unrelated warnings in the release evidence. Do not claim comprehensive success if any acceptance item remains unresolved.
