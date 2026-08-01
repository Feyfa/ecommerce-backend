# TOK-8 Pinpoint Address QA

## Purpose

This document is the canonical backend QA record for the TOK-8 address, cart
availability, and checkout contract. The matching UI checklist is tracked at
`frontend-repo:/docs/qa/tok-8-pinpoint-address.md`.

Provider responses, authorization, legacy-address enforcement, product
availability, and transaction snapshots are verified with automated tests
instead of manual request or database manipulation.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/AddressLocationTest.php tests/Feature/ProductAvailabilityTest.php tests/Feature/ProductListFilterTest.php tests/Feature/CheckoutTest.php tests/Feature/KeranjangServiceTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-8-BE-01 | ✅ | Store a valid buyer pinpoint and validate the client fields required to verify it. | Valid data is stored; missing coordinates or address detail is rejected, while provider-derived formatted address metadata replaces any client value. | `buyer_can_store_a_pinpoint_address`; `map_address_requires_coordinates_and_detail`; `server_uses_the_verified_address_instead_of_client_metadata` |
| TOK-8-BE-02 | ✅ | Submit manual or legacy-compatible payloads for a new or verified address. | New writes remain pinpoint-only and an existing verified address cannot revert to manual. | `existing_address_cannot_be_changed_back_to_manual`; `legacy_payload_without_location_source_is_rejected_for_new_addresses` |
| TOK-8-BE-03 | ✅ | Verify an overseas pinpoint, altered client metadata, and a provider failure. | Overseas data is rejected, verified provider data wins over client metadata, and provider failure causes no partial write. | `server_rejects_a_pinpoint_verified_outside_indonesia`; `server_uses_the_verified_address_instead_of_client_metadata`; `provider_failure_rejects_the_write_without_changing_existing_data` |
| TOK-8-BE-04 | ✅ | Select, update, or delete legacy and foreign buyer addresses. | Legacy manual addresses cannot be selected and one user cannot update or delete another user's address. | `buyer_cannot_select_a_legacy_manual_address`; `buyer_cannot_delete_another_users_address`; `buyer_cannot_update_another_users_address` |
| TOK-8-BE-05 | ✅ | Save a seller pinpoint with the required address detail. | The seller address is stored as a verified pinpoint with complete location data. | `seller_can_store_a_pinpoint_with_required_detail` |
| TOK-8-BE-06 | ✅ | Checkout with a legacy manual buyer or seller address. | Checkout is rejected before payment processing. | `checkout_rejects_an_active_legacy_manual_buyer_address`; `checkout_rejects_a_legacy_manual_seller_address` |
| TOK-8-BE-07 | ✅ | Complete checkout and then change the current master address. | Buyer and seller snapshots remain independent of later master-address changes. | `checkout_copies_buyer_and_seller_location_snapshots` |
| TOK-8-BE-08 | ✅ | Change an address after loading checkout and submit the stale snapshot. | Checkout detects the changed address and rejects stale processing. | `checkout_snapshot_detects_an_address_change` |

