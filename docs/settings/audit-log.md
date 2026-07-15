# Audit Log

This document explains the backend implementation for Audit Log phase 1 under Jira issue `TOK-1`.

## Status

Implemented. The backend now stores and serves successful Register, Login, and user-initiated Logout activity.

## Purpose

Audit Log gives an authenticated user a trustworthy history of important activity on their own account. Laravel owns audit persistence and access control; the browser must not be treated as the source of actor identity or event success.

Phase 1 establishes the reusable audit foundation but records authentication activity only. Profile, address, bank account, product, checkout, transaction, and other business events are outside this phase.

## Phase 1 Scope

Phase 1 records successful events only:

```text
auth.registered
auth.logged_in
auth.logged_out
```

Rules:

- A successful registration records `auth.registered` only. It must not also show a redundant login event for the first Clerk session.
- A successful login is recorded at most once for each Clerk session.
- `auth.logged_out` represents a logout initiated by the user through the application.
- A failed attempt to persist the logout audit must not prevent Clerk sign-out and local session cleanup.
- Failed registration and login attempts are outside phase 1.
- Existing activity is not backfilled. Recording starts after the feature is deployed.
- Audit rows have no automatic retention limit in phase 1.

## Authentication Context

Clerk is the authentication source of truth. `AuthenticateApiRequest` already validates Clerk tokens and places the following values on request attributes:

```text
clerk_user_id
clerk_session_id
```

Laravel resolves the local user from `clerk_user_id`. Audit code must always derive the actor from the authenticated request or the trusted Clerk-to-local-user synchronization flow. It must never accept an arbitrary actor user id from the frontend.

## Main Components

The implementation uses:

```text
app/Enums/AuditEvent.php
app/Models/AuditLog.php
app/Services/AuditLogService.php
app/Services/UserAgentParserService.php
app/Http/Controllers/AuditLogController.php
app/Http/Resources/AuditLogResource.php
app/Http/Middleware/AssignRequestId.php
database/migrations/2026_07_13_000002_create_audit_logs_table.php
```

All event creation should pass through one audit service so event naming, request metadata, IP handling, sensitive-data filtering, and idempotency remain consistent.

## Database Structure

The `audit_logs` table contains:

| Column | Purpose |
| --- | --- |
| `id` | UUID primary key for one audit event. |
| `actor_user_id` | Nullable local user UUID. User-facing queries are scoped through this value. |
| `actor_clerk_user_id` | Snapshot of the Clerk user identifier for investigation and identity correlation. |
| `event` | Stable machine-readable event name such as `auth.logged_in`. |
| `category` | Event group. Phase 1 uses `authentication`. |
| `subject_type` | Optional subject type such as `user` or `session`. |
| `subject_id` | Optional identifier of the affected subject. |
| `context` | Sanitized PostgreSQL JSONB metadata. |
| `ip_address` | Full source IP stored using an appropriate PostgreSQL representation. |
| `user_agent` | Raw request user-agent snapshot. |
| `clerk_session_id` | Clerk session associated with the authentication activity. |
| `request_id` | Correlation id for connecting the audit event to technical request logs. |
| `idempotency_key` | Internal unique hash that prevents duplicate rows for the same event/session. |
| `occurred_at` | Timestamp of the activity. |
| `created_at` | Timestamp when Laravel persisted the row. |

The migration follows the repository UUID and PostgreSQL conventions and preserves audit history when a related domain record is removed. Audit rows do not cascade-delete with the user or subject.

### Index Direction

Indexes must support the actual user-facing query:

```text
(actor_user_id, occurred_at DESC, id DESC)
```

Additional indexes or uniqueness constraints should support:

- event filtering for one authenticated user;
- request-id correlation;
- one login event per user and Clerk session;
- one logout event per user and Clerk session;
- one registration event per local user.

