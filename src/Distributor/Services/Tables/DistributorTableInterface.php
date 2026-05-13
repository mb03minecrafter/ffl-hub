<?php
namespace FFLHub\Distributor\Services\Tables;

if (! defined('ABSPATH')) {
    exit;
}

interface DistributorTableInterface
{



    /**
     * Create or migrate all tables for this schema.
     */
    public function createTables(): void;

    /**
     * Optional: drop / cleanup on uninstall, etc.
     */

    public function get_row_by_upc($upc): ?array;

    /**
     * @param array<int,string> $upcs
     * @return array<string,array<string,mixed>>
     */
    public function get_rows_by_upcs(array $upcs): array;
    


}
