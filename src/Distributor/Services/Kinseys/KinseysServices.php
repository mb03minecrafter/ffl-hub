<?php

namespace FFLHub\Distributor\Services\Kinseys;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\DistributorServicesBase;
use FFLHub\Distributor\Services\Kinseys\Cron\KinseysInventoryCronService;
use FFLHub\Distributor\Services\Kinseys\Cron\KinseysProductCronService;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Util\DebugLogUtil;

final class KinseysServices extends DistributorServicesBase
{
    private const SCHEMA_VERSION_OPTION = 'fflhub_kinseys_schema_version';
    private const SCHEMA_VERSION = '3';

    private FFLTable $fflTable;

    public function __construct(
        DoubleBufferedProductTable $fulfillmentTable,
        KinseysProductCronService $productCron,
        KinseysInventoryCronService $inventoryCron,
        FFLTable $fflTable
    ) {
        parent::__construct(
            $fulfillmentTable,
            $productCron,
            $inventoryCron
        );

        $this->fflTable = $fflTable;
    }

    public function get_ffl_table(): FFLTable
    {
        return $this->fflTable;
    }

    public function on_activate(): void
    {
        parent::on_activate();
        $this->ensure_schema_current();
    }

    public function register_runtime_services(): void
    {
        $this->ensure_schema_current();
        parent::register_runtime_services();
    }

    private function ensure_schema_current(): void
    {
        if ((string) get_option(self::SCHEMA_VERSION_OPTION, '') === self::SCHEMA_VERSION) {
            return;
        }

        if (!$this->fulfillmentTable instanceof DoubleBufferedProductTable) {
            update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
            return;
        }

        try {
            $this->fulfillmentTable->createTables();

            $stage_table = (new KinseysProductImporterService($this->fulfillmentTable))->ensure_inventory_stage_table();
            if ($stage_table === '') {
                DebugLogUtil::log_ctx('FFLHUB_CRON_DEBUG', '[FFLHub][KinseysServices]', 'Failed to bootstrap Kinsey\'s inventory stage table during schema check.', [
                    'schema_version' => self::SCHEMA_VERSION,
                ]);
                return;
            }

            update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
        } catch (\Throwable $e) {
            DebugLogUtil::log_ctx('FFLHUB_CRON_DEBUG', '[FFLHub][KinseysServices]', 'Failed to bootstrap Kinsey\'s tables during schema check.', [
                'schema_version' => self::SCHEMA_VERSION,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