The implementation hashes a stable event/session source into the unique `idempotency_key`. `insertOrIgnore` and the database unique constraint enforce idempotency safely under concurrent requests rather than relying only on a read-before-insert check.

## Request ID

`request_id` is a correlation identifier, not a foreign key and not a join to another application table.

The request middleware:

1. accept a valid incoming `X-Request-ID` when appropriate;
2. generate a UUID when the header is missing or invalid;
3. attach the resolved value to the request;
4. return the same value in the response header;
5. make it available to application logs and `AuditLogService`.

One request may produce more than one technical log entry, all searchable through the same request id.

## Event Recording Flow

### Register

Registration is detected when the Clerk synchronization flow creates a new local user.

```text
Clerk registration completes
  -> frontend calls GET /api/auth/me
  -> Laravel verifies the Clerk session
  -> local user is created
  -> auth.registered is persisted
  -> the first session is marked as already observed
```

User creation and the registration audit should be committed consistently. Repeated `/api/auth/me` calls must not create another registration row or a login row for the same first session.

### Login

`GET /api/auth/me` is also used during application bootstrap and browser refresh, so calling the endpoint is not by itself a unique login signal.

Laravel should record `auth.logged_in` only once for a previously unseen Clerk session belonging to an existing local user:

```text
existing user + new Clerk session -> record auth.logged_in
existing user + already-seen Clerk session -> do not record again
```

Phase 1 therefore records when Laravel first observes the authenticated Clerk session. A future Clerk webhook integration may provide more complete provider-side session lifecycle coverage, but it is not required by this phase.

### Logout

The frontend signs out through Clerk, so phase 1 uses an authenticated backend endpoint before Clerk sign-out:

```text
user presses Logout
  -> frontend calls the authenticated logout-audit endpoint
  -> Laravel records auth.logged_out
  -> frontend clears local application state
  -> frontend signs out from Clerk and redirects to login
```

The frontend must continue Clerk sign-out even when the audit endpoint fails or is unavailable. Session expiry, provider-side revocation, and closing the browser are not labeled as user-initiated logout in phase 1.

## Authentication Method

The UI may distinguish Google, email/password, or passkey only when the backend can verify the method from reliable Clerk data. The Clerk Backend session model installed in this project does not expose a verified sign-in method, so phase 1 currently omits method-specific wording.

Rules:

- verify actual Clerk capabilities and available session data during implementation;
- never infer a method from untrusted display text;
- never fabricate a default method;
- omit method-specific wording when the method cannot be verified.

The generic title is `Login` when no verified authentication method is available.

## Sensitive Data

Audit context must be intentionally allow-listed. It must never contain:

- passwords;
- Clerk tokens or JWTs;
- authorization headers;
- cookies;
- TOTP secrets;
- backup codes;
- passkey credential material;
- API keys;
- complete request payloads by default.

The database stores the full source IP for the owner-visible detail flow. Collection responses expose a masked value. Full IP access must use an authenticated owner-scoped detail endpoint.

### Client IP Behind Reverse Proxies

`AuditLogService` stores the IP resolved by Laravel through `Request::ip()`. It
must not read `X-Forwarded-For`, `X-Real-IP`, or Cloudflare-specific headers
directly because an untrusted client can supply those headers.

Native development leaves `TRUSTED_PROXIES` empty. Docker staging and
production set it to `REMOTE_ADDR`, which trusts only the `backend-nginx`
instance directly connected to PHP-FPM. The public reverse proxy must first
validate its upstream edge proxy and replace the forwarded chain with one
normalized client IP.

Expected public request flow:

```text
client public IP
  -> trusted Cloudflare Tunnel connector
  -> reverse proxy validates CF-Connecting-IP and normalizes X-Forwarded-For
  -> backend-nginx
  -> Laravel trusts only REMOTE_ADDR and resolves the normalized client IP
```

Direct requests from other LAN hosts are not allowed to override their source
address with a forged forwarded header. Existing audit rows are immutable and
are not rewritten after proxy configuration changes.

