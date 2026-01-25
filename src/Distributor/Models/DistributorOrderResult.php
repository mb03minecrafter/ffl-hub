<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Result of attempting to PLACE an order with a distributor API.
 *
 * Mirrors DistributorOrderValidationResult shape:
 *  - OK => success
 *  - BLOCK_RETRYABLE => retry with backoff
 *  - BLOCK_FATAL => terminal failure (human action)
 *
 * PHP 7 compatible (no enums, no typed properties).
 */
final class DistributorOrderResult
{
    // -----------------------------
    // Primary result codes (control-plane)
    // -----------------------------

    /** Success */
    const CODE_OK = 'OK';

    /** Retryable failure (worker should retry with backoff) */
    const CODE_BLOCK_RETRYABLE = 'BLOCK_RETRYABLE';

    /** Fatal failure (worker should fail terminal) */
    const CODE_BLOCK_FATAL = 'BLOCK_FATAL';

    // -----------------------------
    // Reason codes (machine + analytics)
    // These are similar to your existing CODE_* but now live in "codes[]".
    // -----------------------------

    // Retryable infra-ish
    const REASON_RETRY_RATE_LIMIT = 'RETRY_RATE_LIMIT';
    const REASON_RETRY_TIMEOUT    = 'RETRY_TIMEOUT';
    const REASON_RETRY_UPSTREAM   = 'RETRY_UPSTREAM';
    const REASON_RETRY_UNKNOWN    = 'RETRY_UNKNOWN';

    // Fatal
    const REASON_FATAL_MISSING_CREDS    = 'FATAL_MISSING_CREDS';
    const REASON_FATAL_CLIENT_MISSING   = 'FATAL_CLIENT_MISSING';
    const REASON_FATAL_SERVICES_MISSING = 'FATAL_SERVICES_MISSING';
    const REASON_FATAL_BAD_REQUEST      = 'FATAL_BAD_REQUEST';
    const REASON_FATAL_MAPPING          = 'FATAL_MAPPING';
    const REASON_FATAL_RESTRICTED       = 'FATAL_RESTRICTED';
    const REASON_FATAL_OUT_OF_STOCK     = 'FATAL_OUT_OF_STOCK';
    const REASON_FATAL_NOT_IMPLEMENTED  = 'FATAL_NOT_IMPLEMENTED';
    const REASON_FATAL_UNKNOWN          = 'FATAL_UNKNOWN';

    // Meta / control-plane (optional usage)
    const REASON_CANCELLED = 'CANCELLED';
    const REASON_DRY_RUN   = 'DRY_RUN';

    /** @var bool */
    public $ok;

    /**
     * Primary result code: OK | BLOCK_RETRYABLE | BLOCK_FATAL
     *
     * @var string
     */
    public $code;

    /**
     * Reason codes (machine readable), like VALIDATION codes[].
     *
     * @var string[]
     */
    public $codes;

    /** @var string */
    public $message;

    /**
     * Some distributors may create >1 external order id.
     *
     * @var string[]
     */
    public $external_order_ids;

    /**
     * Optional HTTP status if available (0 if unknown/not applicable).
     *
     * @var int
     */
    public $http_status;

    /**
     * Optional vendor/provider-specific error code (empty if none).
     *
     * @var string
     */
    public $provider_error_code;

    /**
     * Optional small debug details (redacted).
     *
     * @var array<string,mixed>
     */
    public $details;

    /**
     * @param bool $ok
     * @param string $code
     * @param string $message
     * @param string[] $codes
     * @param int $http_status
     * @param string $provider_error_code
     * @param array<string,mixed> $details
     * @param string[] $external_order_ids
     */
    public function __construct(
        $ok,
        $code,
        $message,
        array $codes = array(),
        $http_status = 0,
        $provider_error_code = '',
        array $details = array(),
        array $external_order_ids = array()
    ) {
        $this->ok = (bool) $ok;

        $code = is_string($code) ? trim($code) : '';
        $this->code = ($code !== '') ? $code : self::CODE_BLOCK_FATAL;

        $this->message = (string) $message;

        // Normalize codes[]
        $out_codes = array();
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $out_codes[] = $c;
            }
        }
        $this->codes = $out_codes;

        $this->http_status = (int) $http_status;
        $this->provider_error_code = trim((string) $provider_error_code);

        // Normalize external ids
        $ids = array();
        foreach ($external_order_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $this->external_order_ids = $ids;

        $this->details = is_array($details) ? $details : array();
    }

    // -----------------------------
    // Factories (match validation style)
    // -----------------------------

    public static function ok($message = 'OK', array $external_order_ids = array(), array $details = array())
    {
        return new self(true, self::CODE_OK, (string) $message, array(), 0, '', $details, $external_order_ids);
    }

    public static function block_retryable($message, array $codes = array(), array $details = array(), $http_status = 0, $provider_error_code = '', array $external_order_ids = array())
    {
        // Ensure at least one retry reason code if caller forgets.
        if (empty($codes)) {
            $codes = array(self::REASON_RETRY_UNKNOWN);
        }
        return new self(false, self::CODE_BLOCK_RETRYABLE, (string) $message, $codes, (int) $http_status, (string) $provider_error_code, $details, $external_order_ids);
    }

    public static function block_fatal($message, array $codes = array(), array $details = array(), $http_status = 0, $provider_error_code = '', array $external_order_ids = array())
    {
        // Ensure at least one fatal reason code if caller forgets.
        if (empty($codes)) {
            $codes = array(self::REASON_FATAL_UNKNOWN);
        }
        return new self(false, self::CODE_BLOCK_FATAL, (string) $message, $codes, (int) $http_status, (string) $provider_error_code, $details, $external_order_ids);
    }

    public static function cancelled($message = 'Cancelled', array $details = array())
    {
        return self::block_fatal((string) $message, array(self::REASON_CANCELLED), $details);
    }

    public static function dry_run($message = 'Dry run', array $details = array(), array $external_order_ids = array())
    {
        // Up to you whether worker treats DRY_RUN as terminal-success-ish or as a special state.
        return new self(false, self::CODE_OK, (string) $message, array(self::REASON_DRY_RUN), 0, '', $details, $external_order_ids);
    }

    // -----------------------------
    // Helpers
    // -----------------------------

    public function is_retryable()
    {
        return ($this->code === self::CODE_BLOCK_RETRYABLE);
    }

    public function is_fatal()
    {
        return ($this->code === self::CODE_BLOCK_FATAL);
    }

    public function first_external_order_id()
    {
        return isset($this->external_order_ids[0]) ? $this->external_order_ids[0] : null;
    }
}
