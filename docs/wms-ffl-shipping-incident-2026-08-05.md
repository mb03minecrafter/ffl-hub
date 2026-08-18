# WMS FFL Shipping Incident - 2026-08-05

## Summary

On 2026-08-05, WMS wave `32` purchased shipping labels for three dealer-fulfilled firearm orders using the customer shipping address instead of the selected FFL address.

The affected Woo orders were:

- `34332`
- `34244`
- `34114`

All three order placement jobs were correctly marked as FFL-required dealer-fulfilled jobs. The failure happened later in the WMS packing/label path.

## Distributor Source

The affected order placement rows were CSSI dealer-fulfilled rows:

- Order `34332`: job `918`, `cssi|dealer_fulfilled`, UPC `764503068225`, `ffl_required = 1`
- Order `34244`: job `890`, `cssi|dealer_fulfilled`, UPC `764503068225`, `ffl_required = 1`
- Order `34114`: job `856`, `cssi|dealer_fulfilled`, UPC `764503073519`, `ffl_required = 1`

CSSI was not the source of the bad non-FFL value. CSSI distributor offer rows for these UPCs had `ffl_required = 1`.

The bad value came from our own product state / best offer fallback path.

## What Went Wrong

The order placement job payload correctly knew each line was FFL-required.

However, the old WMS packing path used the order job only to decide whether an item belonged in the dealer-fulfilled packing flow. It then separately asked product state whether the product required an FFL.

At the time the wave was packed, the relevant product state / best offer path could represent a product as a no-offer row:

- blank `distributor_id`
- `selection_status = no_offer`
- default `ffl_required = 0`

That default zero was treated as "confirmed non-FFL" instead of "unknown / no selected offer."

The WMS wave then persisted `ffl_required: 0` into:

- `packages_json`
- `package_items_json`

The shipping label purchase path trusted that saved WMS package data, so it built non-FFL shipments to the Woo customer address.

## Important Distinction

This was not a CSSI feed problem.

This was a source-of-truth problem inside FFL Hub:

- Order placement job payload: correct, FFL-required
- Distributor offers: correct, FFL-required
- WMS package snapshot: incorrect, non-FFL
- Label destination: incorrect, customer address

The WMS and shipping systems trusted mutable product/package state instead of the immutable order job payload.

## Why Product State Could Say Non-FFL

The old behavior allowed an empty/no-offer product state row to degrade into `ffl_required = 0`.

For compliance-critical fields, this is wrong. A zero can mean two very different things:

- confirmed non-FFL item
- unknown because no offer / no selected row / stale state

For firearms, unknown must never be treated as non-FFL.

## Code Timing Note

The WMS wave was created before later hardening that made product-state FFL checks more conservative.

Later changes added offer-level fallback logic so that if any known distributor offer says a UPC requires FFL, product-level FFL checks can return true even when product state is empty or no-offer.

That helped, but it still was not enough as the final architecture. Placed orders should not depend on product state for FFL-required status.

## Correct Rule Going Forward

For placed orders, FFL-required status must come from the order placement job payload.

Product state can be used for catalog/product browsing and as a fallback for unplaced products, but it must not be the authority for already-placed order compliance.

Hard invariant:

> If a dealer-fulfilled order job payload says any package item is FFL-required, the only valid shipping destination is the selected FFL.

## Required Guardrails

1. WMS packing must derive FFL-required status from dealer-fulfilled order job payload lines.
2. Label purchase must re-check the order job payload before buying any label.
3. If the order job says FFL-required, destination must be forced to the selected FFL.
4. If the selected FFL is missing, invalid, or cannot be resolved, label purchase must fail closed.
5. Saved package JSON must not be the only authority for compliance-critical flags.
6. Product state `0` must not override order job `ffl_required = 1`.
7. FastBound disposition destination and shipping label destination should be reconciled before shipment confirmation.
8. Any mismatch between distributor offers saying FFL and product_state/best_offers saying non-FFL should be audited.

## Permanent Regression Cases

These orders should be used as regression examples for future WMS/shipping changes:

- `34332`
- `34244`
- `34114`

Expected behavior for all three:

- package item is treated as FFL-required
- destination is selected FFL, not Woo customer address
- label cannot be purchased to customer address
- packing UI requires serial number handling
- shipping confirmation must not proceed if FFL checks fail

## Current Understanding

Root cause:

The WMS packing path allowed product_state/no-offer fallback data to overwrite the order job's correct FFL-required truth. That bad value was then saved into WMS package JSON and trusted by label buying.

Correct fix direction:

Order job payload should be the authoritative source for placed-order FFL status. Product state should be treated as mutable product/catalog data, not compliance truth for shipped orders.
