<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declarative map for syncing a full distributor product table into offers.
 *
 * Concrete distributor services own the source expressions; the shared SQL
 * runner owns the insert/update/stale-cleanup query shape.
 */
final class OfferProductSyncMap
{
    /** @var string */
    private $distributor_id;

    /** @var string */
    private $label;

    /** @var string */
    private $live_table_label;

    /** @var string */
    private $source_live_table;

    /** @var string */
    private $source_alias;

    /** @var string */
    private $matched_count_key;

    /** @var array<string,string> */
    private $source_columns;

    /**
     * @param array<string,string> $source_columns
     */
    public function __construct(
        string $distributor_id,
        string $label,
        string $live_table_label,
        string $source_live_table,
        string $source_alias,
        string $matched_count_key,
        array $source_columns
    ) {
        $this->distributor_id = $distributor_id;
        $this->label = $label;
        $this->live_table_label = $live_table_label;
        $this->source_live_table = $source_live_table;
        $this->source_alias = (string) preg_replace('/[^A-Za-z0-9_]/', '', $source_alias);
        $this->matched_count_key = $matched_count_key;
        $this->source_columns = $source_columns;
    }

    public function distributor_id(): string
    {
        return $this->distributor_id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function live_table_label(): string
    {
        return $this->live_table_label;
    }

    public function source_live_table(): string
    {
        return $this->source_live_table;
    }

    public function source_alias(): string
    {
        return $this->source_alias;
    }

    public function matched_count_key(): string
    {
        return $this->matched_count_key;
    }

    /**
     * @return array<string,string>
     */
    public function source_columns(): array
    {
        return $this->source_columns;
    }
}
