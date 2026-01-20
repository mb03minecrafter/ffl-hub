<?php

namespace FFLHub\Distributor\Lipseys;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\DistributorBase;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\Lipseys\LipseysServices;
use FFLHub\Distributor\DistributorModuleInterface;

use FFLHub\Distributor\Product\DistributorOrderRequest;
use FFLHub\Distributor\Product\DistributorOrderResult;
use FFLHub\Distributor\Product\DistributorOrderLine;
use FFLHub\Distributor\Product\DistributorShipTo;

/**
 * Lipsey's distributor implementation.
 *
 * Uses the official Lipsey's PHP client (lipseys/apiintegration) if it is available.
 */
class DistributorLipseys extends DistributorBase
{
    public function __construct(DistributorModuleInterface $module, ?LipseysServices $services = null)
    {
        parent::__construct($module, $services);
    }

    /**
     * Lipsey's image resolver override.
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $image_name = $this->get_string_field($row, $field);
        if ($image_name === '') {
            return '';
        }

        return 'https://www.lipseyscloud.com/images/' . $image_name;
    }

    /**
     * Look up a single product by UPC using the Lipsey's LIVE fulfillment table.
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (! $row) {
            return null;
        }

        return $this->build_payload_from_row(
            $row,
            [
                'sku'          => ['lipseys_item_number'],
                'upc'          => ['upc'],
                'name'         => ['manufacturer', 'model', 'caliber_gauge'],
                'description'  => ['product_description'],
                'price'        => ['distributor_price'],
                'map'          => ['retail_map'],
                'msrp'         => ['retail_msrp'],
                'quantity'     => ['inventory_quantity'],
                'category'     => ['item_group'],
                'image'        => ['image_name'],
                'ffl_required' => ['ffl_required'],
            ],
            [DistributorProductCategoryMapper::class, 'map_lipseys'],
            $normalized_upc,
            true
        );
    }

    /**
     * Flat-rate Lipsey's shipping.
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        return 10.0;
    }

    /**
     * Place Lipsey's orders.
     *
     * Your procurement layer can pass ONE request containing mixed lines (FFL + non-FFL).
     * Lipsey's requires two separate endpoints, so we split internally:
     *  - non-FFL lines => DropShipAccessories
     *  - FFL lines     => DropShipFirearms
     *
     * IMPORTANT:
     * Lipsey's ordering endpoints require ItemNo (Lipsey's item number), so we map:
     *   UPC -> lipseys_item_number via our Lipsey's fulfillment table.
     */
    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (! class_exists('\\lipseys\\ApiIntegration\\LipseysClient')) {
            return new DistributorOrderResult(false, 'Lipseys API client not available (lipseys/apiintegration).', []);
        }

        if (! $this->services) {
            return new DistributorOrderResult(false, 'Lipseys services not available; cannot access fulfillment table.', []);
        }

        if (empty($request->lines)) {
            return new DistributorOrderResult(false, 'No order lines provided.', []);
        }

        $email = $this->get_dealer_email();
        $password = $this->get_dealer_password();

        if ($email === '' || $password === '') {
            return new DistributorOrderResult(false, 'Missing Lipsey’s credentials (dealer_email / dealer_password).', []);
        }

        // Split into two Lipsey's orders based on ffl_required.
        $items_non = [];
        $items_ffl = [];

        foreach ($request->lines as $line) {
            if (! ($line instanceof DistributorOrderLine)) {
                continue;
            }

            $upc = trim($line->upc);
            if ($upc === '') {
                continue;
            }

            $item_no = $this->lookup_item_number_by_upc($upc);
            if ($item_no === null || $item_no === '') {
                return new DistributorOrderResult(false, 'Cannot map UPC to Lipsey’s item number: ' . $upc, []);
            }

            $item = [
                'ItemNo'   => $item_no,
                'Quantity' => max(1, (int) $line->quantity),
            ];

            if ($line->ffl_required) {
                $items_ffl[] = $item;
            } else {
                $items_non[] = $item;
            }
        }

        if (empty($items_non) && empty($items_ffl)) {
            return new DistributorOrderResult(false, 'No valid line items after normalization.', []);
        }

        try {
            $client = new \lipseys\ApiIntegration\LipseysClient($email, $password);
        } catch (\Throwable $e) {
            return new DistributorOrderResult(false, 'Failed to initialize Lipsey’s client: ' . $e->getMessage(), []);
        }

        $external_ids = [];
        $errors = [];

        // Use merchant_order_id as the base PO identifier.
        // Lipsey's client validates ZIP/state/etc; keep PO short-ish and deterministic.
        $base_po = self::sanitize_po((string) $request->merchant_order_id);
        if ($base_po === '') {
            $base_po = 'FFLHUB';
        }

        // 1) Non-FFL -> DropShipAccessories
        if (! empty($items_non)) {
            $po = $base_po . '-NON';

            $ship = $request->ship_to_customer;
            $payload = [
                // Billing (Lipsey's requires these; we use customer info)
                'BillingName'          => self::safe($ship->name),
                'BillingAddressLine1'  => self::safe($ship->address1),
                'BillingAddressCity'   => self::safe($ship->city),
                'BillingAddressState'  => self::state2($ship->state),
                'BillingAddressZip'    => self::zip5($ship->zip),

                // Shipping
                'ShippingName'         => self::safe($ship->name),
                'ShippingAddressLine1' => self::safe($ship->address1),
                'ShippingAddressCity'  => self::safe($ship->city),
                'ShippingAddressState' => self::state2($ship->state),
                'ShippingAddressZip'   => self::zip5($ship->zip),

                'PoNumber'             => $po,
                'Items'                => $items_non,
            ];

            $resp = $client->DropShipAccessories($payload);
            $norm = $this->normalize_lipseys_response($resp, $po, 'DropShipAccessories');

            if ($norm['ok']) {
                $external_ids[] = $norm['external_id'];
            } else {
                $errors[] = $norm['message'];
            }
        }

