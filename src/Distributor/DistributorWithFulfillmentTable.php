<?php

namespace FFLHub\Distributor;



abstract class DistributorWithFulfillmentTable extends DistributorBase
{

    protected function get_live_table_name(): ?string
    {
        $service_class = static::get_services_class();

        $table_class = $service_class::get_table_class();

        return $table_class::get_live_table_name();
    }

    protected function get_row_by_upc(string $normalized_upc): ?array
    {
        $table = $this->get_live_table_name();
        if ( ! $table ) {
            return null;
        }

        global $wpdb;

        $sql = "SELECT * FROM {$table} WHERE upc = %s LIMIT 1";
        $row = $wpdb->get_row(
            $wpdb->prepare( $sql, $normalized_upc ),
            ARRAY_A
        );

        if ( ! is_array( $row ) || empty( $row ) ) {
            return null;
        }

        return $row;
    }
}



