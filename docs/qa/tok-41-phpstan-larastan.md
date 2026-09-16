# TOK-41 PHPStan and Larastan QA

## Purpose

This document tracks backend verification for introducing PHPStan through
Larastan as a blocking static-analysis gate. The adoption is limited to
developer tooling, CI configuration, documentation, and behavior-preserving
type metadata; it does not intentionally change application behavior, database
queries, persistence, API responses, or public contracts.

Revision under review:

- branch: `task/jd-tok-41`;
- commit: this document is included in the reviewed TOK-41 implementation commit.

Status legend: ✅ verified, ⬜ not verified yet.

## Locked Toolchain and Configuration

| Item | Locked or configured value |
| --- | --- |
| Runtime | PHP 8.3 |
| PHPStan target | `phpVersion: 80300` |
| Laravel | Existing Laravel 10 application; no framework upgrade in TOK-41 |
| Larastan | `larastan/larastan` v2.9.14, direct development dependency |
| PHPStan | `phpstan/phpstan` 1.12.34, direct development dependency |
| Transitive parser | `phpmyadmin/sql-parser` 5.11.1 |
| Analysed path | `app/` |
| Level | `max`, equivalent to level 9 for PHPStan 1.12 |
| Local and CI command | `composer analyse` |
| Memory limit | 1 GiB |
| Baseline stale check | `reportUnmatchedIgnoredErrors: true` |

Laravel 11, Larastan 3, PHPStan 2, level 10, and analysis of routes,
database files, or tests remain outside TOK-41.

## Adoption Results

The first raw run completed without bootstrap, autoload, configuration, or
symbol-resolution errors. It reported 440 application findings across 52 of 85
PHP files under `app/`.

Behavior-preserving PHPDoc and type metadata removed 83 findings before the
final baseline state was accepted:

- Eloquent relation generics were added to 14 model files, and all three
  `Product` scopes now declare `Builder<Product>`;
- retry backoff arrays in two search synchronization jobs now declare integer
  keys and values;
- `AuditLogResource` declares its `AuditLog` model mixin and string-keyed
  response map;
- the audit context parameter declares a string-keyed map;
- seller dashboard, seller cursor, and transaction queries declare their
  concrete Eloquent builder model.
- bounded outbox command inputs now declare the numeric string or integer
  values already accepted by their existing casts;
- payment simulation, verified seller IDs, seller cursor positions, and the
  product image manifest now expose their proven iterable value types.

These changes alter type metadata and its corresponding import only. No
application branch, query, write, response, or side effect was modified.

The remaining 357 findings are captured in `phpstan-baseline.neon`. Every
entry points to `app/`; there are no broad ignore patterns or excluded source
paths.

## Baseline Identifier Inventory

| PHPStan identifier | Count |
| --- | ---: |
| `argument.type` | 84 |
| `missingType.iterableValue` | 67 |
| `property.nonObject` | 57 |
| `property.notFound` | 42 |
| `cast.string` | 27 |
| `encapsedStringPart.nonString` | 22 |
| `cast.int` | 7 |
| `offsetAccess.nonOffsetAccessible` | 9 |
| `method.nonObject` | 8 |
| `argument.templateType` | 7 |
| `return.type` | 3 |
| `match.unhandled` | 4 |
| `assign.propertyType` | 3 |
| `missingType.generics` | 3 |
| `nullCoalesce.offset` | 3 |
| `cast.double` | 2 |
| `method.notFound` | 2 |
| `ternary.elseUnreachable` | 2 |
| `booleanAnd.rightAlwaysTrue` | 1 |
| `foreach.nonIterable` | 1 |
| `if.alwaysTrue` | 1 |
| `method.unresolvableReturnType` | 1 |
| `nullCoalesce.variable` | 1 |

## Baseline Hotspots

| File | Findings |
| --- | ---: |
| `app/Services/CheckoutService.php` | 37 |
| `app/Services/KeranjangService.php` | 36 |
| `app/Http/Controllers/AlamatController.php` | 34 |
| `app/Services/SaldoService.php` | 24 |
| `app/Services/TransactionService.php` | 20 |
| `app/Http/Resources/AuditLogResource.php` | 17 |
| `app/Http/Controllers/KeranjangController.php` | 16 |
| `app/Http/Controllers/PaymentController.php` | 14 |
| `app/Http/Controllers/SaldoController.php` | 14 |
| `app/Services/Clerk/ClerkSecurityService.php` | 14 |

