<?php

namespace FFLHub\Distributor\Integrations\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Services\SportsSouth\Cron\SportsSouthInventoryCronService;
use FFLHub\Distributor\Services\SportsSouth\Cron\SportsSouthProductCronService;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthFulfillmentPolicy;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthServices;
use FFLHub\Distributor\Services\SportsSouth\Tables\SportsSouthProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Sports South module definition.
 */
final class SportsSouthModule implements DistributorModuleInterface
{
    public function id(): string
    {
        return 'sports_south';
    }

    public function label(): string
    {
        return 'Sports South';
    }

    public function name(): string
    {
        return 'Sports South';
    }

    public function description(): string
    {
        return 'Sports South Distributor';
    }

    public function section_description(): string
    {
        return 'Sports South Distributor';
    }

    public function icon_url(): string
    {
        return '';
    }

    public function settings_schema(): array
    {
        return array_merge([
            'customer_number' => [
                'label' => 'Customer Number',
                'type' => 'text',
                'placeholder' => '99994',
                'description' => 'Sports South account/customer number.',
                'default' => '',
            ],
            'username' => [
                'label' => 'UserName',
                'type' => 'text',
                'placeholder' => '99994',
                'description' => 'Sports South web services UserName. Live usually matches your account number.',
                'default' => '',
            ],
            'password' => [
                'label' => 'Password',
                'type' => 'password',
                'placeholder' => '',
                'description' => 'Sports South web services password.',
                'default' => '',
            ],
            'source' => [
                'label' => 'Source',
                'type' => 'text',
                'placeholder' => 'Account number or provider code',
                'description' => 'Use your account number unless Sports South gave you a provider Source code.',
                'default' => '',
            ],
            'inventory_api_base_url' => [
                'label' => 'Inventory API Base URL',
                'type' => 'text',
                'placeholder' => 'https://webservices.theshootingwarehouse.com/smart/inventory.asmx',
                'description' => 'Sports South inventory ASMX endpoint.',
                'default' => 'https://webservices.theshootingwarehouse.com/smart/inventory.asmx',
            ],
            'orders_api_base_url' => [
                'label' => 'Orders API Base URL',
                'type' => 'text',
                'placeholder' => 'https://webservices.theshootingwarehouse.com/smart/orders.asmx',
                'description' => 'Sports South orders ASMX endpoint.',
                'default' => 'https://webservices.theshootingwarehouse.com/smart/orders.asmx',
            ],
            'order_ship_via' => [
                'label' => 'Order ShipVIA',
                'type' => 'select',
                'description' => 'Sports South order shipping code. Blank uses Ground Economy.',
                'default' => '',
                'options' => [
                    '' => 'Ground Economy',
                    'G' => 'Ground Premium',
                    '2' => '2-Day Air',
                    'N' => 'Next-Day Air',
                ],
            ],
            'order_adult_signature' => [
                'label' => 'Adult Signature',
                'type' => 'checkbox',
                'description' => 'Send AdultSignature=True on Sports South order headers.',
                'default' => '0',
            ],
            'order_signature' => [
                'label' => 'Signature',
                'type' => 'checkbox',
                'description' => 'Send Signature=True on Sports South order headers.',
                'default' => '0',
            ],
            'order_insurance' => [
                'label' => 'Insurance',
                'type' => 'checkbox',
                'description' => 'Send Insurance=True on Sports South order headers.',
                'default' => '0',
            ],
            'dealer_batch_enabled' => [
                'label' => 'Enable Sports South Dealer Batch Queue',
                'type' => 'checkbox',
                'description' => 'When enabled, Sports South dealer-fulfilled rows are held in a batch queue instead of placing immediately.',
                'default' => '1',
            ],
            'dealer_batch_dispatch_time' => [
                'label' => 'Sports South Dealer Batch Dispatch Time',
                'type' => 'text',
                'placeholder' => '17:00',
                'description' => 'Daily local dispatch time (HH:MM, America/Chicago) for non-priority dealer batch rows.',
                'default' => '17:00',
            ],
            'dealer_batch_low_stock_threshold' => [
                'label' => 'Sports South Dealer Batch Low Stock Threshold',
                'type' => 'number',
                'placeholder' => '3',
                'description' => 'Rows containing UPCs at or below this stock level, unknown stock, or demand above stock are flushed immediately.',
                'default' => '3',
            ],
            'dealer_batch_retry_delay_seconds' => [
                'label' => 'Sports South Dealer Batch Retry Delay Seconds',
                'type' => 'number',
                'placeholder' => '300',
                'description' => 'Retry delay for retryable aggregate batch failures.',
                'default' => '300',
            ],
            'dealer_batch_max_rows_per_run' => [
                'label' => 'Sports South Dealer Batch Max Rows Per Run',
                'type' => 'number',
                'placeholder' => '200',
                'description' => 'Maximum due batch_pending rows examined per batch cron run.',
                'default' => '200',
            ],
            'dealer_batch_force_flush' => [
                'label' => 'Force Sports South Dealer Batch Flush',
                'type' => 'checkbox',
                'description' => 'One-shot flag consumed by the next batch cron run; sends non-priority queued rows immediately.',
                'default' => '0',
            ],
            'ca_relay_batch_enabled' => [
                'label' => 'Enable Sports South CA Relay Batch Queue',
                'type' => 'checkbox',
                'description' => 'When enabled, non-FFL Sports South direct-ship rows going to CA are batched and shipped to your configured relay ship-to address.',
                'default' => '1',
            ],
            'ca_relay_batch_dispatch_time' => [
                'label' => 'Sports South CA Relay Batch Dispatch Time',
                'type' => 'text',
                'placeholder' => '17:00',
                'description' => 'Daily local dispatch time (HH:MM, America/Chicago) for non-priority CA relay batch rows.',
                'default' => '17:00',
            ],
            'ca_relay_batch_low_stock_threshold' => [
                'label' => 'Sports South CA Relay Batch Low Stock Threshold',
                'type' => 'number',
                'placeholder' => '3',
                'description' => 'Relay rows containing UPCs at or below this stock level, unknown stock, or demand above stock are flushed immediately.',
                'default' => '3',
            ],
            'ca_relay_batch_retry_delay_seconds' => [
                'label' => 'Sports South CA Relay Batch Retry Delay Seconds',
                'type' => 'number',
                'placeholder' => '300',
                'description' => 'Retry delay for retryable aggregate CA relay batch failures.',
                'default' => '300',
            ],
            'ca_relay_batch_max_rows_per_run' => [
                'label' => 'Sports South CA Relay Batch Max Rows Per Run',
                'type' => 'number',
                'placeholder' => '200',
                'description' => 'Maximum due CA relay batch_pending rows examined per batch cron run.',
                'default' => '200',
            ],
            'ca_relay_batch_force_flush' => [
                'label' => 'Force Sports South CA Relay Batch Flush',
                'type' => 'checkbox',
                'description' => 'One-shot flag consumed by the next CA relay batch cron run; sends non-priority queued rows immediately.',
                'default' => '0',
            ],
            'daily_item_last_update' => [
                'label' => 'DailyItemUpdate LastUpdate',
                'type' => 'text',
                'placeholder' => '1/1/1990',
                'description' => 'Keep 1/1/1990 for a full double-buffer table rebuild. Sports South uses mm/dd/yyyy.',
                'default' => '1/1/1990',
            ],
            'daily_item_last_item' => [
                'label' => 'DailyItemUpdate LastItem',
                'type' => 'text',
                'placeholder' => '-1',
                'description' => 'Use -1 to bypass 1000-row paging and return the full DailyItemUpdate payload.',
                'default' => '-1',
            ],
            'inventory_since_datetime' => [
                'label' => 'Initial IncrementalOnhand SinceDateTime',
                'type' => 'text',
                'placeholder' => '2020-12-12T15:05:00.00+00.00',
                'description' => 'Optional first-run cursor. After the first run, FFLHub stores Sports South inventory cursor automatically.',
                'default' => '',
            ],
            'accessories_only' => [
                'label' => 'Accessories Only',
                'type' => 'checkbox',
                'description' => 'When enabled, Sports South FFL/SOT rows are excluded from catalog import, product creation, and product sync payloads.',
                'default' => '0',
            ],
            'product_sync_enabled' => [
                'label' => 'Allow Product Sync Selection',
                'type' => 'checkbox',
                'description' => 'When disabled, Sports South can still provide UPC/product creation payloads, but managed product sync will not choose it as the source.',
                'default' => '0',
            ],
        ], SportsSouthFulfillmentPolicy::settings_schema_fields());
    }

    public function build_distributor(): DistributorBase
    {
        $schema = new SportsSouthProductTableSchema();

        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_sports_south_fulfillment_last_swap'
        );

        $productCron = new SportsSouthProductCronService($table);
        $inventoryCron = new SportsSouthInventoryCronService($table);

        $services = new SportsSouthServices(
            $table,
            $productCron,
            $inventoryCron
        );

        return new DistributorSportsSouth($this, $services);
    }
}
