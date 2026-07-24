<?php
declare(strict_types=1);

namespace FFLHub\Shipping\Packing;

use DVDoug\BoxPacker\Box;
use JsonSerializable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FFLHub box wrapper for dvdoug/boxpacker.
 *
 * The rest of our shipping code stores package data in inches/ounces because
 * ShipStation, EasyPost, and Woo admins speak that language. BoxPacker expects
 * integer millimeters/grams, so this value object keeps both the original
 * shipping-provider values and the converted packing-engine values together.
 */
final class PackingBox implements Box, JsonSerializable
{
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $package_code,
        private readonly float $outer_length_in,
        private readonly float $outer_width_in,
        private readonly float $outer_height_in,
        private readonly float $inner_length_in,
        private readonly float $inner_width_in,
        private readonly float $inner_height_in,
        private readonly float $empty_weight_oz,
        private readonly float $max_weight_oz,
        private readonly int $outer_length_mm,
        private readonly int $outer_width_mm,
        private readonly int $outer_height_mm,
        private readonly int $inner_length_mm,
        private readonly int $inner_width_mm,
        private readonly int $inner_height_mm,
        private readonly int $empty_weight_g,
        private readonly int $max_weight_g,
        private readonly array $source = []
    ) {
    }

    public function getReference(): string
    {
        return $this->id !== '' ? $this->id : $this->name;
    }

    public function getOuterWidth(): int
    {
        return $this->outer_width_mm;
    }

    public function getOuterLength(): int
    {
        return $this->outer_length_mm;
    }

    public function getOuterDepth(): int
    {
        return $this->outer_height_mm;
    }

    public function getEmptyWeight(): int
    {
        return $this->empty_weight_g;
    }

    public function getInnerWidth(): int
    {
        return $this->inner_width_mm;
    }

    public function getInnerLength(): int
    {
        return $this->inner_length_mm;
    }

    public function getInnerDepth(): int
    {
        return $this->inner_height_mm;
    }

    public function getMaxWeight(): int
    {
        return $this->max_weight_g;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPackageCode(): string
    {
        return $this->package_code;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'box_id' => $this->id,
            'box_name' => $this->name,
            'package_code' => $this->package_code,
            'outer_length_in' => $this->outer_length_in,
            'outer_width_in' => $this->outer_width_in,
            'outer_height_in' => $this->outer_height_in,
            'inner_length_in' => $this->inner_length_in,
            'inner_width_in' => $this->inner_width_in,
            'inner_height_in' => $this->inner_height_in,
            'empty_weight_oz' => $this->empty_weight_oz,
            'max_weight_oz' => $this->max_weight_oz,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            ...$this->summary(),
            'source' => $this->source,
        ];
    }
}