## API Contract

The phase 1 endpoints are:

```http
POST /api/auth/logout
GET /api/audit-logs
GET /api/audit-logs/{auditLog}
```

The collection endpoint supports:

```text
event
from
to
cursor
per_page
```

Rules:

- derive ownership from the authenticated local user;
- never accept an arbitrary `user_id` for the user-facing endpoint;
- default `per_page` to 20 and cap it at 50;
- whitelist phase 1 events;
- validate date ranges;
- reject malformed cursor payloads;
- order by `occurred_at DESC, id DESC`;
- use cursor pagination;
- return masked IP values in collection data.

The detail endpoint returns one event only when `actor_user_id` matches the authenticated user. It may return the full IP required by the explicit reveal control in the UI.

No update or delete endpoint is planned. Audit data is append-only from the application perspective.

## Cursor Pagination

The collection uses cursor pagination because new audit rows may arrive while the user is reading the timeline. The cursor must include a deterministic tie-breaker such as `occurred_at` and `id`.

The first request loads 20 rows. A later request supplies `next_cursor` and appends the next results. Changing an event or date filter resets the cursor and starts a new collection query.

## Time Handling

Store timestamps with timezone-aware database semantics. API timestamps should remain machine-readable ISO 8601 values. The frontend is responsible for displaying them in the application timezone, currently Asia/Jakarta.

## Authorization

Every read endpoint is protected by `auth.api` and scoped to the authenticated local user.

The essential ownership rule is:

```text
audit_logs.actor_user_id = authenticated user id
```

A future administrator audit console requires separate authorization and is outside phase 1.

## Retention and Backfill

Phase 1 has:

- no historical backfill;
- no automatic expiration;
- no scheduled cleanup;
- no queue requirement.

If a retention policy is introduced later, the backend command and deployment scheduler must be designed together. The current deployment does not run an application scheduler.

## Automated Verification

`tests/Feature/AuditLogTest.php` covers:

- registration creates one registration event;
- the first registration session does not create a redundant login event;
- repeated Clerk user synchronization for one session does not duplicate login;
- a new Clerk session creates one login event;
- logout records one user-initiated logout event;
- a repeated logout request does not duplicate the same session event;
- one user cannot read another user's audit rows or full IP;
- collection IP is masked while owner detail can return the full value;
- collection and detail responses omit internal Clerk, user-agent, session, request, and idempotency fields;
- cursor pagination is deterministic;
- filters are validated and scoped to the authenticated user;
- malformed cursors are rejected before pagination;
- known desktop, mobile, and tablet user agents are classified without inventing a device type for unknown clients.

Sensitive-field exposure is additionally constrained by the allow-listed context in `AuditLogService` and the explicit response contract in `AuditLogResource`.

## Manual QA Scenarios

Run the scenarios from the easiest checks to the flows with the highest security or operational impact. Risk here describes the consequence and complexity of a failed scenario; it does not authorize destructive testing.

### Safety and Preparation

- Run medium- and high-risk scenarios only in local or staging, never in production.
- Apply the Audit Log migration and run the frontend and backend from the intended `TOK-1` branch.
- Prepare Account A as a completely new Clerk account and Account B as a different existing account.
- Use normal registration, login, and logout flows. Do not create, edit, or delete `audit_logs` rows manually.
- Never include Clerk tokens, authorization headers, or cookies in screenshots and QA notes.
- Replace `Not Run` with `Pass`, `Fail`, or `Blocked`, then add redacted evidence when each scenario is executed.

### Level 1 — Low Risk: Read-Only UI and Presentation

