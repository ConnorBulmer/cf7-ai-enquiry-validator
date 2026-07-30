# Private GitHub Repository and Release Packaging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish the verified plugin to `ConnorBulmer/cf7-ai-enquiry-validator` as a private repository and automatically attach a correctly rooted WordPress ZIP and SHA-256 checksum to every validated semantic-version release tag.

**Architecture:** Keep the plugin directory as the repository root. A small PHP release library owns tag parsing, version agreement, safe file selection, credential-pattern scanning, ZIP creation and archive verification; both standalone tests and GitHub Actions call that same implementation. GitHub Actions runs the existing test/lint suite before invoking the release packager and publishing assets with GitHub CLI.

**Tech Stack:** PHP 8.1, `ZipArchive`, Node.js 20, GitHub Actions, GitHub CLI, Git.

## Global Constraints

- Repository: private `ConnorBulmer/cf7-ai-enquiry-validator`, default branch `main`.
- Release tags: exact `vMAJOR.MINOR.PATCH` format.
- Version sources: tag, plugin header, `CF7AIV\VERSION`, and `readme.txt` stable tag must agree.
- Archive root: exactly `cf7-ai-enquiry-validator/`.
- Runtime source and bundled Action Scheduler are included.
- `.git`, `.github`, `tests`, `tools`, `docs/superpowers`, `VERIFICATION.md`, local secrets and generated output are excluded.
- The release workflow never calls AI, analytics or advertising APIs.
- Generated ZIPs remain GitHub Release assets and are not committed.

---

### Task 1: Release packager

**Files:**
- Create: `tools/release-lib.php`
- Create: `tools/build-release.php`
- Create: `tests/ReleasePackageTest.php`

**Interfaces:**
- Produces: `CF7AIVRelease\ReleasePackager::build(string $root, string $tag, string $outputDirectory): array`
- Produces: CLI `php tools/build-release.php --tag=v0.2.3 --output-dir=dist`

- [ ] **Step 1: Write the failing release package test**

Test valid/invalid tags, exact metadata agreement, mismatch failure, secret-pattern
failure, one-root ZIP structure, required files, exclusions and checksum:

```php
$result = ReleasePackager::build($root, 'v0.2.3', $temporaryDirectory);
assert($result['version'] === '0.2.3');
assert($result['entries'] > 0);
assert(hash_file('sha256', $result['zip']) === trim(strtok(file_get_contents($result['checksum']), ' ')));
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```text
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/cf7-ai-enquiry-validator/tests/ReleasePackageTest.php
```

Expected: failure because `tools/release-lib.php` does not exist.

- [ ] **Step 3: Implement the release library and CLI**

Implement:

```php
final class ReleasePackager {
    public static function build(string $root, string $tag, string $outputDirectory): array;
    public static function versionFromTag(string $tag): string;
    public static function validateMetadata(string $root, string $version): array;
    public static function distributableFiles(string $root): array;
}
```

Use sorted relative paths, reject symlinks, scan textual distributable files for
API-key/Bearer/private-key patterns, create the ZIP with `ZipArchive`, reopen and
verify it, then write `<zip>.sha256`.

- [ ] **Step 4: Run the release test and verify GREEN**

Run the command from Step 2. Expected: `PASS ReleasePackageTest`.

- [ ] **Step 5: Commit**

```text
git add tools/release-lib.php tools/build-release.php tests/ReleasePackageTest.php
git commit -m "Add reproducible WordPress release packager"
```

### Task 2: GitHub release workflow and repository controls

**Files:**
- Create: `.github/workflows/release.yml`
- Create: `.gitignore`
- Create: `.gitattributes`
- Modify: `tests/ReleasePackageTest.php`

**Interfaces:**
- Consumes: `php tools/build-release.php --tag=<tag> --output-dir=dist`
- Produces: GitHub Release assets `cf7-ai-enquiry-validator-<version>.zip` and `.zip.sha256`

- [ ] **Step 1: Extend the failing test for repository release controls**

Assert the checked-in ignore/export configuration and exercise the real packager
against a root containing `.github`, `tests`, `tools`, `dist` and
`docs/superpowers`, proving none enters the ZIP.

- [ ] **Step 2: Run the test and verify RED**

Expected: failure because `.gitignore`, `.gitattributes` and the workflow are
absent.

- [ ] **Step 3: Implement repository controls and workflow**

The workflow:

```yaml
on:
  push:
    tags: ['v*.*.*']
  workflow_dispatch:
    inputs:
      tag:
        required: true