        // 2) FFL -> DropShipFirearms
        if (! empty($items_ffl)) {
            // Need receiving FFL number + ship_to_ffl for name/phone.
            $ffl_num = strtoupper(trim((string) $request->receiving_ffl_number));
            if ($ffl_num === '') {
                $errors[] = 'Missing receiving FFL number for Lipsey’s firearm order.';
            } elseif (! ($request->ship_to_ffl instanceof DistributorShipTo)) {
                $errors[] = 'Missing receiving FFL ship-to address for Lipsey’s firearm order.';
            } else {
                $po = $base_po . '-FFL';

                $ffl_ship = $request->ship_to_ffl;
                $phone = trim((string) $ffl_ship->phone);
                if ($phone === '') {
                    $phone = trim((string) $request->ship_to_customer->phone);
                }

                $payload = [
                    'Ffl'   => $ffl_num,
                    'Name'  => self::safe($ffl_ship->name),
                    'Phone' => self::safe($phone),
                    'Items' => $items_ffl,
                ];

                $resp = $client->DropShipFirearms($payload);
                $norm = $this->normalize_lipseys_response($resp, $po, 'DropShipFirearms');

                if ($norm['ok']) {
                    $external_ids[] = $norm['external_id'];
                } else {
                    $errors[] = $norm['message'];
                }
            }
        }

        // If ANY required subset failed, treat overall as failure.
        if (! empty($errors)) {
            $msg = 'Lipseys order failed: ' . implode(' | ', $errors);
            return new DistributorOrderResult(false, $msg, $external_ids);
        }

        return new DistributorOrderResult(true, 'Lipseys order submitted.', $external_ids);
    }

    /**
     * Map UPC -> lipseys_item_number using the LIVE fulfillment table.
     */
    private function lookup_item_number_by_upc(string $upc): ?string
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized);
        if (! $row) {
            return null;
        }

        $item_no = $this->get_string_field($row, ['lipseys_item_number']);
        $item_no = trim((string) $item_no);

        return $item_no !== '' ? $item_no : null;
    }

    private function get_dealer_email(): string
    {
        return trim((string) get_option($this->get_option_name('dealer_email'), ''));
    }

    private function get_dealer_password(): string
    {
        return trim((string) get_option($this->get_option_name('dealer_password'), ''));
    }

    /**
     * Normalize Lipsey’s response shape (their client returns arrays with authorized/success/errors).
     *
     * @param mixed $resp
     * @return array{ok:bool,message:string,external_id:string}
     */
    private function normalize_lipseys_response($resp, string $po, string $op): array
    {
        if (! is_array($resp)) {
            return [
                'ok' => false,
                'message' => "{$op} returned non-array response.",
                'external_id' => '',
            ];
        }

        $authorized = isset($resp['authorized']) ? (bool) $resp['authorized'] : true;
        $success = isset($resp['success']) ? (bool) $resp['success'] : false;

        if (! $authorized) {
            return [
                'ok' => false,
                'message' => "{$op} not authorized.",
                'external_id' => '',
            ];
        }

        if (! $success) {
            $errors = '';
            if (isset($resp['errors']) && is_array($resp['errors'])) {
                $errors = implode(' | ', array_map('strval', $resp['errors']));
            }
            if ($errors === '') {
                $errors = 'Unknown error';
            }

            return [
                'ok' => false,
                'message' => "{$op} failed: {$errors}",
                'external_id' => '',
            ];
        }

        // Lipsey’s responses vary; if we can’t find an order id, store PO.
        $external = '';
        foreach (['orderNumber', 'OrderNumber', 'order_no', 'OrderNo'] as $k) {
            if (isset($resp[$k]) && is_string($resp[$k]) && trim($resp[$k]) !== '') {
                $external = trim($resp[$k]);
                break;
            }
        }
        if ($external === '') {
            $external = $po;
        }

        return [
            'ok' => true,
            'message' => "{$op} OK",
            'external_id' => $external,
        ];
    }

    private static function zip5(string $zip): string
    {
        $zip = trim($zip);
        if ($zip === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $zip);
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 5);
    }

    private static function state2(string $state): string
    {
        $s = strtoupper(trim($state));
        return preg_match('/^[A-Z]{2}$/', $s) ? $s : $s;
    }

    private static function sanitize_po(string $po): string
    {
        $po = trim($po);
        if ($po === '') {
            return '';
        }

        // Keep letters, numbers, dash; Lipsey’s usually tolerates this well.
        $po = preg_replace('/[^A-Za-z0-9\-]+/', '-', $po);
        $po = is_string($po) ? $po : '';
        $po = trim($po, '-');

        // Keep it reasonable in length.
        if (strlen($po) > 24) {
            $po = substr($po, 0, 24);
        }

        return $po;
    }

    private static function safe(string $s): string
    {
        return trim((string) $s);
    }
}
