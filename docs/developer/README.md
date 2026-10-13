# Developer guide

This guide explains how CF7 AI Enquiry Validator works internally, so you can
change it safely. It is written for a PHP developer who knows WordPress but has
not seen this codebase.

If you only want to install or configure the plugin, read the
[project README](../../README.md) and [Setup](../SETUP.md) instead.

## Start here

1. Read [Architecture](architecture.md). It covers the directory layout, how
   the plugin boots, every hook and background job, and one enquiry's path
   from form submission to conversion.
2. Read the guide for the area you are changing:

| You are changing… | Read |
| --- | --- |
| Hidden form fields, the attribution script, consent capture, fingerprints, identity hashes | [Intake and attribution](intake-and-attribution.md) |
| Policies, starter packs, the prompt, what is sent to AI, DeepSeek/OpenAI, timeouts, evidence rules, auto-send eligibility | [Classification](classification.md) |
| When and how conversions are sent, consent at send time, retries, pausing, GA4/sGTM/Microsoft/webhook payloads | [Delivery and destinations](delivery.md) |
| Admin screens, form handlers, settings, the review queue, Policy Lab, Setup, calibration, diagnostics | [Administration](admin.md) |
| Database tables, options, transients, state values | [Data model](data-model.md) |
| Action Scheduler jobs, retries, de-duplication | [Queue and background jobs](queue-and-jobs.md) |
| Tests, the WordPress harness, releases | [Testing and releases](testing-and-release.md) |
| A common task (new setting, admin action, provider, destination, pack, migration) | [Recipes](recipes.md) |

Public extension points (filters, interfaces, lifecycle actions) for code
*outside* the plugin are in [Extending](../EXTENDING.md). What data reaches the
AI provider is in [Privacy and AI](../PRIVACY-AND-AI.md).

## The ideas that shape the code

Most design decisions follow from a few rules. Keep them in mind and the rest
of the code reads naturally.

- **The visitor never waits.** Nothing slow happens inside the Contact Form 7
  request. Capture writes one row and queues a job. AI calls and conversion
  sends run later in Action Scheduler.
- **AI proposes, rules decide.** The model returns a structured
  classification. Whether anything is sent is decided by deterministic PHP:
  grounded evidence, policy status, lifecycle, consent, mappings, approval and
  idempotency. Model confidence is shown for diagnostics only and never gates
  a send.
- **Fail closed.** When in doubt, an enquiry goes to human review and nothing
  is sent. Missing consent, a missing mapping, an unpublished policy, a
  verification error, a possible duplicate: each ends in "Needs review" or a
  recorded gate, never a send.
- **Store a reference, not a copy.** The plugin keeps a pointer to the
  enquiry in Flamingo or CFDB7, not the message itself. It loads the original
  only when a worker or reviewer needs it.
- **Only whitelisted, redacted fields reach AI.** Name, email, phone, address,
  file, click ID, attribution and consent fields are blocked by name even if a
  policy whitelists them. Free text is redacted.
- **Every send happens once.** Deliveries are keyed by an idempotency hash and
  claimed under a lease. Retries, duplicate jobs and concurrent workers cannot
  send the same conversion twice.
- **Safe by default.** New installs start in shadow mode with every
  destination disabled and in dry run. Going live needs separate, explicit
  administrator steps.

## Glossary

| Term | Meaning |
| --- | --- |
| Item | One captured enquiry: a row in `wp_cf7aiv_items`. |
| Source | Where the original enquiry lives: Flamingo (`flamingo`) or CFDB7 (`cfdb7`). An item captured before its source row exists has `source_type = 'cf7'` until the worker links it. |
| Policy | The rules for classifying enquiries: tiers, evidence catalogue, exclusions, AI whitelist. Versioned in `wp_cf7aiv_policies`. Schema `cf7aiv-policy/v2` (v1 is read-only legacy). |
| Pack | A starter policy for an industry, from `assets/policies/packs.json`. |
| Tier | A policy-defined category such as `residential_care` or `spam`. Each tier maps to one universal outcome. |
| Outcome | One of six universal results: `primary_opportunity`, `secondary_opportunity`, `legitimate_non_conversion`, `excluded`, `spam`, `insufficient_information`. |
| Evidence | Codes the model cites with a quote from a field. A quote that appears in that field is *grounded*. |
| Decision state | `strong_match`, `needs_review` or `not_eligible`, worked out by `EvidenceEvaluator` from grounded evidence. Shown to users as Strong match / Needs review / Not eligible. |
| Mapping | "Send tier X to destination Y as event Z worth V." Versioned in `wp_cf7aiv_mappings`. |
| Destination | Where conversions go: `ga4`, `server_gtm`, `microsoft_uet`, `webhook`. |
| Shadow mode | Automation mode where classification runs but nothing is sent automatically. The default. |
| Dry run | A per-destination switch. The destination builds and logs the request but does not send it. |
| Calibration | Classifying a bounded sample of historical enquiries to measure accuracy. Calibration items can never be sent. |
| Gate | A recorded reason a delivery did not happen, such as `consent_not_permitted`. |

## Running the checks

```bash
for f in tests/*Test.php; do php "$f" || break; done
node tests/AttributionJsTest.js
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
```

Every test is a plain PHP script that exits non-zero on failure. There is no
Composer or PHPUnit. See [Testing and releases](testing-and-release.md).
