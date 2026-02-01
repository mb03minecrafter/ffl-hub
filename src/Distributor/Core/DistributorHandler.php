<?php

namespace FFLHub\Distributor\Core;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Services\Orders\Cron\OrderingCronService;
use FFLHub\Distributor\Services\Orders\OrderingOrchestratorService;
use FFLHub\Distributor\Services\Orders\OrderTrashJobsService;
use FFLHub\Distributor\Services\Orders\Shipping\Cron\ShippingCronService;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\ProductSync\DistributorProductSyncCronService;
use FFLHub\Settings\Options;

/**
 * Central registry/aggregator for distributor instances and distributor-wide services.
 *
 * Responsibilities:
 * - Build distributor instances from DistributorRegistry modules.
 * - Persist + apply enable/disable state per distributor.
 * - Delegate distributor activation/deactivation to each distributor's services.
 * - Own and register cross-distributor services:
 *   - Product sync cron (managed products inventory/price sync)
 *   - Order placement pipeline (jobs table + orchestrator + cron)
 *   - Shipping polling pipeline (cron)
 *   - Order trash/delete hooks (non-cron service)
 *
 * Non-responsibilities:
 * - Distributor-specific logic (that stays inside DistributorBase implementations).
 * - Business rules for pricing/validation (those are implemented in services/distributors).
 */
class DistributorHandler
{
    /**
     * Built distributor instances keyed by distributor id.
     *
     * @var array<string, DistributorBase>
     */
    private array $distributors = [];

    // ---------------------------------------------------------------------
    // Cross-distributor services: product sync
    // ---------------------------------------------------------------------
    private DistributorProductSyncCronService $productSyncCronService;

    // ---------------------------------------------------------------------
    // Cross-distributor services: order placement + shipping polling
    // ---------------------------------------------------------------------
    private OrderPlacementJobsSchema $orderSchema;

    /**
     * Jobs table is used widely (admin meta box, cron runners, etc),
     * so it is exposed publicly to avoid needless pass-through methods.
     */
    public OrderPlacementJobsTable $ordering_jobs_table;

    private OrderingOrchestratorService $orderPlacementOrchestratorService;
    private ShippingCronService $orderShippingCronService;
    private OrderingCronService $orderPlacementCronService;

    // ---------------------------------------------------------------------
    // Cross-distributor services: order trash hooks
    // ---------------------------------------------------------------------
    private OrderTrashJobsService $orderTrashJobsService;

    public function __construct()
    {
        // Build distributor instances from module registry first.
        $this->register_distributors();

        /**
         * Product sync cron:
         * requires handler so it can resolve enabled distributors and call into them.
         */
        $this->productSyncCronService = new DistributorProductSyncCronService($this);

        // Order placement jobs table + related pipeline services.
        $this->orderSchema        = new OrderPlacementJobsSchema();
        $this->ordering_jobs_table = new OrderPlacementJobsTable($this->orderSchema);

        $this->orderPlacementOrchestratorService = new OrderingOrchestratorService($this->ordering_jobs_table);

        // Cron services require handler to reach distributor implementations.
        $this->orderPlacementCronService = new OrderingCronService($this, $this->ordering_jobs_table);
        $this->orderShippingCronService  = new ShippingCronService($this, $this->ordering_jobs_table);

        // Order trash hooks (not cron-based; no scheduling lifecycle needed).
        $this->orderTrashJobsService = new OrderTrashJobsService($this->ordering_jobs_table);
    }

    /**
     * Build distributors from the module registry.
     *
     * DistributorRegistry is the authoritative list of available modules.
     * Each module builds its DistributorBase implementation and provides an id().
     */
    private function register_distributors(): void
    {
        foreach (DistributorRegistry::get_modules() as $module) {
            $dist = $module->build_distributor();
            $this->distributors[$module->id()] = $dist;
        }
    }

