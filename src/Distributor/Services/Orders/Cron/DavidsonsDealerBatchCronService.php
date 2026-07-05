<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

use WC_Order;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\Identifiers\OrderPlacementJobIdentifiersStore;
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifeCycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\Snapshots\OrderPlacementJobSnapshotsStore;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementSnapshotUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Davidson's dealer batch wrapper.
 *
 * Davidson's still requires human ordering, but it participates in the real
 * batch queue so the optimizer can move rows to it and hold them until the
 * normal dealer-batch dispatch window. When the batch fires, Davidson's
 * place_order() sends the manual-order email and returns MANUAL; this class
 * then stamps the shared batch PO and marks each covered row manual for the
 * existing Davidson's Manual Order Status page.
 */
final class DavidsonsDealerBatchCronService extends AbstractOrderBatchCronService
{
    /** Action Scheduler / WP-Cron hook polled every minute for Davidson's dealer batch work. */
    public const CRON_HOOK = 'fflhub_davidsons_dealer_batch_poll';

    private const LOG_PREFIX = '[FFLHub][DavidsonsDealerBatchCronService]';

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_log_prefix(): string
    {
        return self::LOG_PREFIX;
    }

    protected function get_distributor_id(): string
    {
        return 'davidsons';
    }

    protected function get_option_prefix(): string
    {
        return 'fflhub_davidsons_dealer_batch';
    }

    protected function get_po_prefix(): string
    {
        return 'DAVB';
    }

    protected function get_batch_description_prefix(): string
    {
        return "Davidson's dealer batch manual handoff";
    }

    protected function get_batch_mode(): string
    {
        return self::MODE_DEALER;
    }

    /**
     * Davidson's aggregate MANUAL result means the manual-order email was sent.
     * Keep the batch grouped under one merchant PO, then move each row into the
     * existing manual status workflow instead of falling back to single-row
     * immediate placement.
     *
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     * @param array<int,DistributorOrderLine> $aggregate_lines
     */
    protected function handle_manual_aggregate_result(
        DistributorOrderResult $result,
        array $batch_candidates,
        array $aggregate_lines,
        ?DistributorShipTo $ship_to,
        string $po,
        string $batch_kind,
        string $run_id
    ): bool {
        foreach ($batch_candidates as $entry) {
            $job = $entry['job'] ?? null;
            $order = $entry['order'] ?? null;
            if (!($job instanceof OrderPlacementJobRow) || !($order instanceof WC_Order)) {
                continue;
            }

            $job_key = (string) $job->job_key_norm();
            if ($job_key === '') {
                continue;
            }

            $snapshot = OrderPlacementSnapshotUtil::place_snapshot($result, $job->ctx((int) $job->attempts + 1));
            OrderPlacementJobSnapshotsStore::set_job_place_result($this->jobs_table, $order, $job_key, $snapshot);
            OrderPlacementJobIdentifiersStore::set_job_merchant_po($this->jobs_table, $order, $job_key, $po, true);

            OrderPlacementJobWriter::apply_patch_for_order(
                $this->jobs_table,
                $order,
                $job_key,
                OrderPlacementJobPatch::empty()
                    ->with_last_step('place')
                    ->with_last_error((string) $result->message)
                    ->with_last_codes((array) $result->codes)
            );

            OrderPlacementJobLifeCycle::mark_job_manual($this->jobs_table, $order, $job_key, (string) $result->message);
        }

        $this->log_ctx('manual_aggregate_handoff_complete', [
            'run_id' => $run_id,
            'batch_kind' => $batch_kind,
            'po' => $po,
            'rows' => count($batch_candidates),
            'unique_lines' => count($aggregate_lines),
            'ship_to_state' => $ship_to instanceof DistributorShipTo ? (string) $ship_to->state : '',
        ]);

        return true;
    }
}
