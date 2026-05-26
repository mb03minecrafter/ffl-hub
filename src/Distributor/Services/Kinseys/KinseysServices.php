<?php

namespace FFLHub\Distributor\Services\Kinseys;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Kinseys\Cron\KinseysInventoryCronService;
use FFLHub\Distributor\Services\Kinseys\Cron\KinseysProductCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

final class KinseysServices extends DistributorServicesBase
{
    private const SCHEMA_BOOTSTRAP_OPTION = 'fflhub_kinseys_schema_bootstrap_v1';

    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        KinseysProductCronService $productCron,
        KinseysInventoryCronService $inventoryCron
    ) {
        parent::__construct(
            $fulfillmentTable,
            $productCron,
            $inventoryCron
        );
    }

    public function on_activate(): void
    {
        parent::on_activate();
        update_option(self::SCHEMA_BOOTSTRAP_OPTION, '1', false);
    }

    public function register_runtime_services(): void
    {
        $this->ensure_tables_after_plugin_update();
        parent::register_runtime_services();
    }

    private function ensure_tables_after_plugin_update(): void
    {
        if ((string) get_option(self::SCHEMA_BOOTSTRAP_OPTION, '') === '1') {
            return;
        }

        if (!$this->fulfillmentTable instanceof DoubleBufferedProductTable) {
            update_option(self::SCHEMA_BOOTSTRAP_OPTION, '1', false);
            return;
        }

        try {
            $this->fulfillmentTable->createTables();
            update_option(self::SCHEMA_BOOTSTRAP_OPTION, '1', false);
        } catch (\Throwable $e) {
            DebugLogUtil::log_ctx('FFLHUB_CRON_DEBUG', '[FFLHub][KinseysServices]', 'Failed to bootstrap Kinsey\'s tables during runtime registration.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
