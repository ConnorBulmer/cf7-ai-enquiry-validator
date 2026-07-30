# Private GitHub repository and release packaging design

## Objective

Publish CF7 AI Enquiry Validator as a private repository named
`ConnorBulmer/cf7-ai-enquiry-validator`. The repository must contain the complete
plugin source and practical maintainer documentation. Tagged releases must
produce a correctly rooted WordPress plugin ZIP without requiring a local
packaging environment.

## Repository

- The plugin directory is the repository root.
- The default branch is `main`.
- The repository is private.
- Generated release ZIPs are not committed.
- Secrets, encrypted WordPress option values, local environment files, runtime
  output and editor metadata are excluded.
- Bundled Action Scheduler remains in the repository because it is part of the
  distributable plugin.

## GitHub-facing documentation

`README.md` is the practical maintainer and operator guide. It covers:

- purpose, supported systems and important safety boundaries;
- installation and dependency requirements;
- guided policy setup, starter industries and custom policies;
- DeepSeek and OpenAI configuration;
- Flamingo/CFDB7 capture, attribution and consent handling;
- review, calibration, shadow mode and deterministic dispatch;
- GA4, existing server-side GTM, Microsoft UET and webhook destinations;
- WP-CLI, testing, extension interfaces and troubleshooting;
- privacy/security responsibilities;
- versioning and the automated GitHub release process.

`readme.txt` remains the WordPress.org-facing document and is not expanded with
GitHub-maintainer detail.

## Release contract

The release workflow runs for pushed tags matching `v*.*.*` and may also be
started manually for an existing version tag.

For a tag such as `v0.2.3`, the workflow derives `0.2.3` and requires exact
agreement with:

1. the plugin header `Version`;
2. the `CF7AIV\VERSION` constant;
3. the `readme.txt` `Stable tag`.

A mismatch stops the workflow before a release is published.

## Validation

Before packaging, GitHub Actions:

1. checks out the tagged source;
2. installs supported PHP and Node runtimes;
3. runs every standalone `tests/*Test.php` file;
4. runs PHP syntax checks for every non-vendor PHP file;
5. runs the attribution JavaScript behavior test and syntax check;
6. scans distributable source for common committed-secret patterns;
7. builds and inspects the archive.

External AI and advertising APIs are not called by the release workflow.

## Archive

The asset is named `cf7-ai-enquiry-validator-<version>.zip`.

Every archive entry is below one `cf7-ai-enquiry-validator/` directory. The
archive contains runtime plugin source, bundled dependencies, user-facing
documentation and licensing. It excludes:

- `.git`, `.github` and local environment files;
- tests and design/verification evidence;
- editor, OS, cache, coverage and generated-output files.

The workflow verifies the root, slash direction and presence of
`cf7-ai-enquiry-validator/cf7-ai-enquiry-validator.php`. It also produces a
matching `.sha256` file.

## Publication and failures

On success, GitHub Actions creates or updates the GitHub Release for the tag and
attaches the ZIP and checksum. Release notes are generated from Git history.

Validation, version or packaging failures leave the tag visible but do not
publish an incomplete release asset. Rerunning the workflow for the same tag is
idempotent: assets with the same names are replaced by the verified build.

## Initial publication

The existing verified `0.2.3` source becomes the initial `main` branch content.
No release tag is created automatically during repository setup; the first
release is produced only when the maintainer deliberately pushes a matching
version tag.
