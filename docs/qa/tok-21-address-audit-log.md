# TOK-21 Address Audit Log QA

## Purpose

This document is the canonical backend QA record for TOK-21 buyer address audit
events. The matching UI checklist is tracked at
`frontend-repo:/docs/qa/tok-21-address-audit-log.md`.

Automated coverage owns event persistence, snapshots, personal-data masking,
authorization, and rollback. Keep any additional manual evidence redacted; never
capture credentials, authorization headers, or full pinpoint coordinates.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/AddressAuditLogTest.php tests/Feature/AuditLogTest.php tests/Feature/ProductAuditLogTest.php
```

`phpunit.xml` runs these tests on SQLite in memory, while CI runs them on
PostgreSQL. PostgreSQL `jsonb` does not preserve object-key order, so any strict
assertion on a stored audit context must rebuild the payload in the contract
order first. Assertions that depend on raw key order pass locally and fail only
in CI.

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-21-BE-01 | ✅ | Add a buyer address through the pinpoint flow. | One owner-scoped `address.created` event stores the permitted snapshot. | `successful_create_records_an_owner_scoped_address_snapshot` |
| TOK-21-BE-02 | ✅ | Inspect the stored context and both audit endpoints. | Latitude, longitude, and the Geoapify place id never reach the audit context or any response. | `coordinates_are_never_stored_or_exposed` |
| TOK-21-BE-03 | ✅ | Update address values. | One `address.updated` event records only the fields that really changed. | `update_records_only_real_value_changes` |
| TOK-21-BE-04 | ✅ | Submit an identical successful update. | One stable event is recorded with an empty change list instead of invented changes. | `identical_update_is_recorded_without_false_changes` |
| TOK-21-BE-05 | ✅ | Delete an address and retrieve its audit detail. | The final snapshot remains readable after the address row is gone. | `delete_keeps_the_last_snapshot_after_the_address_is_gone` |
| TOK-21-BE-06 | ✅ | Delete the active address while a verified fallback exists. | The event records the replacement address chosen by the system. | `delete_records_the_replacement_address_chosen_by_the_system` |
| TOK-21-BE-07 | ✅ | Select a different main address. | One `address.selected` event records both the new and the previous main address. | `selecting_a_main_address_records_the_previous_one` |
| TOK-21-BE-08 | ✅ | Compare the collection and detail endpoints. | Collection masks the phone and recipient name and omits the address detail; the owner-scoped detail route returns the full values. | `collection_masks_personal_data_while_detail_reveals_it` |
| TOK-21-BE-09 | ✅ | Read an update event from the collection endpoint. | Before/after values that contain personal data are masked in the collection response. | `collection_masks_change_rows_that_contain_personal_data` |
| TOK-21-BE-10 | ✅ | Submit foreign-address writes and an invalid payload. | No audit row is created and no address owned by another buyer is mutated. | `foreign_address_and_failed_validation_do_not_create_audit_rows` |
| TOK-21-BE-11 | ✅ | Force audit persistence failure during an address update. | The address mutation rolls back and no audit row remains. | `audit_failure_rolls_back_the_address_mutation` |
| TOK-21-BE-12 | ✅ | Run the existing authentication and product audit suites. | The shared category dispatcher keeps previous audit responses unchanged. | `Tests\\Feature\\AuditLogTest`; `Tests\\Feature\\ProductAuditLogTest` |

## Manual Notes

Manual verification is not required for the backend contract because every rule
above is covered by automated tests. Any additional manual check must redact
personal data before it is recorded here.