| ID | Scenario | Manual Steps | Expected Result | Status | Evidence |
| --- | --- | --- | --- | --- | --- |
| MQA-01 | Open Audit Log | Sign in normally and open `/settings/audit-log`.<br>Inspect the page and first collection request. | The page loads without console errors; title, description, filters, Refresh, and cards render; the default range is 30 days; the request uses `per_page=20`; no `Segera` badge appears. | ✅ Pass | Screenshots confirm the Audit Log UI, 30-day default (`2026-06-14` to `2026-07-13`), `GET /api/audit-logs` status `200`, `per_page=20`, no Audit Log `Segera` badge, and zero console errors. |
| MQA-02 | Desktop and mobile layout | Check desktop and representative mobile widths such as 375px, 425px, and 476px.<br>Open Detail at each layout. | Controls remain readable without horizontal overflow; Detail stays at the card's top-right on mobile; the modal follows its content width and remains inside the viewport. | ✅ Pass | Screenshots confirm the desktop layout and responsive layouts at 375px, 400px, 425px, and 476px; filters stack correctly, cards remain readable, Detail stays at the top-right, and the content-sized modal remains inside the viewport without horizontal overflow. Additional long-list screenshots confirm the filter toolbar remains sticky inside the desktop Settings scroll area while the stacked mobile toolbar stays in normal document flow. |
| MQA-03 | Card content and spacing | Inspect Register, Login, and Logout cards.<br>Compare title, badge, description, and metadata spacing. | Cards are consistent; description and metadata have a small readable separation; missing optional metadata is omitted instead of invented. | ✅ Pass | Screenshots confirm consistent Register, Login, and Logout presentation on desktop and mobile; title, description, divider, table, and security-note spacing are balanced; the desktop IP row aligns with the other rows; optional authentication method remains omitted because no verified value is available. |
| MQA-04 | IP masking and reveal | Confirm the card IP is masked.<br>Open Detail, reveal the IP, hide it, then close and reopen the modal. | Collection and newly opened Detail show a masked IP; full IP appears only after selecting the eye icon; reopening Detail resets it to masked. | ✅ Pass | Screenshots confirm the collection and initial Detail show `127.0.xxx.xxx`; selecting the eye reveals `127.0.0.1` and changes the control to hide; closing and reopening Detail resets the value to masked. |
| MQA-05 | Event filters | Select Register, Login, and Logout one at a time, then return to `Semua Aktivitas`. | Every card matches the selected event; changing the filter replaces the collection and resets the previous cursor. | ✅ Pass | Screenshots confirm Register, Login, and Logout filters each return only the matching card; Network requests use `event=auth.registered`, `event=auth.logged_in`, and `event=auth.logged_out` with status `200`; the initial unfiltered request is also present and results do not mix between filter changes. |
| MQA-06 | Time filters and no-result state | Check 7, 30, and 90 days.<br>Use a custom range containing known activity, then a valid future range.<br>Select Reset Filter. | Date boundaries follow Asia/Jakarta; the future range shows `Aktivitas tidak ditemukan`; Reset Filter returns to the default 30-day collection. | ✅ Pass | Screenshots and Network requests confirm the 7-, 30-, and 90-day presets send the corresponding date boundaries; a custom range containing 1–14 July 2026 returns known activity, while 1–8 July 2026 shows `Aktivitas tidak ditemukan`; Reset Filter restores the default 30-day collection. The custom date control remains readable on desktop and uses separate single-calendar start/end inputs on mobile without horizontal overflow. |
| MQA-07 | Refresh without a new session | Note visible events, then select Refresh several times without signing out. | The current filter reloads and no duplicate Register, Login, or Logout activity appears. | ✅ Pass | Screenshots under throttled network conditions confirm the Refresh action requests the active 30-day collection again, displays loading skeletons while requests are pending, and completes the repeated `/api/audit-logs` requests with status `200`. The same Login, Logout, and Register activities return after each refresh without additional or duplicated rows. |

### Level 2 — Medium Risk: Authentication Lifecycle

