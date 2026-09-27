# TOK-59 Legacy Invoice Endpoint Retirement QA

## Purpose

This task retires the legacy `GET /api/invoice` endpoint and its unused frontend
Vuex action. The current buyer and seller transaction pages use
`getTransactions` and `GET /api/transaction`. A repository-wide source search
found no caller of `getInvoice`; the old `InvoiceView.vue` that called it was
removed in frontend commit `eeefb0b`. The backend route itself was marked
"sudah tidak dipakai" (no longer used). These findings establish the current
repository usage, not the absence of external API clients.

Implementation branches: `task/jd-tok-59` in backend and frontend.

## Changes and Contract

- Backend: removed the `InvoiceController` import and `GET /api/invoice` route,
  then deleted `InvoiceController.php`. There is no replacement for that route;
  a request to it now has no matching API route.
- Frontend: removed only the `getInvoice` Vuex action and its PHPDoc from
  `src/store.js`. The buyer and seller transaction pages continue to use
  `getTransactions` and `GET /api/transaction`.
- PHPStan: removed only seven baseline entries, covering eight suppressed
  findings for the deleted controller. No baseline regeneration or new ignore
  was used.
- The `TransactionInvoice` model and table, checkout flow, and transaction API
  remain in use. The generated frontend `dist` directory was not edited as
  source. Geoapify and address-location typing are outside this task.

| Baseline metric | Before | After |
| --- | ---: | ---: |
| Findings | 230 | 222 |
| Entries | 168 | 161 |
| Files represented | 18 | 17 |

## Local Verification

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-59-BE-01 | ✅ | Check route syntax and formatting. | PHP 8.3 `-l routes/api.php` and scoped Pint `--test routes/api.php` passed. |
| TOK-59-BE-02 | ✅ | Confirm the retired route is absent and the transaction route remains. | `route:list --path=invoice` reported no matching routes; `route:list --path=transaction` listed GET and POST transaction routes. |
| TOK-59-BE-03 | ✅ | Prove exactly which baseline entries became stale. | Before baseline cleanup, `composer analyse` reported seven unmatched patterns, all scoped to `InvoiceController.php`. After removing those seven entries, analysis passed with no errors. Counts were read from the resulting baseline. |
| TOK-59-BE-04 | ✅ | Verify current transaction and checkout behavior. | Focused `TransactionServiceTest.php` and `CheckoutTest.php`: 11 tests, 49 assertions passed. Full backend suite: 244 tests, 3,246 assertions passed. |
| TOK-59-FE-05 | ✅ | Check frontend source, formatting, tests, and build. | `node --check src/store.js`, ESLint, Prettier check, 25 unit tests, and Vite build passed. |
| TOK-59-XR-06 | ✅ | Inspect final references and diffs in both repositories. | No active `InvoiceController`, `getInvoice`, or `/invoice` reference remained in backend app/routes, frontend source, or deploy source. Both `git diff --check` commands passed; the new QA file has no trailing whitespace and ends with a newline. |

## Release Boundary

Repository references, existing tests, and a local build do not prove that
external clients have stopped calling `GET /api/invoice`. Production access-log
review is required before release. If an active caller or integration is found,
the endpoint retirement must be reconsidered before deployment. This page
records local validation and review. No browser smoke test, push, pull request,
or deployment was performed at this stage; remote CI and runtime checks remain
pending.
