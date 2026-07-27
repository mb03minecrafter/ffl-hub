<?php
declare(strict_types=1);

namespace FFLHub\Shipping\Packing;

use DVDoug\BoxPacker\Item;
use DVDoug\BoxPacker\Rotation;
use JsonSerializable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FFLHub order-line item wrapper for dvdoug/boxpacker.
 *
 * One instance represents one Woo order line shape. BoxPacker can receive that
 * same object with a quantity, then returns one packed entry per physical unit.
 * The local metadata lets us convert packed units back into Woo order items.
 */
final class PackingItem implements Item, JsonSerializable
{
    public function __construct(
        private readonly string $packing_key,
        private readonly int $order_item_id,
        private readonly int $product_id,
        private readonly int $variation_id,
        private readonly string $upc,
        private readonly string $sku,
        private readonly string $name,
        private readonly bool $ffl_required,
        private readonly float $length_in,
        private readonly float $width_in,
        private readonly float $height_in,
        private readonly float $weight_oz,
        private readonly int $length_mm,
        private readonly int $width_mm,
        private readonly int $height_mm,
        private readonly int $weight_g,
        private readonly Rotation $allowed_rotation = Rotation::BestFit
    ) {
    }

    public function getDescription(): string
    {
        $label = $this->sku !== '' ? $this->sku : $this->upc;
        return trim($this->name . ($label !== '' ? ' (' . $label . ')' : ''));
    }

    public function getWidth(): int
    {
        return $this->width_mm;
    }

    public function getLength(): int
    {
        return $this->length_mm;
    }

    public function getDepth(): int
    {
        return $this->height_mm;
    }

    public function getWeight(): int
    {
        return $this->weight_g;
    }

    public function getAllowedRotation(): Rotation
    {
        return $this->allowed_rotation;
    }

    public function getEnvelopeMinimumThicknessIn(): float
    {
        if ($this->allowed_rotation === Rotation::BestFit) {
            return min($this->length_in, $this->width_in, $this->height_in);
        }

        return $this->height_in;
    }

    /**
     * Older BoxPacker interface builds asked items this question directly.
     * Keeping it here is harmless on newer builds and protects the admin tester
     * if PHP-FPM is still holding a stale interface in OPcache after composer
     * updates.
     */
    public function getKeepFlat(): bool
    {
        return $this->allowed_rotation === Rotation::KeepFlat;
    }

    public function getPackingKey(): string
    {
        return $this->packing_key;
    }

    public function getOrderItemId(): int
    {
        return $this->order_item_id;
    }

    public function getProductId(): int
    {
        return $this->product_id;
    }

    public function getVariationId(): int
    {
        return $this->variation_id;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'packing_key' => $this->packing_key,
            'order_item_id' => $this->order_item_id,
            'product_id' => $this->product_id,
            'variation_id' => $this->variation_id,
            'upc' => $this->upc,
            'sku' => $this->sku,
            'name' => $this->name,
            'ffl_required' => $this->ffl_required ? 1 : 0,
            'length_in' => $this->length_in,
            'width_in' => $this->width_in,
            'height_in' => $this->height_in,
            'weight_oz' => $this->weight_oz,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->summary();
    }
}
