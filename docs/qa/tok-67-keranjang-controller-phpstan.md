# TOK-67 KeranjangController PHPStan QA

## Purpose

Resolve the remaining 10 PHPStan findings in `KeranjangController` without
changing validation order, ownership, queries, HTTP contracts, quantity,
selection, availability, or read-repair behavior.

Jira: [TOK-67](https://muhammadjidan-31088882.atlassian.net/browse/TOK-67).
Implementation branch: `task/jd-tok-67`.

This document records local verification only. Post-commit CI, PR, deployed
smoke, deployment, and release-completion evidence belong in Jira/PR/Actions.

## Implementation Contract

- `validateCheckout()` reads the same request buyer ID into a locally typed
  string only after the existing UUID validation succeeds. All buyer-scoped
  calls and queries in that method use that unchanged value.
- Ownership uses the local `User|null` contract installed by authentication
  middleware, null-safe ID access, the existing string cast, and strict
  comparison. The controller still returns `403 CART_FORBIDDEN` for a missing
  request user or a different buyer. Middleware authentication remains separate.
- `store()` uses the validated seller UUID for location lookup only after its
  strict equality check against the product seller succeeds. A null or
  mismatched product seller still returns the existing 422 response before
  availability lookup or cart mutation.
- No request rules, schema, service signatures, amount casts, stock rules,
  frontend code, or deployment configuration changed.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 127 | 117 |
| Baseline entries | 94 | 87 |
| Files represented | 9 | 8 |
| KeranjangController findings | 10 | 0 |

Analysis with the original baseline reported exactly seven unmatched controller
patterns covering the ten target findings, with no new code diagnostics.
Only those seven entries were removed. Every remaining baseline entry is
unchanged; no ignore was added or widened and no baseline was regenerated.
`level: max` and `reportUnmatchedIgnoredErrors: true` remain enabled.

## Local Verification

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-67-BE-01 | ✅ | Establish the original application gate. | PHP 8.3 `composer analyse` passed before controller changes. Existing cart/availability/checkout tests passed: 32 tests, 229 assertions. |
| TOK-67-BE-02 | ✅ | Prove added coverage on the original controller. | Four new controller tests passed before application edits; the combined focused SQLite suites passed: 36 tests, 269 assertions. |
| TOK-67-BE-03 | ✅ | Preserve invalid-input and ownership behavior. | Tests reject null, empty, malformed, and array buyer IDs before service access; missing request user retains 403. Existing coverage rejects cross-buyer access on all cart endpoints without mutation. |
| TOK-67-BE-04 | ✅ | Preserve seller checks and cart mutation. | Tests reject null/mismatched product sellers before availability calls, reject an unverified seller, and verify cart creation and quantity increment for a matching verified seller. |
| TOK-67-BE-05 | ✅ | Retain full local regression coverage. | Final PHP 8.3 Unit/Feature suite on isolated SQLite passed: 283 tests, 3,772 assertions. |
| TOK-67-BE-06 | ✅ | Verify focused PostgreSQL behavior. | `KeranjangControllerTest`, `KeranjangServiceTest`, `ProductAvailabilityTest`, and `CheckoutTest` passed on PostgreSQL 16.13: 36 tests, 269 assertions. |
| TOK-67-BE-07 | ✅ | Analyze application and controller contracts. | Final `composer analyse` passed. Independent Larastan analysis of the controller at level max without any baseline also returned no errors; its configuration/cache reside outside the repository. |
| TOK-67-BE-08 | ✅ | Check formatting, syntax, and scoped diff. | `composer format:check` passed for 180 files. Changed PHP files passed syntax checks. Baseline comparison proves only seven target entries were deleted; controller queries and business responses retain their original structure. `git diff --check` passed. |
| TOK-67-BE-09 | ✅ | Review the final task changes before commit. | October 2, 2026: reviewed UUID validation, strict seller equality before location lookup, middleware user resolution, optional/null-safe ID equivalence for the local User contract, unchanged cart queries/responses, and added test expectations. No defect was found in the scoped application changes. Existing local test evidence remains applicable; no additional manual local smoke is required. |

## Test Environment And Limitations

SQLite uses the configured in-memory testing database. PostgreSQL verification
used a newly initialized cluster under `/private/tmp/tok67-postgres.llovfq`,
loopback port 55467, and the dedicated `tok67_testing` database and role.
The database identity, PostgreSQL version, and 26 migration records were
verified. The temporary server was stopped after testing.

Explicit testing overrides isolated queue, cache, session, Redis namespaces,
and search-index settings; the shared test base fakes queues. No development,
staging, or production database was used. No live Clerk or payment provider
test, browser smoke, or separate Integration suite was run. The full local
suite ran on SQLite; PostgreSQL coverage was limited to the four focused suites.

The first sandbox PHPStan run could not bind its worker socket. A permitted
host execution resolved that environment restriction. The first added-test
run exposed a mock replacement issue between requests; reusing one mock fixed
the test setup before any controller change, and the original-code run passed.