| ID | Scenario | Manual Steps | Expected Result | Status | Evidence |
| --- | --- | --- | --- | --- | --- |
| MQA-08 | Register a new account | Register Account A through the normal Clerk flow.<br>Wait for `/api/auth/me` to succeed, then open Audit Log. | Exactly one Register activity exists for the first session; a redundant Login does not appear for that session. | ✅ Pass | Screenshots confirm a new Clerk account completed the normal Google registration and callback flow, reached the authenticated Audit Log page, and produced exactly one `Akun berhasil dibuat` activity at 14 July 2026 19:44 WIB. Repeated collection requests return status `200`, the Register row remains single, and no redundant Login activity appears for the registration session. |
| MQA-09 | Reuse the registration session | While Account A remains signed in, refresh and navigate between authenticated routes several times.<br>Return to Audit Log and select Refresh. | The Register row remains single and no Login is created for the already-observed first session. | ✅ Pass | Screenshots confirm Account A remained authenticated while navigating across buyer routes, switching to seller mode, opening Product and Settings, and returning to Audit Log. Refresh shows the loading state and completes the Audit Log request with status `200`; the collection still contains exactly one Register activity and no new Login or duplicate Register activity. |
| MQA-10 | Normal user-initiated logout | Sign out Account A using the application's Logout action.<br>Confirm `POST /api/auth/logout` occurs before Clerk sign-out.<br>Sign in again and inspect Audit Log. | One Logout activity exists for the previous session; local state was cleared and the user was redirected to Login during sign-out. | ✅ Pass | Screenshots confirm the application initiated `POST /api/auth/logout` before completing sign-out, the endpoint returned status `200`, local navigation returned to `/login`, and Account A could authenticate again. Audit Log then shows exactly one Logout activity between the new Login and the original Register activity, with no duplicate rows. |
| MQA-11 | Login with a new Clerk session | After the previous logout, sign in again as Account A.<br>Refresh and navigate repeatedly, then inspect Audit Log. | Exactly one new Login exists for the new session; repeated `/api/auth/me` calls do not duplicate it. | ✅ Pass | Screenshots confirm Account A reused the new Clerk session across repeated browser refreshes and authenticated navigation, including opening Bank Account and returning to Audit Log. Repeated `/api/auth/me` and `/api/audit-logs` requests complete with status `200`; the collection remains exactly one Login, one Logout, and one Register activity, with no duplicate Login created. The updated `Login` title also renders correctly. |
| MQA-12 | Ordering and Jakarta time | Compare recent Register, Login, and Logout actions with their displayed timestamps. | Newest activity appears first; timestamps match Asia/Jakarta and do not shift unexpectedly because of browser or database timezone. | ✅ Pass | Screenshot of the collection response confirms `occurred_at` uses the `+07:00` offset and is ordered newest-first as Login, Logout, then Register. The UI renders the same activities at 20:34 WIB, 20:34 WIB, and 20:32 WIB respectively; repeated collection requests preserve the timestamps and ordering. |
| MQA-13 | Cursor pagination | Use a staging account with more than 20 activities created through normal login/logout flows.<br>Select `Muat Aktivitas Lainnya` until no next page remains.<br>Change a filter after loading at least two pages. | The first request returns 20 items; later pages append without duplicates; the button disappears when `has_more` is false; changing a filter discards the old cursor. | ✅ Pass | Screenshots confirm the first collection returns 20 items with `has_more: true`, a non-null `next_cursor`, and the `Muat Aktivitas Lainnya` action. Loading the cursor page appends the remaining five activities without visible duplicates, returns `has_more: false` and `next_cursor: null`, and removes the load-more action. Changing the event filter to Login starts a status `200` request with `event=auth.logged_in` and no old `cursor`; the collection is replaced by Login activities only. |

### Level 3 — High Risk: Authorization, Exposure, and Failure Handling

