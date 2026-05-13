<?php

namespace FFLHub\Distributor\Core;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Services\Orders\Cron\LipseysCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\LipseysDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\OrderingCronService;
use FFLHub\Distributor\Services\Orders\Cron\OrionCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\OrionDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\RSRDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\SportsSouthCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\SportsSouthDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\ZandersCaRelayBatchCronService;
use FFLHub\Distributor\Services\Orders\Cron\ZandersDealerBatchCronService;
use FFLHub\Distributor\Services\Orders\OrderingOrchestratorService;
use FFLHub\Distributor\Services\Orders\OrderTrashJobsService;
use FFLHub\Distributor\Services\Orders\Shipping\Cron\DealerFulfilledCronService;
use FFLHub\Distributor\Services\Orders\Shipping\Cron\ShippingCronService;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\ProductSync\DistributorProductSyncCronService;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

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
    private DealerFulfilledCronService $orderDealerFulfilledCronService;
    private OrderingCronService $orderPlacementCronService;
    private RSRDealerBatchCronService $rsrDealerBatchCronService;
    private LipseysDealerBatchCronService $lipseysDealerBatchCronService;
    private OrionDealerBatchCronService $orionDealerBatchCronService;
    private SportsSouthDealerBatchCronService $sportsSouthDealerBatchCronService;
    private ZandersDealerBatchCronService $zandersDealerBatchCronService;
    private LipseysCaRelayBatchCronService $lipseysCaRelayBatchCronService;
    private OrionCaRelayBatchCronService $orionCaRelayBatchCronService;
    private SportsSouthCaRelayBatchCronService $sportsSouthCaRelayBatchCronService;
    private ZandersCaRelayBatchCronService $zandersCaRelayBatchCronService;

    // ---------------------------------------------------------------------
    // Cross-distributor services: order trash hooks
    // ---------------------------------------------------------------------
    private OrderTrashJobsService $orderTrashJobsService;



    // ---------------------------------------------------------------------
    // FFL Table
    // ---------------------------------------------------------------------
    private FFLTable $ffl_table;



    private const DEBUG_CONST = 'FFLHUB_DEBUG_BOOT';
    private const LOG_PREFIX  = '[DistributorHandler]';

    public function __construct(FFLTable $ffl_table)
    {
        $t0 = microtime(true);
        $t_last = $t0;
        $step = 0;

        $log_step = function (string $label, array $extra = []) use (&$t_last, $t0, &$step): void {
            $now = microtime(true);
            $delta_ms = ($now - $t_last) * 1000.0;
            $since_ms = ($now - $t0) * 1000.0;
            $t_last = $now;
            $step++;

            DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, 'BOOT STEP', array_merge([
                'step' => $step,
                'label' => $label,
                'elapsed_ms' => round($delta_ms, 3),
                'since_start_ms' => round($since_ms, 3),
                'mem_kb' => (int) (memory_get_usage(true) / 1024),
            ], $extra));
        };

        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, 'BOOT START', [
            'mem_kb' => (int) (memory_get_usage(true) / 1024),
        ]);

        $this->ffl_table = $ffl_table;
        $log_step('assign ffl_table');

        $this->register_distributors();
        $log_step('register_distributors', [
            'distributor_count' => count($this->distributors),
        ]);

        $this->productSyncCronService = new DistributorProductSyncCronService($this);
        $log_step('new DistributorProductSyncCronService');

        $this->orderSchema = new OrderPlacementJobsSchema();
        $log_step('new OrderPlacementJobsSchema');

        $this->ordering_jobs_table = new OrderPlacementJobsTable($this->orderSchema);
        $log_step('new OrderPlacementJobsTable');

        $this->orderPlacementOrchestratorService = new OrderingOrchestratorService($this->ordering_jobs_table);
        $log_step('new OrderingOrchestratorService');

        $this->orderPlacementCronService = new OrderingCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new OrderingCronService');

        $this->rsrDealerBatchCronService = new RSRDealerBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new RSRDealerBatchCronService');

        $this->lipseysDealerBatchCronService = new LipseysDealerBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new LipseysDealerBatchCronService');

        $this->orionDealerBatchCronService = new OrionDealerBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new OrionDealerBatchCronService');

        $this->sportsSouthDealerBatchCronService = new SportsSouthDealerBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new SportsSouthDealerBatchCronService');

        $this->zandersDealerBatchCronService = new ZandersDealerBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new ZandersDealerBatchCronService');

        $this->lipseysCaRelayBatchCronService = new LipseysCaRelayBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new LipseysCaRelayBatchCronService');

        $this->orionCaRelayBatchCronService = new OrionCaRelayBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new OrionCaRelayBatchCronService');

        $this->sportsSouthCaRelayBatchCronService = new SportsSouthCaRelayBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new SportsSouthCaRelayBatchCronService');

        $this->zandersCaRelayBatchCronService = new ZandersCaRelayBatchCronService($this, $this->ordering_jobs_table, $this->ffl_table);
        $log_step('new ZandersCaRelayBatchCronService');

        $this->orderShippingCronService  = new ShippingCronService($this, $this->ordering_jobs_table);
        $log_step('new ShippingCronService');

        $this->orderDealerFulfilledCronService = new DealerFulfilledCronService($this, $this->ordering_jobs_table);
        $log_step('new DealerFulfilledCronService');

        $this->orderTrashJobsService = new OrderTrashJobsService($this->ordering_jobs_table);
        $log_step('new OrderTrashJobsService');

        $total_ms = (microtime(true) - $t0) * 1000.0;
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, 'BOOT END', [
            'total_ms' => round($total_ms, 3),
            'mem_kb' => (int) (memory_get_usage(true) / 1024),
        ]);
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
        $this->orderDealerFulfilledCronService->on_activation();
        $this->orderPlacementCronService->on_activation();
        $this->rsrDealerBatchCronService->on_activation();
        $this->lipseysDealerBatchCronService->on_activation();
        $this->orionDealerBatchCronService->on_activation();
        $this->sportsSouthDealerBatchCronService->on_activation();
        $this->zandersDealerBatchCronService->on_activation();
        $this->lipseysCaRelayBatchCronService->on_activation();
        $this->orionCaRelayBatchCronService->on_activation();
        $this->sportsSouthCaRelayBatchCronService->on_activation();
        $this->zandersCaRelayBatchCronService->on_activation();

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
        $this->orderDealerFulfilledCronService->on_deactivation();
        $this->orderPlacementCronService->on_deactivation();
        $this->rsrDealerBatchCronService->on_deactivation();
        $this->lipseysDealerBatchCronService->on_deactivation();
        $this->orionDealerBatchCronService->on_deactivation();
        $this->sportsSouthDealerBatchCronService->on_deactivation();
        $this->zandersDealerBatchCronService->on_deactivation();
        $this->lipseysCaRelayBatchCronService->on_deactivation();
        $this->orionCaRelayBatchCronService->on_deactivation();
        $this->sportsSouthCaRelayBatchCronService->on_deactivation();
        $this->zandersCaRelayBatchCronService->on_deactivation();
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
        $this->orderDealerFulfilledCronService->register();
        $this->orderPlacementCronService->register();
        $this->rsrDealerBatchCronService->register();
        $this->lipseysDealerBatchCronService->register();
        $this->orionDealerBatchCronService->register();
        $this->sportsSouthDealerBatchCronService->register();
        $this->zandersDealerBatchCronService->register();
        $this->lipseysCaRelayBatchCronService->register();
        $this->orionCaRelayBatchCronService->register();
        $this->sportsSouthCaRelayBatchCronService->register();
        $this->zandersCaRelayBatchCronService->register();

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
                // Log under admin debug so hidden lookup failures are diagnosable.
                DebugLogUtil::log_ctx(
                    'FFLHUB_ADMIN_DEBUG',
                    '[FFLHub][DistributorHandler]',
                    'UPC lookup distributor exception',
                    [
                        'upc' => $upc,
                        'dist_id' => (string) $id,
                        'include_images' => $include_images ? 1 : 0,
                        'exception_class' => get_class($e),
                        'exception_message' => (string) $e->getMessage(),
                    ]
                );
                continue;
            }

            if ($offer instanceof DistributorOffer) {
                $non_dropship_blocked = Options::is_distributor_non_dropship_blocked((string) $id);
                $offer_dropship_enabled = !empty($offer->product->dropship_enabled);

                if ($non_dropship_blocked && !$offer_dropship_enabled) {
                    DebugLogUtil::log_ctx(
                        'FFLHUB_ADMIN_DEBUG',
                        '[FFLHub][DistributorHandler]',
                        'UPC lookup offer skipped by non-dropship policy',
                        [
                            'upc' => $upc,
                            'dist_id' => (string) $id,
                            'include_images' => $include_images ? 1 : 0,
                            'non_dropship_blocked' => 1,
                            'offer_dropship_enabled' => 0,
                        ]
                    );
                    continue;
                }

                $offers[(string) $id] = $offer;
            }
        }

        return new UpcLookupResult($offers);
    }

    /**
     * Fetch normalized distributor offers for many UPCs.
     *
     * This preserves the same cheapest-distributor rules as get_payloads_for_upc(),
     * but lets table-backed distributors satisfy the request in bulk.
     *
     * @param array<int,string> $upcs
     * @return array<string,UpcLookupResult> Results keyed by normalized UPC.
     */
    public function get_payloads_for_upcs(array $upcs, bool $include_images = false): array
    {
        $normalized_upcs = [];
        foreach ($upcs as $upc) {
            $normalized = preg_replace('/\D+/', '', (string) $upc);
            $normalized = is_string($normalized) ? trim($normalized) : '';
            if ($normalized === '') {
                continue;
            }

            $normalized_upcs[$normalized] = $normalized;
        }

        if (empty($normalized_upcs)) {
            return [];
        }

        $offers_by_upc = [];
        foreach ($normalized_upcs as $normalized) {
            $offers_by_upc[$normalized] = [];
        }

        foreach ($this->distributors as $id => $distributor) {
            if (!Options::is_distributor_enabled($id)) {
                continue;
            }

            try {
                if (!$include_images && method_exists($distributor, 'get_pricing_payloads_by_upcs')) {
                    $payloads = $distributor->get_pricing_payloads_by_upcs(array_values($normalized_upcs));
                } else {
                    $payloads = $this->get_payloads_for_upcs_one_by_one($distributor, $normalized_upcs, $include_images);
                }
            } catch (\Throwable $e) {
                DebugLogUtil::log_ctx(
                    'FFLHUB_ADMIN_DEBUG',
                    '[FFLHub][DistributorHandler]',
                    'Bulk UPC lookup distributor exception',
                    [
                        'dist_id' => (string) $id,
                        'include_images' => $include_images ? 1 : 0,
                        'upc_count' => count($normalized_upcs),
                        'exception_class' => get_class($e),
                        'exception_message' => (string) $e->getMessage(),
                    ]
                );

                $payloads = $this->get_payloads_for_upcs_one_by_one($distributor, $normalized_upcs, $include_images);
            }

            if (!is_array($payloads) || empty($payloads)) {
                continue;
            }

            foreach ($payloads as $payload_upc => $payload) {
                if (!$payload instanceof DistributorProductPayload) {
                    continue;
                }

                $normalized = preg_replace('/\D+/', '', (string) $payload_upc);
                $normalized = is_string($normalized) ? trim($normalized) : '';
                if ($normalized === '' || !isset($offers_by_upc[$normalized])) {
                    $normalized = preg_replace('/\D+/', '', (string) ($payload->upc ?? ''));
                    $normalized = is_string($normalized) ? trim($normalized) : '';
                }

                if ($normalized === '' || !isset($offers_by_upc[$normalized])) {
                    continue;
                }

                $offer = new DistributorOffer(
                    (string) $id,
                    $distributor->get_label(),
                    $payload
                );

                $non_dropship_blocked = Options::is_distributor_non_dropship_blocked((string) $id);
                $offer_dropship_enabled = !empty($offer->product->dropship_enabled);

                if ($non_dropship_blocked && !$offer_dropship_enabled) {
                    DebugLogUtil::log_ctx(
                        'FFLHUB_ADMIN_DEBUG',
                        '[FFLHub][DistributorHandler]',
                        'Bulk UPC lookup offer skipped by non-dropship policy',
                        [
                            'upc' => $normalized,
                            'dist_id' => (string) $id,
                            'include_images' => $include_images ? 1 : 0,
                            'non_dropship_blocked' => 1,
                            'offer_dropship_enabled' => 0,
                        ]
                    );
                    continue;
                }

                $offers_by_upc[$normalized][(string) $id] = $offer;
            }
        }

        $results = [];
        foreach ($offers_by_upc as $upc => $offers) {
            $results[$upc] = new UpcLookupResult($offers);
        }

        return $results;
    }

    /**
     * @param array<string,string> $normalized_upcs
     * @return array<string,DistributorProductPayload>
     */
    private function get_payloads_for_upcs_one_by_one(DistributorBase $distributor, array $normalized_upcs, bool $include_images): array
    {
        $payloads = [];

        foreach ($normalized_upcs as $normalized) {
            try {
                $offer = $distributor->get_offer_by_upc($normalized, $include_images);
            } catch (\Throwable $e) {
                DebugLogUtil::log_ctx(
                    'FFLHUB_ADMIN_DEBUG',
                    '[FFLHub][DistributorHandler]',
                    'Individual UPC lookup fallback exception',
                    [
                        'upc' => $normalized,
                        'dist_id' => (string) $distributor->get_id(),
                        'include_images' => $include_images ? 1 : 0,
                        'exception_class' => get_class($e),
                        'exception_message' => (string) $e->getMessage(),
                    ]
                );
                continue;
            }

            if ($offer instanceof DistributorOffer && $offer->product instanceof DistributorProductPayload) {
                $payloads[$normalized] = $offer->product;
            }
        }

        return $payloads;
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
