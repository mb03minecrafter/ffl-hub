<?php

namespace FFLHub\Distributor\Core;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Settings\Options;


use FFLHub\Distributor\Services\ProductSync\DistributorProductSyncCronService;

use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Services\Orders\OrderPlacementDispatchCronService;
use FFLHub\Distributor\Services\Orders\Shipping\OrderPlacementShippingPollCronService;

/**
 * Central place to build and expose distributor instances.
 *
 * Now builds distributors via module instances from DistributorRegistry.
 * Also handles enabling/disabling distributors (including starting/stopping services).
 */
class DistributorHandler
{
    /**
     * @var array<string, DistributorBase>
     */
    private array $distributors = [];

    private DistributorProductSyncCronService $productSyncCronService;
    private OrderPlacementShippingPollCronService $orderShippingCronService;
    private OrderPlacementDispatchCronService $orderPlacementCronService;

    public function __construct()
    {
        $this->register_distributors();
    }

    private function register_distributors(): void
    {
        foreach (DistributorRegistry::get_modules() as $module) {
            $dist = $module->build_distributor();
            $this->distributors[$module->id()] = $dist;
        }

        $this->productSyncCronService = new DistributorProductSyncCronService();
        $this->orderShippingCronService = new OrderPlacementShippingPollCronService();
        $this->orderPlacementCronService = new OrderPlacementDispatchCronService();
    }

    /**
     * Enable or disable a distributor (and start/stop its services).
     */
    public function set_enabled(string $id, bool $enabled): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }

        // Persist the state.
        Options::set_distributor_enabled($id, $enabled);

        // Retrieve the distributor.
        $dist = $this->distributors[$id] ?? null;
        if (! $dist) {
            return;
        }

        $services = $dist->get_services();
        if (! $services) {
            return;
        }

        // Hard start/stop cron + runtime services.
        if ($enabled) {
            $services->on_activate();
        } else {
            $services->on_deactivate();
        }
    }

    /**
     * @return array<string, DistributorBase>
     */
    public function get_distributors(): array
    {
        return $this->distributors;
    }

    public function get_distributor_by_id(string $id): ?DistributorBase
    {
        return $this->distributors[$id] ?? null;
    }

    public function on_activate(): void
    {
        foreach ($this->distributors as $id => $distributor) {
            // Only start enabled distributors on plugin activation.
            if (! Options::is_distributor_enabled($id)) {
                continue;
            }

            $services = $distributor->get_services();
            if ($services) {
                $services->on_activate();
            }
        }

        $this->productSyncCronService->on_activation();
        $this->orderShippingCronService->on_activation();
        $this->orderPlacementCronService->on_activation();
    }

    public function on_deactivate(): void
    {
        foreach ($this->distributors as $distributor) {
            $services = $distributor->get_services();
            if ($services) {
                $services->on_deactivate();
            }
        }

        $this->productSyncCronService->on_deactivation();
        $this->orderShippingCronService->on_deactivation();
        $this->orderPlacementCronService->on_deactivation();
    }

    public function register_runtime_services(): void
    {
        foreach ($this->distributors as $id => $distributor) {
            if (! Options::is_distributor_enabled($id)) {
                continue;
            }

            $services = $distributor->get_services();
            if ($services) {
                $services->register_runtime_services();
            }
        }

        $this->productSyncCronService->register();
        $this->orderShippingCronService->register();
        $this->orderPlacementCronService->register();
    }

    /**
     * Fetch normalized distributor payloads for a UPC across all distributors.
     *
     * @return array{
     *   carriers: array<string,array{label:string,payload:DistributorProductPayload,true_cost:?float,quantity:?int}>,
     *   cheapest_in_stock: ?array{product:DistributorProductPayload,true_cost:float,label:string,id:string,quantity:?int},
     *   cheapest_any: ?array{product:DistributorProductPayload,true_cost:float,label:string,id:string,quantity:?int}
     * }
     */

    public function get_payloads_for_upc(string $upc, bool $include_images = true): UpcLookupResult
    {
        $upc = trim($upc);
        if ($upc === '') {
            return new UpcLookupResult([]);
        }

        $offers = [];

        foreach ($this->distributors as $id => $distributor) {
            if (! Options::is_distributor_enabled($id)) {
                continue;
            }

            try {
                $offer = $distributor->get_offer_by_upc($upc, $include_images);
            } catch (\Throwable $e) {
                continue;
            }

            if ($offer instanceof DistributorOffer) {
                $offers[(string) $id] = $offer;
            }
        }

        return new UpcLookupResult($offers);
    }


    /**
     * @return array<string, array{instance: DistributorBase, label: string}>
     */
    public function get_enabled_distributors_for_validation(): array
    {
        $enabled_distributors = [];
        foreach ($this->get_distributors()  as $id => $d) { //CHANGED THIS
            $id = strtolower(trim((string) $id));
            if ($id === '') {
                continue;
            }
            if (!Options::is_distributor_enabled($id)) {
                continue;
            }
            if (!$d || !method_exists($d, 'validate_order_request')) {
                continue;
            }

            $enabled_distributors[$id] = [
                'instance' => $d,
                'label'    => method_exists($d, 'get_label') ? (string) $d->get_label() : $id,
            ];
        }

        return $enabled_distributors;
    }
}
