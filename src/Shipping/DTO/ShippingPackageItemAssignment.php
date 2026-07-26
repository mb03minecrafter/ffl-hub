<?php
declare(strict_types=1);

namespace FFLHub\Shipping\DTO;

use JsonSerializable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One Woo order-item quantity assigned to one outbound shipping package.
 */
final class ShippingPackageItemAssignment implements JsonSerializable
{
    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        private readonly int $item_id,
        private readonly int $quantity,
        private readonly array $meta = []
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function from_packed_item_row(array $row): self
    {
        return new self(
            absint($row['order_item_id'] ?? $row['item_id'] ?? 0),
            max(0, (int) ($row['quantity'] ?? 0)),
            $row
        );
    }

    /**
     * @return array{item_id:int,quantity:int}
     */
    public function assignment_array(): array
    {
        return [
            'item_id' => $this->item_id,
            'quantity' => $this->quantity,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function to_array(): array
    {
        return [
            ...$this->meta,
            ...$this->assignment_array(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }
}