permissions:
  contents: write
```

It checks out the selected tag, sets up PHP 8.1 and Node 20, runs every PHP test,
lints non-vendor PHP, runs both JavaScript checks, calls the packager, then uses
`gh release create` or `gh release upload --clobber`.

- [ ] **Step 4: Run the release test and YAML parse check**

Run:

```text
php tests/ReleasePackageTest.php
ruby -e "require 'yaml'; YAML.load_file('.github/workflows/release.yml', aliases: true)"
```

Expected: PASS. If Ruby is unavailable, parse with Python `yaml.safe_load`.

- [ ] **Step 5: Commit**

```text
git add .github/workflows/release.yml .gitignore .gitattributes tests/ReleasePackageTest.php
git commit -m "Automate tagged WordPress releases"
```

### Task 3: GitHub maintainer README

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Documents the release CLI and tag contract implemented in Tasks 1-2.

- [ ] **Step 1: Add the missing operator and maintainer sections**

Add a concise contents list, quick start, architecture/data-flow explanation,
requirements table, integration summaries, privacy/security checklist,
troubleshooting, development commands and tagged release guide.

- [ ] **Step 2: Check documentation consistency**

Verify every command, filename, tag format, version and safety claim against the
implemented files. Scan for placeholders and credential-like strings.

- [ ] **Step 3: Commit**

```text
git add README.md CHANGELOG.md
git commit -m "Expand GitHub setup and release documentation"
```

### Task 4: Full verification and initial source commit

**Files:**
- Add all previously untracked plugin source files.

**Interfaces:**
- Produces the complete local `main` branch to publish.

- [ ] **Step 1: Run the complete suite**

Run all sorted `tests/*Test.php`, attribution behavior, JavaScript syntax and
all non-vendor PHP lint checks. Expected: every command exits zero.

- [ ] **Step 2: Build and inspect `v0.2.3` locally**

Run:

```text
php tools/build-release.php --tag=v0.2.3 --output-dir=<temporary-directory>
```

Verify the one-root archive, checksum, required files, excluded developer files
and zero credential-pattern matches.

- [ ] **Step 3: Review Git scope and commit the full plugin**

Stage only this plugin repository. Confirm no `.env`, runtime output, local
credentials or generated ZIP is staged, then commit:

```text
git add -A
git commit -m "Publish CF7 AI Enquiry Validator 0.2.3"
```

### Task 5: Private GitHub publication

**Files:** No source changes expected.

**Interfaces:**
- Produces: `https://github.com/ConnorBulmer/cf7-ai-enquiry-validator`

- [ ] **Step 1: Create the private repository**

```text
gh repo create ConnorBulmer/cf7-ai-enquiry-validator --private --source=. --remote=origin
```

- [ ] **Step 2: Push `main`**

```text
git push -u origin main
```

- [ ] **Step 3: Verify remote state**

Use `gh repo view` and the GitHub API to confirm visibility `PRIVATE`, default
branch `main`, Actions workflow presence, README rendering source and pushed
commit identity.

- [ ] **Step 4: Do not create a release tag**

Leave version `0.2.3` untagged until the maintainer deliberately chooses to
publish the first release. Document the exact command:

```text
git tag v0.2.3
git push origin v0.2.3
```
