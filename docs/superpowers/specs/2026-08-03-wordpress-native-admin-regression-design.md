# WordPress-native admin refresh and regression hardening design

**Date:** 2026-08-03
**Target release:** 0.2.16
**Schema version:** 10 (unchanged)

## Purpose

Refresh the CF7 AI Enquiry Validator administration screens so that they are
clear, responsive, accessible, and recognisably WordPress-native, while running
a comprehensive regression programme across the portable plugin and Mosaic's
live installation.

The release must improve presentation without changing classification,
delivery, consent, settings, database, or public-site behaviour. It must remain
portable across WordPress sites and submission-storage integrations.

## Confirmed baseline

Before this design was approved:

- all 25 standalone PHP test files passed;
- the attribution JavaScript behaviour and syntax checks passed;
- the live Mosaic administration screens were inspected in the authenticated
  WordPress interface;
- plugin CSS was confirmed to load only on plugin administration screens; and
- the local `main` worktree was clean and eight commits ahead of `origin/main`.

The inspection identified four concrete issues:

1. Overview and Tracking Health render operational data as raw JSON.
2. Review Queue and Tracking Health cause page-wide horizontal overflow.
3. Integrations is a long, weakly grouped collection of controls with little
   visual hierarchy.
4. The Microsoft controlled-test copy still calls the event
   `identifier-free`, although version 0.2.15 correctly sends a one-off
   synthetic anonymous identifier required by the Microsoft API.

## Design direction

Use a polished WordPress-native interface. Retain server-rendered PHP and core
WordPress interaction conventions rather than introducing an application-like
JavaScript interface or site-specific branding.

The implementation will use:

- WordPress typography, neutral colours, buttons, notices, form controls, and
  focus conventions;
- prefixed semantic classes beneath `.cf7aiv-admin`;
- progressively enhanced native HTML such as `<details>` where advanced
  technical content benefits from disclosure;
- responsive CSS grids and local table-scrolling containers; and
- no new JavaScript dependency.

No plugin-admin style may affect unrelated WordPress screens or the public
site.

## Screen structure

### Shared page presentation

Each administration page receives a consistent header treatment with:

- one page heading;
- a concise description of the page's purpose;
- relevant text-labelled status badges; and
- predictable spacing between panels, forms, tables, help text, and actions.

Status must never be communicated by colour alone.

### Overview

The Overview becomes a readable operational dashboard without becoming an
application-style UI.

- Automation mode and setup completion appear as labelled status badges.
- Queue age, attribution readiness, API errors, and reviewed count appear in a
  responsive metric-card grid.
- State, tier, and reason distributions appear as compact label/count tables.
- Human-reviewed quality appears in a dedicated panel with exact agreement,
  Ads precision, and false positives.
- The confusion matrix and per-tier review counts render as semantic tables
  when data exists and as explanatory empty states when no reviewed data exists.
- Paused destinations render as alert rows or a clear `None paused` state.
- Page navigation remains available as ordinary WordPress links.

Operational distributions must continue to be described as counts rather than
accuracy measures.

### Review Queue

- Preserve the existing decision legend and all bulk and per-item actions.
- Group filtering and bulk actions into separate, compact toolbars.
- Wrap the wide review table in a labelled, locally scrollable region.
- Prevent the table from widening the WordPress page itself.
- Preserve item detail, revision protection, evidence, attribution, consent,
  review, and delivery information.
- Retain visible text labels and keyboard-operable controls.

### Policy Lab and Setup

- Preserve the existing guided workflow, draft/publish separation, lifecycle
  safeguards, and optional historical calibration.
- Improve numbered-section hierarchy, panel spacing, help text, field grouping,
  and action placement.
- Keep advanced policy controls conditional on Advanced mode.
- Keep publishing, lifecycle changes, and calibration explicit and visually
  distinct from ordinary saves.

### Integrations

Group the existing controls into distinct native panels:

1. interface mode and AI provider;
2. GA4 and optional existing server-side GTM;
3. Microsoft UET;
4. webhook;
5. privacy and retention;
6. controlled transport tests;
7. automation and destination lifecycles;
8. consent policy;
9. destination mappings; and
10. Advanced-mode usage diagnostics.

Primary, secondary, and safety-sensitive actions use appropriate WordPress
button and notice conventions. All current form actions, nonce boundaries,
write-only secret behaviour, live-transition guards, and redirect behaviour
remain unchanged.

The Microsoft transport-test explanation must state that the event is
zero-value and consent-denied, includes no click ID or enhanced identity, and
uses only a one-off synthetic anonymous identifier required by CAPI. It must
not claim to be identifier-free.

### Tracking Health and Logs

- Replace the health JSON blob with labelled status rows.
- Show each source's availability and bounded count in a semantic table.
- Render read-only checks as text-labelled statuses.
- Render paused destinations as structured rows or an empty state.
- Preserve the historical-calibration form and explicit opt-in.
- Replace delivery-error JSON with a responsive table containing only the
  existing sanitised fields.
- Render a clear empty state when no delivery errors exist.

## Rendering architecture

Introduce one small, focused admin-view helper for reusable presentation
primitives such as:

- panels;
- status badges;
- metric cards;
- key/value and label/count tables;
- empty states;
- locally scrollable table regions; and
- human-readable duration formatting.

Existing service classes remain the source of data. In particular,
`DashboardService`, `HealthService`, settings, classification, delivery,
consent, attribution, and database code must not be changed merely to support
the visual refresh.

All dynamic content must be escaped at the final output boundary. Existing
sanitisation of delivery error text must be preserved. The helper may accept
only already structured values and must not expose credentials, raw provider
responses, private submissions, or hidden source details.