## Cart and Checkout Availability

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-8-BE-09 | ✅ | Load a stocked cart product owned by a seller without a verified address. | The product is marked with `SELLER_LOCATION_UNVERIFIED`, cannot be purchased, and retains its stored cart quantity. | `cart_marks_stocked_products_from_an_unverified_seller` |
| TOK-8-BE-10 | ✅ | Load a sold-out product whose seller location is also unverified. | `OUT_OF_STOCK` takes precedence as the unavailable reason and the item contributes nothing to the checked total. | `cart_uses_out_of_stock_before_unverified_seller_location` |
| TOK-8-BE-11 | ✅ | Reduce stock to `0` after a cart item is selected, then validate checkout. | Validation returns `CART_STOCK_CHANGED` with an `OUT_OF_STOCK` issue, clears the affected checked state, and preserves the stored quantity. | `cart_checkout_validation_unchecks_an_item_without_resetting_quantity` |
| TOK-8-BE-12 | ✅ | Soft-delete a product after it enters checkout, then refresh checkout data. | Checkout returns `CHECKOUT_INVALID`, clears checked and checkout state, and preserves the cart quantity for review. | `refreshing_checkout_rejects_a_soft_deleted_product_and_preserves_the_cart` |
| TOK-8-BE-13 | ✅ | Validate cart checkout when the buyer has no enabled address. | The request returns `400 BUYER_ADDRESS_REQUIRED` before checkout rows are updated and preserves the cart selection and quantity. | `checkout_validation_rejects_buyer_without_enabled_address_without_updating_checkout_rows` |
| TOK-8-BE-14 | ✅ | Load checkout data when the buyer has no checkout cart rows. | The request returns `CHECKOUT_INVALID` and does not proceed to payment preparation. | `checkout_data_rejects_buyer_without_checkout_rows_before_loading_payment_methods` |
| TOK-8-BE-15 | ✅ | Validate a matching set of checked, purchasable cart items with verified buyer and seller addresses. | Validation succeeds and only the checked cart rows are marked for checkout. | `checkout_validation_marks_only_matching_checked_rows_for_the_authenticated_buyer` |
| TOK-8-BE-16 | ✅ | Call plus, minus, and direct-change quantity endpoints after a cart product reaches stock `0`. | Every endpoint returns `409 OUT_OF_STOCK`, clears stale selection flags, and preserves the stored quantity. | `quantity_endpoints_reject_out_of_stock_products_without_changing_quantity` |
| TOK-8-BE-17 | ✅ | Inject `checked = 1` and `checkout = 1` for a stock-zero cart row, then load the cart. | The cart read repairs both flags to `0`, excludes the row from `totalPrice`, and preserves its stored quantity. | `cart_read_repairs_injected_out_of_stock_selection_without_resetting_quantity` |
| TOK-8-BE-18 | ✅ | Authenticate as one buyer and target another buyer id through every cart endpoint. | Every request returns `403 CART_FORBIDDEN` and neither buyer's cart is mutated. | `cart_endpoints_reject_an_authenticated_user_targeting_another_buyers_cart` |
| TOK-8-BE-19 | ✅ | Load an injected selected cart row whose saved quantity exceeds a positive current stock. | The response exposes `QUANTITY_EXCEEDS_STOCK`, marks the row non-selectable, repairs checked and checkout flags to `0`, excludes it from `totalPrice`, and preserves quantity. | `cart_read_exposes_and_repairs_a_quantity_that_exceeds_positive_stock` |
| TOK-8-BE-20 | ✅ | Validate a multi-seller checkout containing one quantity issue and one valid product, then retry with the valid product. | The first request returns structured `CART_STOCK_CHANGED`, only the invalid row is unchecked, and the second request marks only the valid row for checkout. | `stock_change_blocks_the_first_checkout_but_preserves_valid_multi_seller_items` |

## Public Store Identity

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-8-BE-21 | ✅ | Load and search the buyer catalog, load grouped cart or structured stock-issue data, and load Checkout for a seller whose account and company names differ. | Buyer-facing responses consistently prioritize `companies.name`, and catalog search recognizes the public company name. | `buyer_catalog_prioritizes_and_searches_the_store_name`; `stock_change_blocks_the_first_checkout_but_preserves_valid_multi_seller_items`; `checkout_groups_use_the_store_name_instead_of_the_seller_account_name` |

## Concurrency and Boundary Regressions

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-8-BE-22 | ✅ | Remove seller location verification after the buyer selects cart items or opens Checkout, then validate the cart, reload Checkout, and process payment. | All three boundaries return `409 SELLER_ADDRESS_REQUIRES_VERIFICATION`; the cart state is reconciled and no payment is created. | `cart_checkout_validation_reports_a_concurrently_unverified_seller`; `refreshing_checkout_reports_a_concurrently_unverified_seller`; `processing_checkout_reports_a_concurrently_unverified_seller_before_payment` |
| TOK-8-BE-23 | ✅ | Delete the active verified buyer address while only a legacy manual address remains. | The legacy row remains disabled instead of being promoted into an invalid checkout address. | `deleting_the_active_address_does_not_enable_a_legacy_manual_fallback` |
| TOK-8-BE-24 | ✅ | Give a dual-role user only an enabled seller address, then evaluate the buyer-address checkout gate. | The seller address does not satisfy the buyer shipping-address requirement. | `seller_address_does_not_count_as_an_enabled_buyer_address` |
| TOK-8-BE-25 | ✅ | Change cart quantity, product price, or the active buyer address after the initial snapshot but before locked checkout validation. | Locked validation rejects every stale snapshot before payment creation and requires the buyer to review current checkout state. | `locked_checkout_rejects_a_quantity_change_from_the_initial_snapshot`; `locked_checkout_rejects_a_price_change_from_the_initial_snapshot`; `locked_checkout_rejects_an_active_buyer_address_change` |
