# TOK-23 Company Audit Log QA

| ID           | Status | Scenario                                                               | Expected Result                                                                                                                          |
| ------------ | ------ | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| TOK-23-BE-01 | ✅     | Update name, phone, description, and store location.                   | One owner-scoped `company.updated` row stores only changed fields and the safe final snapshot without lat/lng/place id.                  |
| TOK-23-BE-02 | ✅     | Save unchanged Profil Toko values.                                     | One `company.updated` row is stored with an empty `changes` list.                                                                        |
| TOK-23-BE-03 | ✅     | Request collection and owner detail.                                   | Collection masks phone values; detail returns the full phone only for the owner.                                                         |
| TOK-23-BE-04 | ✅     | Submit invalid Profil Toko data.                                       | Request fails and no audit row is created.                                                                                               |
| TOK-23-BE-05 | ✅     | Upload, replace, then delete a company image.                          | Upload/replacement records `company.image_uploaded`; delete records `company.image_deleted`; no image path is saved.                     |
| TOK-23-BE-06 | ✅     | Force audit persistence to fail during a company mutation.             | The related database mutation rolls back.                                                                                                |

## Test Evidence

`php artisan test tests/Feature/CompanyAuditLogTest.php` passed with 6 tests
and 41 assertions.
