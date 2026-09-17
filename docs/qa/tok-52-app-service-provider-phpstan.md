# TOK-52 AppServiceProvider PHPStan Debt QA

## Purpose

This document tracks the focused removal of the 12 PHPStan findings previously
assigned to `AppServiceProvider`. The change centralizes string configuration
normalization without changing valid environment defaults, service bindings,
testing safety rules, or application API behavior.

Implementation branch: `task/jd-tok-52`.

Status legend: ✅ verified, ⬜ not verified yet.

## Implementation Contract

- Required string configuration preserves the previous scalar cast behavior,
  including normalizing `null` to an empty string.
- Nullable string configuration preserves `null` for dependencies such as the
  optional Meilisearch API key.
- Arrays and objects are rejected with a configuration-specific
  `RuntimeException` before reaching a string-only dependency.
- The existing values and defaults in `config/`, including
  `MEILISEARCH_KEY`, remain unchanged.
- Database, queue, cache, Redis, and Meilisearch testing guards retain their
  existing safety decisions.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 287 | 275 |
| Baseline entries | 210 | 208 |
| Files represented | 28 | 27 |
| `AppServiceProvider` findings | 12 | 0 |

The two removed baseline entries represented 11 mixed-to-string casts and one
mixed Meilisearch API-key argument. No ignore rule was added or widened, and
the baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-52-BE-01 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | `composer analyse` passed with no errors after the two stale entries were removed. The baseline now contains 275 findings across 208 entries and 27 files. |
| TOK-52-BE-02 | ✅ | Verify configuration normalization and rejection behavior. | The focused unit suite passed 17 tests and 26 assertions covering scalar values, required and nullable `null`, non-scalar rejection, and the existing external-service safety guards. |
| TOK-52-BE-03 | ✅ | Run PHP syntax and scoped Pint checks. | PHP 8.3 syntax validation and scoped Pint checks passed for the provider and focused test. |
| TOK-52-BE-04 | ✅ | Run repository-wide formatting, tests, and diff validation. | `composer format:check` passed 172 files, the full backend suite passed 203 tests and 1,291 assertions, and `git diff --check` passed. |

## Release Status

No migration, seeder, frontend change, configuration-file change, or
deployment-configuration change is required. CI, deployment, health-check, and
final synchronization evidence is tracked in Jira and GitHub after the local
implementation commit.
