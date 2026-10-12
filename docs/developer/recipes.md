# Recipes

Checklists for common changes. Each one lists every place that has to change
together. Most bugs in this codebase's history came from changing one of them
and missing another.

Before you start, read the guide for the area. After you finish, run the fast
checks in [Testing and releases](testing-and-release.md).

## Add a setting

1. **Default.** Add it to `Settings::defaults()`. `withDefaults()` then fills
   it in for existing sites automatically.
2. **Sanitize.** Add it to `SettingsSanitizer::sanitize()` (clamp numbers,
   whitelist enums, cast booleans with `self::bool`).
3. **Merge.** Make sure `AdminHooks::saveSettings` copies the section into
   `$next`. It merges only the sections present in the post (`ui`,
   `providers`, `privacy`, `automation`, `destinations`).
4. **Verify the save.** Add the field to
   `AdminHooks::settingsSaveSucceeded()`. The handler re-reads the option
   after saving and reports failure if what was stored differs from what was
   sent.
5. **Field.** Add the form field to the right page (usually
   `IntegrationsPage`), with the name `cf7aiv[section][key]`.
6. **Existing sites.** If they need a *different* value from the new default,
   add a step to `Settings::upgrade()` and bump `Settings::REVISION`. Only
   move values an administrator never chose.
7. **Test.** Extend `tests/FoundationTest.php` (defaults and upgrade) and
   `tests/VisibleStateTest.php` or `tests/AdminTest.php` (save and display).

A value written by a background job does not belong in settings. Give it its
own option (see `Ga4Validation`).

## Add a secret

1. Add the name to the list in `SettingsSanitizer::secrets()`.
2. Read it with `Crypto::secret('<name>')`. That automatically supports a
   `CF7AIV_<NAME>` constant, which takes priority over the stored value.
3. Render a write-only field. Follow `IntegrationsPage::destinationFields` and
   `tests/SecretFieldStateTest.php`: never print the value, only whether one
   is set and whether a constant overrides it.
4. Document the constant in the README's secret-constants list.

## Add an admin action

1. Add `'cf7aiv_<action>' => '<method>'` to `AdminHooks::HANDLERS`. It is then
   registered and guarded automatically.
2. Write `public static function <method>(): void`:

   ```php
   $post = self::post();
   AdminRequestGuard::authorize((string) ($post['_wpnonce'] ?? ''), 'cf7aiv_<nonce_action>');
   // validate; throw InvalidArgumentException with a message for the administrator
   // call a service; queue slow work instead of doing it here
   self::redirect('admin.php?page=cf7aiv-…');
   ```

3. In the form, output `wp_nonce_field('cf7aiv_<nonce_action>')` and
   `<input type="hidden" name="action" value="cf7aiv_<action>">`, posting to
   `admin_url('admin-post.php')`.
4. Add the action to the `$expected` list in `tests/AdminHandlerGuardTest.php`.
   That test requires the registered actions to match the list exactly, each
   registered through the guard, and each answering 403 to an unauthorised
   request (which also proves the method exists). Test the handler's own
   behaviour in the admin test for that area.

## Add a background job

1. Choose a hook name `cf7aiv_<name>`, and register the handler in the
   relevant `*Hooks::register()`.
2. Queue it with `Queue::enqueue()` or `Queue::schedule()`, and check
   `Queue::accepted()`. If the queue refuses, either run the work inline (as
   GA4 validation does) or tell the administrator.
