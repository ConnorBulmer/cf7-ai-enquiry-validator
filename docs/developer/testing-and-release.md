# Testing and releases

## Fast tests (run before every commit)

```bash
# Every PHP test (each file exits non-zero on the first failure)
for f in tests/*Test.php; do php "$f" || break; done

# Attribution script
node tests/AttributionJsTest.js
node --check assets/js/attribution.js

# Lint everything outside vendor/
find . -path './vendor' -prune -o -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l
```

These need only PHP 8.1+ and Node.js. There is no Composer, PHPUnit or npm
install. CI runs exactly these steps (`.github/workflows/release.yml`).

### How the PHP tests work

Each `tests/*Test.php` file is a standalone script:

- It registers the same `CF7AIV\` → `src/` autoloader the plugin uses.
- It defines **fake WordPress functions** it needs (`get_option`,
  `update_option`, `apply_filters`, a fake `$wpdb` whose `get_var`,
  `get_row`, `query` and so on return scripted values, a fake Action
  Scheduler, and so on).
- It calls real classes and checks results with a small local assert
  function, which throws `RuntimeException('FAIL: …')`.
- It prints `PASS <Name>` at the end.

This is why the production code guards WordPress calls with `function_exists`
and takes injectable callables: the tests depend on both. Some tests also read
source files and check for required patterns (for example
`tests/IntegrationTest.php` checks `attribution.js`, the docs and the README).

| Test file | Covers |
| --- | --- |
| `FoundationTest`, `MigrationV9Test`, `UninstallTest` | Settings, schema, migrations, uninstall |
| `IntakeTest`, `CaptureConsentHashRegressionTest`, `CookieRegressionTest`, `ConsentRegressionTest`, `DedupRegressionTest` | Capture, attribution, consent, fingerprints, hashes |
| `ClassificationTest`, `PolicyV2Test`, `GroundedEvidenceTest`, `VerificationPassTest`, `CalibrationV2Test`, `CalibrationRunLifecycleTest` | Worker, policies, evidence, verification, calibration |
| `ProviderTest`, `ProviderTokenBudgetTest`, `FailureDiagnosticTest` | DeepSeek/OpenAI requests, budgets, timeouts, error messages |
| `DeliveryTest`, `DeliveryReviewRegressionTest`, `ApprovalAtomicityTest`, `GA4TestServiceTest`, `MicrosoftUetTestServiceTest` | Delivery gates, destinations, approval audit |
| `QueueTest` | De-duplication against running and pending actions |
| `AdminTest`, `AdminUiTest`, `AdminHandlerGuardTest`, `AdminPrivacyTest`, `ReviewScreenTest`, `VisibleStateTest`, `SecretFieldStateTest`, `AssistedSetupTest`, `AccuracyTestTest`, `CorrectionExamplesTest`, `EventLogTest`, `DiagnosticsTest` | Screens, handlers, guard, review, Policy Lab, setup, logs |
| `IntegrationTest`, `PipelineSelfTestTest` | Cross-module behaviour, required docs topics |
| `ReleasePackageTest` | The release packager and the version identity |

### Writing a test

Copy the structure of a nearby test of the same area. Fake only what the code
under test calls. When a fake `$wpdb` method serves several queries, branch on
the SQL (for example, return `0` for `COUNT(*)` queries) so one scripted value
does not leak into an unrelated query. A new file named `tests/<Name>Test.php`
is picked up automatically by the loop above and by CI.

`AttributionJsTest.js` runs `assets/js/attribution.js` against a minimal fake
`window`/`document`/`localStorage`. Each case sets `location.search`, the
consent functions and cookies, then fires events.

## WordPress portability matrix (optional, slower)

`tests/wordpress/` runs the real plugin in Docker against real WordPress,
Contact Form 7 and Flamingo or CFDB7:

- `run-matrix.ps1` (PowerShell): for each cell (WordPress 6.9/PHP 8.1 and
  WordPress 7.0.2/PHP 8.3, each with Flamingo and with CFDB7) it brings up
  `docker-compose.yml` and runs `bootstrap.ps1`/`bootstrap.php` (installs
  plugins, creates fixture forms). It then runs `runtime-assertions.php`
  through WP-CLI, followed by the Playwright specs (`portability.spec.js`,
  `ux-admin.spec.ts`).
- `mu-plugins/cf7aiv-portability-harness.php` is loaded only in that
  environment.

Use it for changes to capture, CF7 integration, admin UX or anything
version-sensitive.

## Isolated checks on a real site

These are safe to run on staging or production:

- `wp cf7aiv pipeline_test` runs capture → classification → eligibility →
  delivery → idempotency in memory with a deterministic classifier
  (`Integration\PipelineSelfTest`). It never touches stored items or
  destinations. `--use-configured-ai` uses the real provider for the
  classification step.
- `wp cf7aiv dry_run` prints the safety report (`OperationalSafety`) and
  sends nothing.
- `wp cf7aiv ga4_test --mode=validate` sends a test event to the GA4 debug
  endpoint only.
  - `--mode=transport --confirm=SEND_ZERO_VALUE_TEST` sends one zero-value
    live test event, after strict validation.
- `wp cf7aiv microsoft_test --confirm=SEND_ZERO_VALUE_MICROSOFT_TEST` sends
  one zero-value Microsoft event.

## Versioning

Bump the version in four places:

1. the `Version:` header in `cf7-ai-enquiry-validator.php`;
2. `const VERSION` in the same file;
3. `Stable tag:` in `readme.txt`;
4. the expected version in `tests/ReleasePackageTest.php`, which fails until
   it matches.

`ReleasePackager::validateMetadata()` refuses to build when the first three
and the Git tag (`vX.Y.Z`) disagree. Add a `CHANGELOG.md` entry for every
release, and a `readme.txt` changelog line.

## Building a release

```bash
php tools/build-release.php --tag=v0.2.36 --output-dir=dist
```

`tools/release-lib.php`:

- checks the tag format and the version agreement;
- scans distributable text for credential-like content;
- builds `cf7-ai-enquiry-validator-<version>.zip` with one root folder;
- checks that every path is safe on Windows;
- writes a `.sha256` file.

**Excluded from the ZIP:** `.git*`, `.github`, `tests`, `tools`,
`docs/superpowers`, `VERIFICATION.md`, `dist`, `node_modules`, `.env*`, logs
and caches. Everything else, including `docs/` and `vendor/`, ships.
`.gitattributes` mirrors these exclusions for `git archive`.

## Publishing on GitHub

Pushing a tag `vX.Y.Z` runs `.github/workflows/release.yml`. It:

1. checks out the tag;
2. runs every PHP and JS test, the JS syntax check and PHP lint;
3. builds and verifies the ZIP;
4. attaches the ZIP and its checksum to a GitHub Release.

You can rerun it for an existing tag from the Actions tab
(`workflow_dispatch`).

```bash
git tag v0.2.36
git push origin v0.2.36
```

Never commit credentials, `.env` files or built ZIPs.
