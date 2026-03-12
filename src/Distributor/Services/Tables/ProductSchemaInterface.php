<?php

namespace FFLHub\Distributor\Services\Tables;

interface ProductSchemaInterface
{
    public function get_base_table_key(): string;
    public function get_live_table_option_name(): string;

    /**
     * @return array<string,string> column_name => SQL definition
     */
    public function get_column_definitions(): array;

    /**
     * @return string[] raw index definitions
     */
    public function get_index_definitions(): array;

    /**
     * Columns used in INSERT statements (order matters).
     *
     * @return string[]
     */
    public function get_insert_columns(): array;
}

