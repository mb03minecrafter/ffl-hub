<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Result of validating an order request (preflight).
 *
 * This runs *before* attempting to place an order and answers:
 *   "Given the current cart + ship-to context, is it permissible to attempt ordering?"
 *
 * Typical callers:
 * - Cart/checkout compliance layer
 * - Order placement job runner (preflight)
 *
 * How to interpret the result (worker-facing)
 * ------------------------------------------
 * - ALLOW           => safe to call place_order()
 * - BLOCK_FATAL     => do not retry; requires human intervention or data correction
 * - BLOCK_RETRYABLE => transient validation failure; retry with backoff
 *
 * Notes on retryability:
 * - Retryable validation should be rare (e.g., quota/rate-limit on a validation API).
 * - Most "validation failed" cases are business rules (restricted) or bad input and should be fatal.
 *
 * Compatibility / style
 * ---------------------
 * - PHP 7 compatible (no enums, no typed properties)
 * - Small, redacted details payload (do not dump raw API blobs here)
 * - Secondary codes[] provides machine-readable reason(s) and supports analytics.
 */
final class DistributorOrderValidationResult
{
    // -----------------------------
    // Primary outcome codes (control-plane)
    // -----------------------------

    /** Preflight passed; safe to proceed to place_order() */
    const CODE_ALLOW = 'ALLOW';

    /** Preflight failed terminally (no retry) */
    const CODE_BLOCK_FATAL = 'BLOCK_FATAL';

    /** Preflight failed transiently (retry with backoff) */
    const CODE_BLOCK_RETRYABLE = 'BLOCK_RETRYABLE';

    // -----------------------------
    // Payload fields
    // -----------------------------

    /** @var bool Convenience flag mirroring $code (true only for ALLOW) */
    public $ok;

    /** @var string Human-readable summary for logs/UI */
    public $message;

    /**
     * Primary classification code: ALLOW / BLOCK_FATAL / BLOCK_RETRYABLE
     *
     * IMPORTANT:
     * This is what your worker should branch on for control flow.
     *
     * @var string
     */
    public $code;

    /**
     * Secondary machine codes (reason(s)).
     *
     * Examples you already use:
     * - LIPSEYS_SERVICES_MISSING
     * - LIPSEYS_CREDS_MISSING
     * - LIPSEYS_VALIDATEITEM_TOO_MANY_UNIQUE
     * - RSR_FFL_ADDRESS_MISSING
     * - RSR_OUT_OF_STOCK
     *
     * @var string[]
     */
    public $codes;

    /**
     * Arbitrary details for debugging/auditing (keep small + redacted).
     *
     * Good uses:
     * - LANE breakdowns
     * - local_only flag
     * - a few item tails, counts, and summarized fields
     *
     * @var array<string,mixed>
     */
    public $details;

    /**
     * @param bool              $ok
     * @param string            $message
     * @param string            $code
     * @param string[]          $codes
     * @param array<string,mixed> $details
     */
    public function __construct($ok, $message, $code, array $codes = array(), array $details = array())
    {
        // Normalize ok/message first.
        $this->ok = (bool) $ok;
        $this->message = (string) $message;

        // Normalize primary code; default if missing.
        $code = is_string($code) ? trim($code) : '';
        if ($code === '') {
            $code = $this->ok ? self::CODE_ALLOW : self::CODE_BLOCK_FATAL;
        }
        $this->code = $code;

        // Normalize secondary codes[] (trim + drop empties).
        $clean_codes = array();
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $clean_codes[] = $c;
            }
        }
        $this->codes = $clean_codes;

        // Always keep details an array.
        $this->details = is_array($details) ? $details : array();
    }

    // -----------------------------
    // Factories
    // -----------------------------

    /**
     * Preflight allow.
     *
     * @param string $message
     * @param array<string,mixed> $details
     * @return self
     */
    public static function allow($message = 'OK', array $details = array())
    {
        // ALLOW typically has no secondary codes by default.
        return new self(true, (string) $message, self::CODE_ALLOW, array(), $details);
    }

    /**
     * Preflight block (defaults to fatal).
     *
     * Use for:
     * - missing required data (ship_to_ffl, receiving_ffl_number)
     * - restricted shipping rules / compliance
     * - local stock insufficient / unknown (if you choose conservative behavior)
     *
     * @param string $message
     * @param string[] $codes
     * @param array<string,mixed> $details
     * @return self
     */
    public static function block($message, array $codes = array(), array $details = array())
    {
        return new self(false, (string) $message, self::CODE_BLOCK_FATAL, $codes, $details);
    }

    /**
     * Preflight block (retryable).
     *
     * Use for:
     * - quota/rate-limit on validation endpoint
     * - transient auth/client init errors where retry might succeed
     *
     * @param string $message
     * @param string[] $codes
     * @param array<string,mixed> $details
     * @return self
     */
    public static function block_retryable($message, array $codes = array(), array $details = array())
    {
        return new self(false, (string) $message, self::CODE_BLOCK_RETRYABLE, $codes, $details);
    }

    // -----------------------------
    // Fluent helpers (immutable-ish)
    // -----------------------------

    /**
     * Return a cloned result with an additional details entry.
     *
     * @param string $key
     * @param mixed  $value
     * @return self
     */
    public function with_detail($key, $value)
    {
        $clone = clone $this;
        $clone->details[(string) $key] = $value;
        return $clone;
    }

    /**
     * Return a cloned result with an appended secondary code.
     *
     * NOTE:
     * This does not dedupe. If you care, callers should dedupe before displaying.
     *
     * @param string $code
     * @return self
     */
    public function with_code($code)
    {
        $clone = clone $this;

        $code = trim((string) $code);
        if ($code !== '') {
            $clone->codes[] = $code;
        }

        return $clone;
    }

    // -----------------------------
    // Control helpers
    // -----------------------------

    /**
     * True if this failure should be retried.
     *
     * @return bool
     */
    public function is_retryable()
    {
        return ($this->code === self::CODE_BLOCK_RETRYABLE);
    }

    /**
     * True if this failure is terminal.
     *
     * @return bool
     */
    public function is_fatal_block()
    {
        return ($this->code === self::CODE_BLOCK_FATAL);
    }
}

