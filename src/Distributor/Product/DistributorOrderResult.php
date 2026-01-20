<?php

namespace FFLHub\Distributor\Product;

if (! defined('ABSPATH')) {
    exit;
}

final class DistributorOrderResult
{
    public bool $success;
    public string $message;

    /**
     * Some distributors may create more than one external order (e.g., Lipsey's FFL + non-FFL split).
     * Store all ids here.
     *
     * @var string[]
     */
    public array $external_order_ids;

    public function __construct(bool $success, string $message, array $external_order_ids = [])
    {
        $this->success = $success;
        $this->message = $message;

        $clean = [];
        foreach ($external_order_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $clean[] = $id;
            }
        }
        $this->external_order_ids = $clean;
    }

    public function first_external_order_id(): ?string
    {
        return $this->external_order_ids[0] ?? null;
    }
}
