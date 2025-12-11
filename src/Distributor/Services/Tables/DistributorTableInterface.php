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
    public static function create_tables(): void;

    /**
     * Optional: drop / cleanup on uninstall, etc.
     */
}