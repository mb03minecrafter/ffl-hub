# FFL Hub ShipStation API V2 Labels

FFL Hub includes an admin-only ShipStation API v2 label workflow for individual WooCommerce orders.

## Configure

1. Go to **FFL Hub > ShipStation Labels**.
2. Enable the integration.
3. Choose `sandbox` or `production`.
4. Add the sandbox and/or production API key. The selected mode controls which key is used for carrier refreshes, rates, and label purchases.
5. Enter the default origin address.
6. Save, then click **Refresh / Test API Connection** to cache connected carrier accounts.
7. Enable carrier accounts for ordinary shipments.
8. Explicitly check firearm-approved carriers for FFL shipments.

API key precedence:

1. Mode-specific PHP constant: `FFLHUB_SHIPSTATION_SANDBOX_API_KEY` or `FFLHUB_SHIPSTATION_PRODUCTION_API_KEY`
2. Mode-specific environment variable with the same name
3. Generic `FFLHUB_SHIPSTATION_API_KEY` PHP constant
4. Generic `FFLHUB_SHIPSTATION_API_KEY` environment variable
5. Saved WordPress option for the selected mode

The complete key is never printed in HTML, JavaScript, REST responses, logs, or order notes.

## Order Workflow

On a WooCommerce order, use **FFL Hub - ShipStation Labels**:

1. Review the ship-from address.
2. Review the ship-to address.
3. For FFL orders, confirm the receiving FFL premise address is being used.
4. Enter package weight and dimensions. FFL Hub starts with product-state shipping data when available, but missing dimensions must be entered manually.
5. Click **Get Rates**.
6. Compare all valid rates. Totals include shipping, confirmation, insurance, and other charges.
7. Select one rate and click **Purchase Selected Label**.
8. Open/download the label from the order.
9. Void an unused label from the same panel when needed.

FFL shipments use the firearm-approved carrier allowlist. If no firearm allowlist is configured yet, the temporary conservative default is USPS/Stamps.com carrier accounts only.

## Stored Order Metadata

FFL Hub stores label history in `_fflhub_ss_labels` and also updates the latest-label convenience keys:

- `_fflhub_ss_shipment_id`
- `_fflhub_ss_rate_id`
- `_fflhub_ss_label_id`
- `_fflhub_ss_carrier_id`
- `_fflhub_ss_carrier_code`
- `_fflhub_ss_service_code`
- `_fflhub_ss_tracking_number`
- `_fflhub_ss_tracking_url`
- `_fflhub_ss_label_format`
- `_fflhub_ss_label_layout`
- `_fflhub_ss_label_url`
- `_fflhub_ss_shipping_cost`
- `_fflhub_ss_insurance_cost`
- `_fflhub_ss_total_cost`
- `_fflhub_ss_label_status`
- `_fflhub_ss_purchased_at`
- `_fflhub_ss_voided_at`
- `_fflhub_ss_shipment_snapshot`

Purchased label costs are included in the existing FFL Hub order profit audit shipping-label total. Voided labels stay in history but are ignored by the audit.

## Filters

Future shipment policy can hook:

- `fflhub_shipstation_shipment_context`
- `fflhub_shipstation_eligible_carrier_ids`
- `fflhub_shipstation_rate_request`
- `fflhub_shipstation_rate_results`

## API Endpoints Used

- `GET /v2/carriers`
- `POST /v2/rates`
- `POST /v2/labels/rates/{rate_id}`
- `PUT /v2/labels/{label_id}/void`
- `POST /v2/addresses/validate`

Base URL: `https://api.shipstation.com`

## Sandbox Smoke Test

1. Configure a sandbox API key.
2. Refresh carriers.
3. Open a non-FFL order.
4. Confirm enabled connected carriers are eligible.
5. Enter package details.
6. Get rates.
7. Purchase a sandbox label.
8. Confirm tracking/meta/order note/profit audit updated.
9. Void the sandbox label.
10. Open an FFL order and confirm the FFL destination and firearm carrier restriction.
