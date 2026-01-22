<?php

namespace FFLHub\Distributor\Product;

if (!defined('ABSPATH')) {
    exit;
}

final class DistributorOrderValidationResult
{
    public bool $ok;
    public string $message;

    /** @var string[] */
    public array $codes;

    /** @var array<string, mixed> */
    public array $details;

    public function __construct(bool $ok, string $message, array $codes = [], array $details = [])
    {
        $this->ok = $ok;
        $this->message = $message;
        $this->codes = $codes;
        $this->details = $details;
    }

    public static function allow(string $message = 'OK', array $details = []): self
    {
        return new self(true, $message, [], $details);
    }

    public static function block(string $message, array $codes = [], array $details = []): self
    {
        return new self(false, $message, $codes, $details);
    }

    public function with_detail(string $key, $value): self
    {
        $clone = clone $this;
        $clone->details[$key] = $value;
        return $clone;
    }

    public function with_code(string $code): self
    {
        $clone = clone $this;
        $clone->codes[] = $code;
        return $clone;
    }
}
