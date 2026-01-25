<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Result of validating an order request (preflight).
 *
 * Worker uses this BEFORE calling place_order().
 * This should classify BLOCK outcomes into:
 *  - BLOCK_FATAL (no retry)
 *  - BLOCK_RETRYABLE (rare; e.g. validation endpoint quota)
 *
 * PHP 7 compatible (no enums, no typed properties).
 */
final class DistributorOrderValidationResult
{
    // Primary outcome codes (worker-facing)
    const CODE_ALLOW          = 'ALLOW';
    const CODE_BLOCK_FATAL    = 'BLOCK_FATAL';
    const CODE_BLOCK_RETRYABLE = 'BLOCK_RETRYABLE';

    // Optional secondary reason codes (your existing pattern)
    // Examples you already use:
    // - LIPSEYS_CLIENT_MISSING, LIPSEYS_CREDS_MISSING, LIPSEYS_VALIDATEITEM_TOO_MANY_UNIQUE, etc.

    /** @var bool */
    public $ok;

    /** @var string */
    public $message;

    /**
     * Primary classification code: ALLOW / BLOCK_FATAL / BLOCK_RETRYABLE
     *
     * @var string
     */
    public $code;

    /**
     * Secondary machine codes (reasons). Can contain multiple items.
     *
     * @var string[]
     */
    public $codes;

    /**
     * Arbitrary details (debug, redacted).
     *
     * @var array<string,mixed>
     */
    public $details;

    /**
     * @param bool $ok
     * @param string $message
     * @param string $code
     * @param string[] $codes
     * @param array<string,mixed> $details
     */
    public function __construct($ok, $message, $code, array $codes = array(), array $details = array())
    {
        $this->ok = (bool) $ok;
        $this->message = (string) $message;

        $code = is_string($code) ? trim($code) : '';
        if ($code === '') {
            $code = $this->ok ? self::CODE_ALLOW : self::CODE_BLOCK_FATAL;
        }
        $this->code = $code;

        $clean_codes = array();
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $clean_codes[] = $c;
            }
        }
        $this->codes = $clean_codes;

        $this->details = is_array($details) ? $details : array();
    }

    public static function allow($message = 'OK', array $details = array())
    {
        return new self(true, (string) $message, self::CODE_ALLOW, array(), $details);
    }

    /**
     * Default block is fatal unless explicitly marked retryable.
     */
    public static function block($message, array $codes = array(), array $details = array())
    {
        return new self(false, (string) $message, self::CODE_BLOCK_FATAL, $codes, $details);
    }

    public static function block_retryable($message, array $codes = array(), array $details = array())
    {
        return new self(false, (string) $message, self::CODE_BLOCK_RETRYABLE, $codes, $details);
    }

    public function with_detail($key, $value)
    {
        $clone = clone $this;
        $clone->details[(string) $key] = $value;
        return $clone;
    }

    public function with_code($code)
    {
        $clone = clone $this;
        $code = trim((string) $code);
        if ($code !== '') {
            $clone->codes[] = $code;
        }
        return $clone;
    }

    public function is_retryable()
    {
        return ($this->code === self::CODE_BLOCK_RETRYABLE);
    }

    public function is_fatal_block()
    {
        return ($this->code === self::CODE_BLOCK_FATAL);
    }
}
