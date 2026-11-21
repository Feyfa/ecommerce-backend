# TOK-78 Xendit Response Contracts

Local QA covers the Xendit adapter, its PHPStan contracts, and the checkout and
withdrawal failure paths that consume its results.

| ID | Scenario | Local evidence |
| --- | --- | --- |
| TOK-78-BE-01 | Preserve the successful result envelope and decoded body across all four operations. | Passed in `XenditServiceTest`, including the legacy `suceess` value for VA creation. |
| TOK-78-BE-02 | Preserve provider strings and normalize unusable error messages. | Passed in `XenditServiceTest` with fake HTTP 400 responses for text, empty text, missing, null, array, and numeric messages, non-JSON, and empty bodies. |
| TOK-78-BE-03 | Preserve the invoice ID and payment identifiers on valid checkout. Reject unusable provider responses before writes. | Passed in `CheckoutTest`. Valid checkout preserves the VA's leading zeros and the payment reference. Scalar, null, malformed-error, missing-field, blank-field, and non-string cases return HTTP 400 and preserve the cart and stock, with no invoice or outbox row written. |
| TOK-78-BE-04 | Keep invoice and balance mutation behind the provider result check. | Passed in `PaymentControllerTest` and `PaymentReturnCallerCompatibilityTest`. A rejected fixed VA simulation leaves the invoice pending. A rejected payout returns HTTP 400 before the balance write or history insert. |
| TOK-78-BE-05 | Remove only the eight Xendit findings. | `composer analyse` passed. A second scan at the same level without the baseline reported 67 findings, down from 75. |

## Local Coverage

PHPUnit passed 326 tests with 4,654 assertions. The existing PostgreSQL
`ILIKE` test was skipped under SQLite. The pre-change run passed 308 tests
with 4,562 assertions and the same skip.

Before the payment field validation, all five new malformed-success cases
returned HTTP 200. After validation, they all return HTTP 400 and pass the
checkout state assertions.

The tests use PHP 8.3 and SQLite in memory. Guzzle responses are supplied
through its mock handler. The simulation controller test uses an adapter
mock. These checks exercise local behavior without live provider requests,
real credentials, or real funds.
