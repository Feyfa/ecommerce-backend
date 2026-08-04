# TOK-22 Profile Audit Log QA

| ID           | Status | Scenario                                                               | Expected Result                                                                                                      |
| ------------ | ------ | ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| TOK-22-BE-01 | ✅     | Update phone, birth date, and gender.                                  | One owner-scoped `profile.updated` row stores only changed fields and the safe final snapshot.                       |
| TOK-22-BE-02 | ✅     | Save unchanged Pengaturan Pengguna values.                             | One `profile.updated` row is stored with an empty `changes` list.                                                    |
| TOK-22-BE-03 | ✅     | Request collection and owner detail.                                   | Collection masks phone values; detail returns the full phone only for the owner.                                     |
| TOK-22-BE-04 | ✅     | Submit invalid data or another user's route ID.                        | Request fails and no audit row is created.                                                                           |
| TOK-22-BE-05 | ✅     | Upload, replace, then delete a profile image.                          | Upload/replacement records `profile.image_uploaded`; delete records `profile.image_deleted`; no image path is saved. |
| TOK-22-BE-06 | ✅     | Force audit persistence to fail during a profile mutation.             | The related database mutation rolls back.                                                                            |

## Test Evidence

`php artisan test tests/Feature/ProfileAuditLogTest.php` passed with 5 tests
and 32 assertions. The test runner also reported one existing PHP deprecation
from the Laravel/vendor stack; it did not fail any TOK-22 scenario.
