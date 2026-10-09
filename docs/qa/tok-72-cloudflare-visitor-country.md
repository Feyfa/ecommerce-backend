# TOK-72 Cloudflare Visitor Country QA

## Purpose and Contract

Expose the received `CF-IPCountry` request header on the backend root response
so a later real request through Cloudflare can verify header delivery to Laravel.

Jira: [TOK-72](https://muhammadjidan-31088882.atlassian.net/browse/TOK-72).
Implementation branch: `task/jd-tok-72`.
This document records local verification only; CI, PR, deployment, runtime
revisions, and deployed response evidence belong in Jira/PR/Actions.

`GET /` continues to return HTTP 200 with `status`, `service`, and `timestamp`.
The additional `cf_ipcountry` field contains the received header unchanged,
including `XX` and `T1`. A missing header returns JSON `null`; no country is
inferred from the server IP or used as a fallback. The field remains available
after the experiment. No visitor IP or other request header is added to JSON.

The response sets `Cache-Control: private, no-store` to prevent storage by
compliant caches. Header directive order may be canonicalized by the framework;
tests check the directives rather than their textual order.

The field is diagnostic. A locally supplied header can produce the same JSON,
so its presence does not authenticate Cloudflare traffic and must not be used
as an access-control decision. No database, dependency declaration, frontend,
or deployment configuration is changed.

## Local Verification

Checks were executed on October 9, 2026 using PHP 8.3. The root feature suite
passed with 2 tests and 25 assertions; the full application suite was not run.

| ID | Status | Check | Evidence |
| --- | --- | --- | --- |
| TOK-72-BE-01 | ✅ | Preserve health behavior without a country header. | The existing root test verifies HTTP 200, `status: ok`, `service: backend`, the timestamp field, and `cf_ipcountry: null`. |
| TOK-72-BE-02 | ✅ | Preserve received country values and the JSON contract. | The new root test verifies `ID`, `XX`, `T1`, and the raw value ` id ` without normalization. Exact JSON assertions include the frozen timestamp and prevent extra response fields. |
| TOK-72-BE-03 | ✅ | Prevent caching of responses with and without a country. | Both feature tests assert the `private` and `no-store` directives for every response. |
| TOK-72-BE-04 | ✅ | Check PHP syntax and formatting. | `php -l` passed for the route and test files. `composer format:check` passed for 183 files; a subsequent scoped Pint check passed for both changed PHP files. |
| TOK-72-BE-05 | ✅ | Run configured application static analysis. | `composer analyse` passed with no errors using the existing `app/` scope, level, and baseline. Routes and tests are outside that configured analysis scope and are checked by syntax, Pint, and the feature suite. |
| TOK-72-BE-06 | ✅ | Review the final code, documentation, diff, and Git boundary. | Read all changed/new files and reviewed the actual patch. Diff checks reported no whitespace errors, the active branch is `task/jd-tok-72`, and the index is empty. Frontend and deploy working trees are clean. |

## Environment and Limitations

Tests use the repository's testing environment and simulated request headers.
They prove Laravel header reading and response behavior, not Cloudflare header
injection, IP Geolocation configuration, proxy forwarding, or the behavior of
the public staging and production endpoints. No browser was used.

PHPStan was initially unavailable because three development packages from
`composer.lock` were missing. `composer --no-cache install` with
`--no-scripts --no-interaction --prefer-dist` installed the locked versions of
PHPStan, Larastan, and phpMyAdmin SQL Parser into the local ignored `vendor/` directory.
There were no dependency updates or lockfile changes. PHPStan's worker socket
was blocked by sandbox EPERM; the permitted host execution then passed.

Tokshop's locked framework is Laravel 10.48.16. The separate office project's
Laravel 12 version and Cloudflare configuration are not verified by these tests.

Implementation stops before staging files, committing, pushing, opening PRs,
or deploying. The Jira task remains open while real Cloudflare delivery is
unverified; release work requires the user's subsequent instruction.