| ID | Scenario | Manual Steps | Expected Result | Status | Evidence |
| --- | --- | --- | --- | --- | --- |
| MQA-14 | Cross-account ownership | While signed in as Account B, record one audit UUID without copying credentials.<br>Sign in as Account A.<br>Use Edit and Resend on an Account A detail request and replace only its UUID with Account B's UUID. | The backend returns `404`; Account A cannot read Account B's event or full IP. | ✅ Pass | The dedicated feature test `test_detail_reveals_full_ip_only_to_the_owner` passed with four assertions: the owner can retrieve the detail and full IP, while another authenticated user receives `404` for the same audit UUID. The equivalent DevTools Edit and Resend flow was intentionally skipped because of its manual complexity; backend owner scoping remains covered by the automated test. |
| MQA-15 | Sensitive response fields | Inspect collection and detail responses in DevTools Network. | Collection IP is masked and detail IP is owner-scoped; responses do not expose `actor_clerk_user_id`, raw `user_agent`, `clerk_session_id`, `request_id`, `idempotency_key`, tokens, authorization headers, or cookies. | ✅ Pass | Collection and Detail screenshots confirm the response contract contains only the allow-listed activity fields. Collection returns the masked IP `127.0.xxx.xxx`; owner-scoped Detail initially remains masked in the UI and reveals `127.0.0.1` only after the explicit eye action. Neither response body exposes Clerk identifiers, raw user-agent, session or request identifiers, idempotency keys, tokens, authorization headers, or cookies. |
| MQA-16 | Invalid filter validation | In local or staging, use Edit and Resend with `event=auth.unknown`, then with `from` later than `to`, and then with `per_page=51`. | Each invalid request returns `422` without another user's data or an internal exception trace. | ✅ Pass | The isolated feature test `test_event_date_and_page_size_filters_are_validated` passed with nine assertions. Requests using `event=auth.unknown`, an inverted `from`/`to` range, and `per_page=51` each return `422` with the expected field validation error and no audit collection payload or internal exception trace. The equivalent manual Edit and Resend steps were replaced by automated coverage. |
| MQA-17 | Collection and detail error recovery | Temporarily block `/api/audit-logs` and reload the page.<br>Unblock and select `Coba Lagi`.<br>Repeat by blocking one detail request, then unblock and retry. | Initial and detail errors show recoverable UI states; retry restores data; already-loaded cards are not corrupted. | ✅ Pass | Screenshots confirm a blocked collection request produces the `Audit log gagal dimuat` state and a working `Coba Lagi` action. After request blocking is disabled, retry returns status `200` and restores the collection. Blocking an owner-scoped detail URL keeps the existing collection card intact while the modal displays `Detail aktivitas belum bisa dimuat` with its own retry action; disabling the rule and retrying restores the complete detail view without corrupting the loaded collection. |
| MQA-18 | Logout audit failure | In local or staging, block only `/api/auth/logout` in DevTools.<br>Use the normal Logout action, inspect session/local state, then unblock the endpoint. | The audit request fails, but Clerk sign-out, local cleanup, and redirect to Login still complete; no successful Logout activity is expected for the blocked request. | ✅ Pass | Screenshots confirm the request-blocking rule targeted only `/api/auth/logout`, the normal Logout action produced a `(blocked:devtools)` request, and the application still cleared the authenticated state and redirected to `/login`. After the rule was disabled, the user could authenticate again and open Audit Log; the collection contains the new Login at 22:50 WIB and the existing Register at 21:17 WIB, with no Logout activity created for the blocked request. |

### Completion Criteria

- All Level 1 and Level 2 scenarios pass.
- All Level 3 scenarios pass in local or staging.
- No unresolved console error, data leak, duplicate event, ownership bypass, or logout blocker remains.
- Visual issues include viewport size and screenshot evidence.
- API issues include status code and a redacted response, never credentials.

## Future Phases

The reusable foundation may later record security, profile, address, bank account, product, checkout, transaction, and financial actions. Those events require separate domain review and are intentionally not part of `TOK-1`.
