<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class ShippingUpdateResult
{
    /** @var string[] */
    public array $added_tracking;

    /** @var string[] */
    public array $added_invoices;

    /** @var string[] */
    public array $all_tracking;

    /** @var string[] */
    public array $all_invoices;

    /**
     * @param string[] $added_tracking
     * @param string[] $added_invoices
     * @param string[] $all_tracking
     * @param string[] $all_invoices
     */
    public function __construct(array $added_tracking, array $added_invoices, array $all_tracking = [], array $all_invoices = [])
    {
        $this->added_tracking = self::normalize_list($added_tracking);
        $this->added_invoices = self::normalize_list($added_invoices);
        $this->all_tracking   = self::normalize_list($all_tracking);
        $this->all_invoices   = self::normalize_list($all_invoices);
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    /**
     * Convenience: if you already computed merged lists, and want deltas computed here.
     *
     * @param string[] $existing_tracking
     * @param string[] $new_tracking
     * @param string[] $existing_invoices
     * @param string[] $new_invoices
     */
    public static function from_lists(array $existing_tracking, array $new_tracking, array $existing_invoices, array $new_invoices): self
    {
        $existing_tracking = self::normalize_list($existing_tracking);
        $new_tracking      = self::normalize_list($new_tracking);
        $existing_invoices = self::normalize_list($existing_invoices);
        $new_invoices      = self::normalize_list($new_invoices);

        $merged_tracking = self::merge_preserve_order($existing_tracking, $new_tracking);
        $merged_invoices = self::merge_preserve_order($existing_invoices, $new_invoices);

        $added_tracking = array_values(array_diff($new_tracking, $existing_tracking));
        $added_invoices = array_values(array_diff($new_invoices, $existing_invoices));

        return new self($added_tracking, $added_invoices, $merged_tracking, $merged_invoices);
    }

    public function has_new_tracking(): bool
    {
        return !empty($this->added_tracking);
    }

    public function has_new_invoices(): bool
    {
        return !empty($this->added_invoices);
    }

    public function has_changes(): bool
    {
        return $this->has_new_tracking() || $this->has_new_invoices();
    }

    public function primary_tracking(): string
    {
        return !empty($this->all_tracking) ? (string) $this->all_tracking[0] : '';
    }

    /** @param mixed[] $vals @return string[] */
    private static function normalize_list(array $vals): array
    {
        $out = [];
        foreach ($vals as $v) {
            $s = trim((string) $v);
            if ($s !== '') $out[] = $s;
        }

        // unique preserve order
        $set = [];
        $uniq = [];
        foreach ($out as $s) {
            if (isset($set[$s])) continue;
            $set[$s] = true;
            $uniq[] = $s;
        }
        return $uniq;
    }

    /** @param string[] $a @param string[] $b @return string[] */
    private static function merge_preserve_order(array $a, array $b): array
    {
        $set = [];
        $out = [];

        foreach ($a as $v) {
            if ($v === '' || isset($set[$v])) continue;
            $set[$v] = true;
            $out[] = $v;
        }
        foreach ($b as $v) {
            if ($v === '' || isset($set[$v])) continue;
            $set[$v] = true;
            $out[] = $v;
        }

        return $out;
    }


    
}
