<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\OfferSync;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declarative map for applying an inventory/pricing stage table to offers.
 *
 * Inventory feeds differ by distributor, so concrete services provide the
 * stage join, SET expressions, and changed-only WHERE condition. The shared
 * runner turns that map into one set-based UPDATE.
 */
final class OfferInventorySyncMap
{
    /** @var string */
    private $distributor_id;

    /** @var string */
    private $label;

    /** @var string */
    private $stage_table;

    /** @var string */
    private $stage_alias;

    /** @var string */
    private $join_condition_sql;

    /** @var array<int,string> */
    private $set_expressions;

    /** @var string */
    private $changed_where_sql;

    /**
     * @param array<int,string> $set_expressions
     */
    public function __construct(
        string $distributor_id,
        string $label,
        string $stage_table,
        string $stage_alias,
        string $join_condition_sql,
        array $set_expressions,
        string $changed_where_sql
    ) {
        $this->distributor_id = $distributor_id;
        $this->label = $label;
        $this->stage_table = trim($stage_table);
        $this->stage_alias = (string) preg_replace('/[^A-Za-z0-9_]/', '', $stage_alias);
        $this->join_condition_sql = trim($join_condition_sql);
        $this->set_expressions = $set_expressions;
        $this->changed_where_sql = trim($changed_where_sql);
    }

    public function distributor_id(): string
    {
        return $this->distributor_id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function stage_table(): string
    {
        return $this->stage_table;
    }

    public function stage_alias(): string
    {
        return $this->stage_alias;
    }

    public function join_condition_sql(): string
    {
        return $this->join_condition_sql;
    }

    /**
     * @return array<int,string>
     */
    public function set_expressions(): array
    {
        return $this->set_expressions;
    }

    public function changed_where_sql(): string
    {
        return $this->changed_where_sql;
    }
}