3. Make the handler idempotent and keep its arguments JSON-serialisable (see
   [Queue and background jobs](queue-and-jobs.md#rules-for-job-handlers)).
4. Add it to the jobs table in [Architecture](architecture.md#background-jobs).

## Add an attribution field

See [Intake](intake-and-attribution.md#hidden-fields). In short, change
`FieldNames::map()`, `attribution.js` (collection and consent category in
`filterStored`), `Attribution::fromPosted()`, `AttributionCookie`, and any
destination that sends it (`EnvelopeBuilder::safeAttribution` copies only an
explicit allow-list). Add tests in `tests/AttributionJsTest.js` and
`tests/IntakeTest.php`.

Any new field name starting `cf7aiv_` is automatically blocked from AI.

## Add an AI provider

**From another plugin:** implement `AIProviderInterface` and register it with
the `cf7aiv_ai_providers` filter. See [Extending](../EXTENDING.md#ai-providers).

**Inside this plugin:**

1. Create `src/Providers/<Name>Provider.php` extending `JsonProvider`.
   Implement:
   - `send()`: build the request. Include `UNTRUSTED_INPUT_RULE` and
     `ResultValidator::jsonSchema()`. Use `tokenBudget()` and
     `requestTimeout()`.
   - `extractText()` and `extractUsage()`.
   - `assertComplete()`: throw `PermanentProviderException` for a truncated
     or refused reply.
   - `profile()`, `key()` and `testConnection()`'s transport.

   Map HTTP errors to `TransientProviderException` (0/408/429/5xx) or
   `PermanentProviderException` (the rest). Never put a key or response body
   in an exception message.
2. Build it in `ClassificationHooks::buildProvider()`. Add it to
   `registerProviders()`, the allowed list in `SettingsSanitizer`
   (`providers.selected`), model, URL and reasoning settings and defaults, the
   secret, the `IntegrationsPage` provider section, and the
   `AccuracyTestPanel` provider list.
3. Optionally implement `VerificationCapableProviderInterface` (a second,
   deeper call) and `PolicySuggestionProviderInterface` (setup scan).
4. Tests: copy the shape of `tests/ProviderTest.php` and
   `tests/ProviderTokenBudgetTest.php` with a fake `HttpTransportInterface`.

## Add a destination

**From another plugin:** implement `DestinationReadinessInterface` (which
extends `DestinationInterface`) and register it with `cf7aiv_destinations`.
The interface's `isConfigured()`, `signalsReady()` and `requiredConsent()`
take the place of the settings-based checks below. A destination that
implements only `DestinationInterface` is always gated
`destination_not_configured`. It also needs a published, enabled mapping. See
[Extending](../EXTENDING.md#making-a-registered-destination-sendable).

**Inside this plugin:**

1. Create `src/Destinations/<Name>Destination.php` implementing
   `DestinationInterface`:
   - `send(ConversionEvent): DeliveryResult`.
   - Support dry run by returning `DeliveryResult(false, false, null, null, ['url' => …, 'arguments' => <with secrets redacted>], [], true)`.
   - Classify responses: retryable versus permanent (`ResponseClassifier`,
     or your own).
2. Register it in `DeliveryHooks::destinations()` (only when enabled).
3. Update `DestinationReadiness`: `KEYS`, `configured()` and `signalsReady()`.
4. Set its required consent keys in `DestinationReadiness::requiredConsent()`.
   The delivery worker and `DiagnosticsService` both read them from there.
5. Settings:
   - default `destinations.<key> = ['enabled' => false, 'dry_run' => true]`;
   - add the key to the destination loops in `SettingsSanitizer::sanitize()`,
     `AdminHooks::saveSettings` and `settingsSaveSucceeded()`;
   - add its secret to `SettingsSanitizer::secrets()`.
6. UI: a section in `IntegrationsPage`, and the destination status lists in
   `IntegrationsPage` and `SetupPage`. The mapping form takes the destination
   key as free text (`mapping[destination_key]`), so nothing to add there.
7. If it uses GA identity, update `AttributionDiagnostics::gaIdentityNeeded()`.
8. Tests in `tests/DeliveryTest.php`: dry run, success, retryable failure,
   permanent failure, consent gating, signals gating.

## Add or change a starter pack

1. Edit `assets/policies/packs.json`. Each entry is a full
   `cf7aiv-policy/v2` definition with a `pack` block (`key`, `version`,
   `label`). Bump the pack `version` when you change one.
2. Check it loads: `PolicyPackRegistry::all()` validates every pack with
   `PolicyValidator::importV2()`. `tests/PolicyV2Test.php` loads the packs
   through the registry.
3. Keep prose free of anything `PolicyValidator::assertSafeString()` rejects.
4. Existing sites are unaffected until an administrator builds a new draft
   from the pack. Published policies never change by themselves.

Other plugins can add packs with the `cf7aiv_policy_packs` filter.

## Change the prompt or evidence rules

- **Prompt.** Edit `PolicyCompiler::compile()`. Add a sentence only when it
  applies, so that unaffected policies keep their exact prompt and hash.
  Remember that DeepSeek also receives the guidance from
  `DeepSeekProvider::v2JsonGuidance()`.
- **Answer schema.** Edit `ResultValidator::jsonSchema()` *and* `fromV2()`
  together. OpenAI uses the schema in strict mode, so every property must be
  `required` and `additionalProperties` false.
- **Evidence rules.** Edit `EvidenceEvaluator`. Any change that can produce
  `strong_match` where it could not before needs a test.
- **Measure before shipping.** Run the Policy Lab accuracy test on a staging
  copy with real reviewed enquiries.

## Change the database schema

1. Edit the table in `Schema::definitions()`. `dbDelta` is strict:
   - one column per line;
   - two spaces after `PRIMARY KEY`;
   - `KEY name (col)` syntax;
   - no trailing commas.
2. Bump `Migration::VERSION`.
3. If `dbDelta` cannot make the change, add an `ensure…()` repair in
   `Migration::migrate()` that returns `false` on failure, so the version is
   not written and the migration retries. Examples: changing an index to
   unique, dropping an index, back-filling data.
4. Update every writer and reader: the stores, `RetentionService`, the
   `Privacy` exporter and eraser, `uninstall.php` (new tables), and
   `DiagnosticsService` if it reports the schema.
5. Test with a fake `$wpdb` in the style of `tests/MigrationV9Test.php`, and
   update `tests/UninstallTest.php`.

## Release a new version

1. Bump the four version identities (see
   [Testing and releases](testing-and-release.md#versioning)).
2. Add `CHANGELOG.md` and `readme.txt` changelog entries.
3. Run every check.
4. Commit, then tag `vX.Y.Z` and push the tag.
