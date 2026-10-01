# TOK-65 KeranjangService PHPStan QA

## Purpose

Resolve the 36 PHPStan findings in `KeranjangService` and align its controller
with the clarified result/input contracts while preserving cart
queries, seller grouping, numeric values, nullable product projections,
selection flags, availability priority, quantities, and read-repair behavior.

Jira: [TOK-65](https://muhammadjidan-31088882.atlassian.net/browse/TOK-65).
Implementation branch: `task/jd-tok-65`.

## Implementation Contract

- Query aliases are read through Eloquent attribute access with locally proven
  types. Derived attributes are written through the same Eloquent attribute
  mechanism used by the existing magic properties; response names and insertion
  order remain unchanged.
- Class PHPDoc defines `CartItem` and `StockIssue`. Public service results
  describe concrete array shapes, ID lists, and reason maps. The private stock
  issue helper receives the actual `Keranjang` model used by both callers.
- Numeric query values retain compatible integer, float, and numeric-string
  types rather than adding price casts. Existing stock/quantity integer
  conversions and null-to-zero fallbacks are retained.
- Nullable product data from LEFT JOINs stays nullable, including the name in
  stock issues. Seller display names keep the store-name/account-name fallback.
- Selected-item issues and unavailable reasons are recorded before invalid
  selection flags are cleared. Read-repair updates are still limited to the
  buyer's invalid cart IDs and never reset quantity.
- Seller location loading stays batched; a valid multi-seller cart uses two
  database queries. Product ID filtering, deduplication, and the old
  `checkProductSoldOutByIds()` alias retain their existing behavior.
- The approved controller adjustment removes exactly 50 null-coalescing
  fallbacks on service keys that are guaranteed present and non-null. Fallbacks
  on the generic private response-helper input and unavailable reason lookup
  remain. Request validation, HTTP statuses, query order, and business rules
  retain their existing behavior.
- Product IDs are narrowed only after the existing UUID validation. Integer and
  string input keys are supported, matching the validator's existing array
  contract. Tests cover keyed arrays as well as ordinary lists.
- Checkout services, models, migrations, frontend, and deployment files have
  not been modified.

## Baseline Reduction

| Metric | Before | Current |
| --- | ---: | ---: |
| Findings | 169 | 127 |
| Baseline entries | 124 | 94 |
| Files represented | 10 | 9 |
| KeranjangService findings | 36 | 0 |
| KeranjangController findings | 16 | 10 |

Analysis with the original baseline reported all 26 `KeranjangService` patterns
as unmatched. Those patterns, covering the 36 target findings, were removed
first. After the approved controller adjustment, analysis reported exactly
four additional unmatched controller patterns: the `array_unique` argument,
the product ID service argument, and the two `collect` template patterns.
Those four patterns covered six findings and were also removed. The total
reduction is 42 findings and 30 patterns. No baseline was regenerated and no
ignore was added or widened.

The service was independently analyzed with Larastan, PHP 8.3, and `level: max`
without any baseline. It returned `[OK] No errors`. The diagnostic configuration
and analysis reports live outside the repository.

## Verification

Status: ✅ verified, ⬜ pending.

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-65-BE-01 | ✅ | Establish meaningful tests on the original service. | Replaced two placeholder assertions and added projection/read-repair scenarios. The focused suites passed on both SQLite and isolated PostgreSQL before the service edit: 32 tests, 227 assertions. |
| TOK-65-BE-02 | ✅ | Preserve the cart result and issue contracts. | Tests cover empty state, multi-seller grouping, two-query loading, company/account display names, buyer isolation, decimal prices, nullable product fields, missing/deleted products, duplicate IDs, availability reasons, initial selection issues, and quantities after read-repair. |
| TOK-65-BE-03 | ✅ | Keep cart and checkout regressions green after the final caller adjustment. | The final focused PostgreSQL run of `KeranjangServiceTest`, `ProductAvailabilityTest`, and `CheckoutTest` passed: 32 tests, 229 assertions. The same suites are included in the successful final SQLite Unit/Feature run. Earlier focused runs on both engines passed before and after the service edit: 32 tests, 227 assertions. |
| TOK-65-BE-04 | ✅ | Analyze the service without baseline suppression. | Independent PHP 8.3/Larastan `level: max` analysis passed with no errors. |
| TOK-65-BE-05 | ✅ | Run the configured Unit/Feature suite. | Final PHP 8.3 `artisan test` passed on isolated SQLite: 279 tests, 3,732 assertions. Separate Integration suites were not run. |
| TOK-65-BE-06 | ✅ | Check formatting, syntax, and whitespace. | PHP 8.3 `composer format:check` passed for 179 files. Syntax checks passed for the service, controller, and changed test file; `git diff --check` passed. |
| TOK-65-BE-07 | ✅ | Restore the full application PHPStan gate. | After removing only the four proven-stale controller patterns, PHP 8.3 `composer analyse` passed with `[OK] No errors`. `level: max` and unmatched-ignore reporting remain enabled. |
| TOK-65-BE-08 | ✅ | Review the final diff before staging release. | On October 1, 2026, final review found no defect in the scoped diff. Laravel magic attribute access delegates to the same get/set methods now used explicitly; query structure, read-repair ordering, validated UUID inputs, nullable projections, and decimal amounts were checked. PHPStan and the full SQLite suite were rerun successfully: 279 tests, 3,732 assertions. |
| TOK-65-BE-09 | ✅ | Pass task-branch and staging-integration CI. | Task CI 36882687537, integration CI 36883358617, PR CI 36883855102, and Release Branch Policy 36883855052 completed successfully. Backend PR #162 merged into staging as f7db131db2cfdbecfc694169cd52d6528b7eac83. |
| TOK-65-BE-10 | ✅ | Deploy staging and verify runtime health and local synchronization. | Deploy Staging 36884303246 completed successfully on October 1, 2026, activating backend f7db131db2cfdbecfc694169cd52d6528b7eac83 and frontend d2eb8dfe16114f1271eea9cf8dec211db51e4312. All nine services were running; public frontend and API health returned HTTP 200. All three local repositories were synchronized and clean on main. Jira comment 10182 records the release evidence. |
| TOK-65-BE-11 | ✅ | Smoke-test the deployed cart with real authentication. | User-provided staging screenshots prove cart loading, quantity increment/decrement and persistence after refresh, checked/unchecked persistence, individual/store/all selection, two-store isolation, correct totals (Rp10,000, Rp20,000, Rp160,000, Rp195,000), and entry to checkout with one item at Rp10,000 plus Rp15,000 shipping = Rp25,000. Displayed cart/selection/checkout requests returned HTTP 200. Coverage and the accepted console observation are detailed below. |

## Staging Smoke Coverage and Observation

The buyer performed the browser checks and supplied screenshots in this
conversation. The assistant did not open or automate a browser. Evidence was
combined across screenshots; proven cases do not require repeated manual runs.
The two-store fixture contained Topi from Dots and Helm KYT / Smoke Test from
Toko Fisika Modern. Selecting or deselecting a store preserved the other store's
selection, and global selection produced the correct Rp195,000 total.

The normal authenticated cart-to-checkout path passed. No order or payment was
created during the guided smoke. Unavailable products, stock-change read-repair,
buyer isolation, and nullable projections were verified by automated tests,
not by these browser screenshots.

Some screenshots contained `Uncaught (in promise) Error: Could not establish
connection. Receiving end does not exist.` without an expandable stack trace.
Other screenshots after refresh showed no console errors. The source remains
unconfirmed; no attribution to an extension or application code is established.
The observed cart and checkout operations succeeded despite the message. The
user accepted it as a non-blocking observation before authorizing production.

Staging release: [PR #162](https://github.com/Feyfa/ecommerce-backend/pull/162),
[Deploy Staging](https://github.com/Feyfa/ecommerce-deploy/actions/runs/36884303246).

## Caller Compatibility Boundary

The precise return shapes expose existing controller expressions such as
`$getKeranjangs['keranjangs'] ?? []` and
`$getKeranjangs['totalPrice'] ?? 0`. Those keys are always returned by the
service, so PHPStan now reports the defensive fallbacks as redundant.

The user approved the bounded caller adjustment on October 1, 2026. The
controller now reads those guaranteed keys directly and uses a locally typed
product ID array after UUID validation, preserving the original values and
input keys. This also supplies a concrete input type to `array_unique`.
Two existing collection-template patterns became unmatched because the
unavailable reason maps are now typed. No new request rule, cast, error status,
or business operation was introduced.

## Environment and Delivery Boundary

PostgreSQL verification used a newly initialized PostgreSQL 16.13 cluster under
`/private/tmp`, listening on localhost port 55465, with a dedicated
`tok65_testing` database and role. Environment overrides isolated database,
queue, cache, session, Redis namespaces, and search-index settings. Test queues
were faked by the shared test base class. No development, staging, or production
database was used by automated tests, and no live provider test was performed.
The temporary PostgreSQL server was stopped after the final verification.

The initial sandbox PostgreSQL bootstrap and PHPStan worker socket were blocked
by host permissions. Permitted executions resolved those environment issues.
The first PostgreSQL test invocation was stopped by the application's Redis
testing-safety guard before running tests; explicit testing namespaces resolved
that configuration boundary.

The user authorized staging and subsequently production promotion on October 1,
2026, after the staging deployment and the guided smoke evidence above. This
document is the production-readiness snapshot. The additional QA update changes
documentation only; application code remains the implementation tested on
staging. Production PR, CI, deployment, health, and final synchronization
evidence will be recorded in Jira TOK-65 after verification. TOK-65 remains In
Progress until the authorized production release completes.