## CSS architecture

Extend `assets/css/admin.css` with WordPress-native, prefixed components.

- Every rule must be rooted beneath `.cf7aiv-admin`, apart from a forced-colour
  media query whose selectors remain rooted there.
- Use prefixed custom properties only within `.cf7aiv-admin` for repeated
  spacing, borders, surfaces, and status colours.
- Use responsive grid layouts that collapse naturally at tablet and narrow
  widths.
- Tables remain semantic tables. Wide tables scroll inside a dedicated wrapper
  with an accessible label or description.
- Inputs and textareas must fit their panel at narrow widths.
- Focus indicators must remain visible.
- Forced-colours mode must retain borders and status differentiation.
- Motion is unnecessary; no animation is introduced.
- Avoid `!important` unless a verified WordPress-core conflict makes it
  unavoidable and a regression test documents the reason.

## Accessibility contract

The refreshed interface must provide:

- one logical `<h1>` and ordered heading levels;
- programmatically associated labels for controls;
- semantic tables with scoped headers;
- text in every status badge so meaning is not colour-only;
- keyboard access to every action and native disclosure;
- visible focus styles;
- practical action sizing, targeting at least 44 CSS pixels where the layout
  permits without conflicting with compact WordPress table controls;
- readable empty and error states; and
- no page-level horizontal overflow at desktop, tablet, or narrow mobile
  widths.

Only explicitly designated table regions may scroll horizontally.

## Regression and verification strategy

### Test-first render coverage

Add failing tests before implementation for:

- populated and empty Overview states;
- structured distribution, quality, confusion-matrix, and paused-destination
  output;
- populated and empty Tracking Health states;
- sanitised delivery-error table output;
- absence of raw JSON `<pre>` output on Overview and Tracking Health;
- responsive table wrappers on Review Queue and Tracking Health;
- the corrected Microsoft controlled-test description;
- shared panel, metric, badge, and empty-state semantics;
- escaping of dynamic labels and error text;
- simple and Advanced-mode Integrations output; and
- unchanged actions, nonce fields, capability checks, and safety controls.

Add a stylesheet contract test that confirms selectors remain scoped, required
responsive and forced-colour rules exist, and no public-site asset enqueue is
introduced.

### Full local suite

Run:

1. all standalone `tests/*Test.php` files;
2. PHP syntax checks for every non-vendor PHP file;
3. attribution JavaScript behaviour tests;
4. attribution JavaScript syntax checks;
5. release-package tests; and
6. source and archive scans for credentials, private data, Mosaic-specific
   identifiers, unsafe paths, and incorrect release identities.

### Disposable WordPress matrix

Run the existing four serial cells:

| WordPress | PHP | Submission source |
| --- | --- | --- |
| 6.9 | 8.1 | Flamingo |
| 6.9 | 8.1 | CFDB7 |
| 7.0.2 | 8.3 | Flamingo |
| 7.0.2 | 8.3 | CFDB7 |

Extend browser acceptance to cover each plugin administration screen at
desktop, tablet, and narrow mobile widths. Assert page-level overflow is absent,
designated tables retain local scrolling, critical controls remain visible and
operable, and populated/empty states render correctly.

The disposable matrix must not call live AI, analytics, or advertising APIs.

### Release packaging

Prepare version `0.2.16` with all plugin, runtime, readme stable-tag, and release
identities aligned. Schema remains version 10 and no migration is introduced.

Build the release using the repository packager, reopen and verify the archive,
confirm one canonical plugin root, generate a SHA-256 checksum, and scan the
archive independently of the source tree.

## Mosaic production acceptance

Production is modified only after local and disposable verification passes.

1. Confirm the exact StackCP document root and installed plugin version.
2. Back up the installed plugin and relevant plugin settings outside
   `public_html`.
3. Upload the exact verified release archive and hash-check the deployed files.
4. Activate or upgrade through WordPress tooling without using the server-side
   Git checkout.
5. Confirm version `0.2.16`, schema `10`, activation, scheduled work, status,
   health, and dry-run output through PHP 8.3 WP-CLI.
6. Inspect every administration screen in the authenticated browser at desktop,
   tablet, and narrow widths.
7. Run controlled synthetic checks for AI classification, GA4 debug/live
   delivery, Microsoft zero-value transport, end-to-end processing, and
   duplicate suppression using non-personal data.
8. Confirm no mapping, consent, automation, or destination state changed except
   where an explicitly guarded test requires it and the prior state is restored.
9. Confirm the public site, Contact Form 7 submission flow, and attribution
   script remain unaffected.

Remote calls remain serial because of the StackCP/20i SSH constraints.

## Rollback

Rollback uses the production backup created immediately before deployment.
Because schema version 10 is unchanged, rollback does not require database
downgrade logic. Restore the previous plugin files and settings snapshot,
reactivate if needed, then repeat version, status, health, public-page, and form
checks.

## Acceptance criteria

Release 0.2.16 is acceptable only when:

- every approved screen structure is present;
- Overview and Tracking Health contain no raw JSON presentation;
- Review Queue and Tracking Health do not create page-wide overflow;
- all dynamic output remains escaped and private data remains protected;
- Microsoft test copy accurately describes the synthetic anonymous ID;
- all current security and delivery safeguards remain intact;
- the complete local and four-cell disposable suites pass;
- desktop, tablet, narrow-width, keyboard, and forced-colour checks pass;
- the verified release archive contains no credentials or site-specific
  identifiers;
- live WP-CLI, browser, controlled-delivery, deduplication, and public-site
  acceptance checks pass; and
- the exact rollback artifact and restoration procedure are recorded.
