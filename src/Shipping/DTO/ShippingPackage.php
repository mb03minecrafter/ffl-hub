<?php
declare(strict_types=1);

namespace FFLHub\Shipping\DTO;

use JsonSerializable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provider-neutral outbound package DTO for admin label workflows.
 *
 * The array shape intentionally matches the existing ShipStation-style package
 * payload so the same object can hydrate the UI, rate requests, pending-rate
 * snapshots, and label history without lossy translation.
 */
final class ShippingPackage implements JsonSerializable
{
    /**
     * @param ShippingPackageItemAssignment[] $items
     * @param array<string,mixed> $packing
     */
    public function __construct(
        private readonly string $preset_id,
        private readonly string $preset_name,
        private readonly string $package_kind,
        private readonly string $package_code,
        private readonly float $content_weight_oz,
        private readonly float $package_weight_oz,
        private readonly float $total_weight_oz,
        private readonly float $length_in,
        private readonly float $width_in,
        private readonly float $height_in,
        private readonly float $insured_amount,
        private readonly string $currency,
        private readonly array $items,
        private readonly array $packing = []
    ) {
    }

    /**
     * @param array<string,mixed> $box
     */
    public static function from_packed_box(array $box, float $insured_amount, string $currency = 'usd'): self
    {
        $items = [];
        foreach ((array) ($box['items'] ?? []) as $item) {
            if (is_array($item)) {
                $assignment = ShippingPackageItemAssignment::from_packed_item_row($item);
                if (($assignment->assignment_array()['item_id'] ?? 0) > 0 && ($assignment->assignment_array()['quantity'] ?? 0) > 0) {
                    $items[] = $assignment;
                }
            }
        }

        $source_id = trim((string) ($box['source_id'] ?? ''));
        $source_name = trim((string) ($box['source_name'] ?? ''));

        return new self(
            $source_id !== '' ? $source_id : (string) ($box['box_id'] ?? ''),
            $source_name !== '' ? $source_name : (string) ($box['box_name'] ?? ''),
            (string) ($box['package_type'] ?? $box['source_kind'] ?? 'box'),
            (string) ($box['package_code'] ?? 'package'),
            self::money((float) ($box['packed_item_weight_oz'] ?? 0.0)),
            self::money((float) ($box['empty_weight_oz'] ?? 0.0)),
            self::money((float) ($box['packed_weight_oz'] ?? 0.0)),
            self::dimension((float) ($box['outer_length_in'] ?? 0.0)),
            self::dimension((float) ($box['outer_width_in'] ?? 0.0)),
            self::dimension((float) ($box['outer_height_in'] ?? 0.0)),
            self::money($insured_amount),
            strtolower(trim($currency)) !== '' ? strtolower(trim($currency)) : 'usd',
            $items,
            $box
        );
    }

    /**
     * @return array<int,array{item_id:int,quantity:int}>
     */
    public function assignment_array(): array
    {
        return array_values(array_map(
            static fn(ShippingPackageItemAssignment $item): array => $item->assignment_array(),
            $this->items
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function to_array(): array
    {
        return [
            'preset_id' => $this->preset_id,
            'preset_name' => $this->preset_name,
            'package_kind' => $this->package_kind,
            'package_code' => $this->package_code,
            'content_weight_oz' => $this->content_weight_oz,
            'package_weight_oz' => $this->package_weight_oz,
            'weight' => [
                'value' => $this->total_weight_oz,
                'unit' => 'ounce',
            ],
            'dimensions' => [
                'unit' => 'inch',
                'length' => $this->length_in,
                'width' => $this->width_in,
                'height' => $this->height_in,
            ],
            'insured_value' => [
                'currency' => $this->currency,
                'amount' => $this->insured_amount,
            ],
            'items' => array_values(array_map(
                static fn(ShippingPackageItemAssignment $item): array => $item->to_array(),
                $this->items
            )),
            'packing' => $this->packing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }

    private static function dimension(float $value): float
    {
        return round(max(0.0, $value), 2);
    }

    private static function money(float $value): float
    {
        return round(max(0.0, $value), 2);
    }
}
