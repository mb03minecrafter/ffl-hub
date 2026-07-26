<?php
declare(strict_types=1);

namespace FFLHub\WMS;

use FFLHub\Distributor\Services\Cron\AbstractCronService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minute worker that turns packed Order Waver batches into EasyPost labels.
 */
final class OrderWaverLabelCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_order_waver_label_cron';

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 60;
    }

    public function get_action_group(): string
    {
        return 'fflhub_wms';
    }

    public function run(): void
    {
        (new OrderWaverService())->run_label_worker();
    }
}