    /**
     * Enable or disable a distributor, and start/stop its services immediately.
     *
     * Behavior:
     * - Persists enable state to wp_options via Options.
     * - If distributor exists and exposes services, calls services->on_activate() or on_deactivate().
     */
    public function set_enabled(string $id, bool $enabled): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }

        // Persist the state.
        Options::set_distributor_enabled($id, $enabled);

        // Retrieve distributor instance.
        $dist = $this->distributors[$id] ?? null;
        if (!$dist) {
            return;
        }

        $services = $dist->get_services();
        if (!$services) {
            return;
        }

        // Hard start/stop cron + runtime services for this distributor.
        if ($enabled) {
            $services->on_activate();
        } else {
            $services->on_deactivate();
        }
    }

    /**
     * Get all built distributor instances (enabled or not).
     *
     * @return array<string, DistributorBase>
     */
    public function get_distributors(): array
    {
        return $this->distributors;
    }

    /**
     * Get a distributor by id (enabled state is not checked here).
     */
    public function get_distributor_by_id(string $id): ?DistributorBase
    {
        return $this->distributors[$id] ?? null;
    }

    /**
     * Plugin activation hook handler for distributor subsystem.
     *
     * Responsibilities:
     * - Activate services for distributors that are enabled in Options.
     * - Activate cross-distributor cron services and create required tables.
     */
    public function on_activate(): void
    {
        foreach ($this->distributors as $id => $distributor) {
            // Only start enabled distributors on plugin activation.
            if (!Options::is_distributor_enabled($id)) {
                continue;
            }

            $services = $distributor->get_services();
            if ($services) {
                $services->on_activate();
            }
        }

        // Cross-distributor services.
        $this->productSyncCronService->on_activation();

        // Jobs table must exist before cron runners operate.
        $this->ordering_jobs_table->createTables();

        $this->orderShippingCronService->on_activation();
        $this->orderPlacementCronService->on_activation();

        // OrderTrashJobsService is hook-based (not cron-based); no schedule lifecycle.
    }

    /**
     * Plugin deactivation hook handler for distributor subsystem.
     *
     * Responsibilities:
     * - Deactivate all distributors (regardless of enabled state) to ensure
     *   scheduled tasks are unscheduled and hooks are cleaned up.
     * - Deactivate cross-distributor cron services.
     */
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

    /**
     * Register runtime services for enabled distributors and cross-distributor services.
     *
     * This should be called during normal boot (Plugin::instance()) so the system
     * hooks are active during web requests, and cron hooks can run.
     */
    public function register_runtime_services(): void
    {
        foreach ($this->distributors as $id => $distributor) {
            if (!Options::is_distributor_enabled($id)) {
                continue;
            }

            $services = $distributor->get_services();
            if ($services) {
                $services->register_runtime_services();
            }
        }

        // Cross-distributor services.
        $this->productSyncCronService->register();

        $this->orderPlacementOrchestratorService->register();
        $this->orderShippingCronService->register();
        $this->orderPlacementCronService->register();

        $this->orderTrashJobsService->register();
    }

    /**
     * Fetch normalized distributor offers for a UPC across all enabled distributors.
     *
     * Notes:
     * - Distributors may throw; failures are ignored to keep lookup resilient.
     * - Returned UpcLookupResult encapsulates selection logic (cheapest/in-stock/etc).
     */
    public function get_payloads_for_upc(string $upc, bool $include_images = true): UpcLookupResult
    {
        $upc = trim($upc);
        if ($upc === '') {
            return new UpcLookupResult([]);
        }

        $offers = [];

        foreach ($this->distributors as $id => $distributor) {
            if (!Options::is_distributor_enabled($id)) {
                continue;
            }

            try {
                $offer = $distributor->get_offer_by_upc($upc, $include_images);
            } catch (\Throwable $e) {
                // Defensive: individual distributor failures should not break lookup.
                continue;
            }

            if ($offer instanceof DistributorOffer) {
                $offers[(string) $id] = $offer;
            }
        }

        return new UpcLookupResult($offers);
    }

    /**
     * Return enabled distributors that participate in order request validation.
     *
     * This is used by cart/order compliance flows that need to:
     * - iterate enabled distributors
     * - call validate_order_request (if implemented)
     *
     * @return array<string, array{instance: DistributorBase, label: string}>
     */
    public function get_enabled_distributors_for_validation(): array
    {
        $enabled_distributors = [];

        foreach ($this->get_distributors() as $id => $d) {
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