## Behavior-Sensitive Follow-up Candidates

The following findings were not changed merely to satisfy static analysis:

- `SaldoService` assumes every stored history type is `incoming` or
  `withdrawal`; handling null, unexpected, or legacy values requires an
  explicit response and data-integrity decision.
- `UserController` receives `string|false` from a storage write in one profile
  image path; deciding whether to throw, roll back, or return an API error is a
  behavior change.
- Clerk user synchronization and manual outbox retry call `fresh()`, whose
  framework contract is nullable even though the records were just saved and
  locked. Tightening this requires choosing the failure behavior.
- Checkout, cart, payment, balance, and transaction services exchange legacy
  heterogeneous arrays whose authoritative shapes must be established across
  callers before adding narrow types.
- Joined Eloquent queries expose selected aliases as dynamic model attributes.
  Replacing them with DTOs, typed projections, or model-wide property
  declarations requires separate design and regression coverage.

No verified security vulnerability, data-corruption path, or unsafe database
transaction change was introduced or silently corrected by TOK-41. The items
above remain visible candidates for focused follow-up work.

## Dependency Audit Observation

`composer audit --locked` reports 48 advisories across 14 packages already
present in the repository, including runtime framework and HTTP dependencies.
The lockfile diff adds only Larastan, PHPStan, and SQL Parser, and none of those
three packages appears in the advisory result. Updating the unrelated existing
dependency graph is intentionally outside TOK-41 and requires a dedicated
security-upgrade task; the audit result is not represented by the PHPStan
baseline.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-41-BE-01 | ✅ | Resolve dependencies with PHP 8.3 and inspect the lockfile scope. | Composer resolved Larastan v2.9.14, PHPStan 1.12.34, and SQL Parser 5.11.1 without updating Laravel or unrelated locked packages. |
| TOK-41-BE-02 | ✅ | Run raw analysis before the baseline. | The first run completed without configuration errors and reported 440 findings; safe metadata reduced the remaining findings to 357. |
| TOK-41-BE-03 | ✅ | Verify safe metadata batches. | PHPStan accepted the retained relation, job, resource, audit-context, query-builder, command-input, iterable-shape, and cursor annotations without runtime behavior changes. |
| TOK-41-BE-04 | ✅ | Generate and validate the baseline. | The baseline contains 357 findings from `app/` only; analysis with the baseline and stale-entry reporting exited successfully. |
| TOK-41-BE-05 | ✅ | Run the exact `composer analyse` entry point. | Passed with exit code 0 on September 16, 2026; unmatched baseline reporting found no stale entries. |
| TOK-41-BE-06 | ✅ | Validate Composer metadata and a production `--no-dev` install resolution. | `composer validate --strict --no-check-publish` passed. The PHP 8.3 production dry run completed with zero installs or updates and removed all 40 development packages, including PHPStan and Larastan. |
| TOK-41-BE-07 | ✅ | Run repository-wide Pint check and relevant automated tests with PHP 8.3. | Scoped Pint passed for all six PHP files changed by the follow-up batch, and `composer format:check` passed for 172 files. Four focused suites passed 44 tests and 481 assertions; the full suite passed 193 tests and 1,275 assertions. |
| TOK-41-BE-08 | ✅ | Validate the edited GitHub Actions YAML and working diff. | The workflow parsed successfully after adding static analysis before migrations and PHPUnit; `git diff --check` passed and the PHP source diff contains type metadata only. |
| TOK-41-BE-09 | ⬜ | Run task-branch backend CI. | Pending branch push and remote task-branch CI; local review and validation are complete. |
| TOK-41-BE-10 | ✅ | Audit the locked dependency graph and attribute any advisory to the new tooling. | Composer reported 48 advisories across 14 existing packages and zero advisories for Larastan, PHPStan, or SQL Parser. Remediation is outside TOK-41. |

## CI and Release Status

Backend CI installs the locked development dependencies, prepares the Laravel
testing environment, runs `composer analyse`, and only then runs migrations and
the test suite. Remote CI, staging, production deployment, branch
synchronization, and the Jira Done transition remain pending because the branch
has intentionally not been pushed after the local implementation commit.

Frontend and deploy repositories are outside the implementation scope and have
not been changed.
