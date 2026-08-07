# Task 5 Implementation Report

## Status

DONE_WITH_CONCERNS

## Scope

Added the Playwright browser regression suite without changing production code. The commit contains only:

- `tests/wordpress/playwright.config.ts`
- `tests/wordpress/ux-admin.spec.ts`

Commit: `505ae73` (`test: cover redesigned admin ux with Playwright`)

The suite covers:

- Setup and Integrations at 1440x1000, 1024x900, and 390x844.
- Provider switching and provider-specific reasoning control visibility.
- Ordinary Save changes versus high-risk controls.
- Dirty state, client-only discard, save-button state, and absence of an `admin-post.php` POST.
- Setup progress navigation without form submission.
- Accessible labels, nonce presence, and document-level horizontal overflow.
- Logged-out admin redirect to `wp-login.php`.
- Public Contact Form 7 rendering and attribution field injection without submission.
- Consent-denied and consent-granted read-only attribution checks in isolated browser contexts.
- Screenshot capture and redacted console, page-error, failed-request, and error-response diagnostics.

Authenticated pages use `CF7AIV_AUTH_STATE` or `CF7AIV_STORAGE_STATE` as an administrator storage-state path. The base URL uses `CF7AIV_BASE_URL`. Public checks use `CF7AIV_CONTACT_URL` when supplied, otherwise the existing synthetic `/portability-fixture/` route. Missing authenticated or base URL inputs cause explicit Playwright skips. No credentials are embedded, printed, or generated. Known provider/destination collection endpoints are blocked at the browser route level, and no risky form is submitted.

## TDD Evidence

Initial prescribed command, before dependency installation:

```powershell
npx playwright test tests/wordpress/ux-admin.spec.ts
```

Result: the command could not start because the repository root has no Playwright binary in its `node_modules` path (`'playwright' is not recognized as an internal or external command`). The same root invocation remained unavailable after installing the nested `tests/wordpress` package, because the existing harness keeps its dependency local to that directory.

After installing the pinned dependency, the package-local equivalent was run:

```powershell
npm.cmd ci --prefix tests/wordpress
npx.cmd playwright test ux-admin.spec.ts
```

The first package-local run exposed one test syntax error (`non-GETRequests` was parsed as an invalid expression). That test-only identifier was corrected. The next RED run parsed and executed all 14 browser contracts and reported `14 skipped`; no test was treated as an authenticated administrator success. The skip condition was the absent `CF7AIV_BASE_URL` and authenticated storage-state inputs.

## Verification

Commands and results:

```powershell
npx.cmd playwright test ux-admin.spec.ts --list
```

Result: `Total: 14 tests in 1 file`.

```powershell
npx.cmd playwright test ux-admin.spec.ts
```

Final post-commit result: `Running 14 tests using 1 worker`, followed by `14 skipped`.

```powershell
npx.cmd playwright install chromium
```

Result: exit code 0.

```powershell
node --check assets/js/admin.js
node --check tests/wordpress/portability.spec.js
npm.cmd --prefix tests/wordpress ls --depth=0
git diff --check
```

Result: both JavaScript syntax checks passed, `@playwright/test@1.62.1` resolved from the existing lockfile package, and the diff check passed.

The full package-local Playwright suite was also attempted:

```powershell
npx.cmd playwright test
```

Observed result: 4 pre-existing `portability.spec.js` failures and 14 new-suite skips. The portability failures were caused by `CF7AIV_BASE_URL` being unset, producing `undefined/portability-fixture/`, `undefined/wp-login.php`, and invalid cookie URLs. A direct local Playwright invocation reproduced the same invalid-URL failures, then exceeded the shell timeout because the pre-existing portability tests do not close their contexts after early navigation errors. `portability.spec.js` was not modified.

## Environment Limitations

At verification time these variables were absent:

- `CF7AIV_BASE_URL`
- `CF7AIV_AUTH_STATE`
- `CF7AIV_STORAGE_STATE`
- `CF7AIV_CONTACT_URL`

Therefore no live authenticated admin or public Contact Form 7 browser assertions could run, and no screenshots from real site pages were produced. The suite safely skipped rather than attempting login, creating users, saving settings, publishing mappings, enabling automation, or sending transport tests.

## Concerns

- Real desktop/tablet/mobile visual verification remains pending an explicit base URL and administrator storage-state file.
- Consent and public rendering checks remain pending a reachable Contact Form 7 URL; the default synthetic fixture is created only by the existing portability bootstrap.
- The existing portability suite still contains its legacy test-account login convention and lacks an absent-base-URL guard. It was left untouched because this task adds the new suite and commits only the new test/config files.
- The full Playwright suite cannot be reported green in this environment until the existing portability prerequisites are supplied and its early-error context cleanup issue is addressed separately.

## Review Fix Report

### Status

DONE_WITH_CONCERNS

### Changes

- Updated `tests/wordpress/ux-admin.spec.ts` only; no package or Playwright configuration change was required.
- The denied-consent case now supplies conflicting Google-granted and authoritative WordPress-denied decisions, asserts blank disallowed `cf7aiv_*` fields and storage, and preserves the production `mosaic_consent=not_granted` decision when that field is exposed.
- The granted-consent case now supplies conflicting Google-denied and authoritative WordPress-granted decisions. A browser-context init fixture installs `wp_get_consent_type() === 'optin'` and deterministic `wp_has_consent()` category responses before page scripts, with replacement blocked so CookieYes timing cannot override the test decision.
- Each responsive Setup and Integrations test now visits every exposed step/tab at every target viewport, checks overflow after each state, and asserts that traversal made no non-GET request.
- Public submit-control detection now matches the production submit element without clicking it.

### TDD Evidence

The original test was run against staging before the fix:

```text
ux-admin.spec.ts:380: expect(locator).toHaveValue(expected)
Expected: "denied"
Received: ""
```

After the fix, the two authoritative consent cases passed together (`2 passed`).

### Verification

- Package-local `npx.cmd playwright test ux-admin.spec.ts --list`: 14 tests listed.
- Package-local staging run with the configured staging URL and public Contact Form 7 URL: 4 passed, 10 skipped because administrator storage state was not supplied.
- Package-local no-environment run: 14 skipped safely because the required URL/auth inputs were absent.
- `npm.cmd ls --depth=0`: `@playwright/test@1.62.1`.
- `node --check` passed for `assets/js/admin.js`, `assets/js/attribution.js`, and the untouched `tests/wordpress/portability.spec.js`.
- Existing `node tests/AttributionJsTest.js`: `PASS AttributionJsTest`.
- `git diff --check`: passed.

### Concerns

- Authenticated desktop/tablet/mobile admin coverage remains pending an explicit administrator storage-state file.
- The pre-existing `portability.spec.js` suite was not modified or run as part of this fix, avoiding its credential-based form submissions and high-risk actions.
