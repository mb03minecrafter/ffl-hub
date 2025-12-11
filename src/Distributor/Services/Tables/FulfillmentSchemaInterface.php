<?php

namespace FFLHub\Distributor\Services\Tables;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract for fulfillment schemas (column defs, indexes, option names, etc.).
 */
interface FulfillmentSchemaInterface {

    /**
     * Machine-friendly base key used in table names,
     * e.g. "fflhub_rsr_fulfillment".
     */
    public static function get_base_table_key(): string;

    /**
     * Option name that stores the current live table name,
     * e.g. "fflhub_rsr_fulfillment_live_table".
     */
    public static function get_live_table_option_name(): string;

    /**
     * Column definitions: [ 'column_name' => 'TYPE NOT NULL', ... ].
     *
     * @return array<string,string>
     */
    public static function get_column_definitions(): array;

    /**
     * Index definitions: list of index lines to drop into CREATE TABLE.
     *
     * e.g. [ 'PRIMARY KEY  (upc)', 'KEY some_index (field)' ].
     *
     * @return string[]
     */
    public static function get_index_definitions(): array;
}
