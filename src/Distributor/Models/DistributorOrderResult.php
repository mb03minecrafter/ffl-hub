<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Result of attempting to PLACE an order with a distributor API.
 *
 * This is the "execution result" analogue to DistributorOrderValidationResult.
 * Validation answers: "Should we try to place an order?"
 * This answers:       "We tried; what happened and what should the job runner do next?"
 *
 * Design goals
 * -----------
 * 1) Job-runner friendly control-plane:
 *    - OK              => mark job success (or continue pipeline)
 *    - BLOCK_RETRYABLE => retry with backoff (transient failure)
 *    - BLOCK_FATAL     => stop retrying (needs human / data fix)
 *
 * 2) Machine-readable reason codes (codes[]):
 *    - Do not overload the primary code. Primary code is control-plane.
 *    - codes[] supports analytics, alerting, and "why did we fail?" summaries.
 *
 * 3) Stable, compact details:
 *    - details is intentionally "small and redacted"
 *    - do NOT shove raw request/response blobs here (you already have safe_raw_summary helpers).
 *
 * 4) PHP 7 compatibility:
 *    - no enums
 *    - no typed properties
 *
 * Conventions used throughout the project
 * ---------------------------------------
 * - ok (bool) duplicates code but is convenient for legacy checks.
 * - http_status is optional and may be 0 when unknown or non-HTTP transport.
 * - provider_error_code is optional; used for vendor StatusCode, auth error code, etc.
 * - external_order_ids supports multi-lane distributors (Lipsey's direct-ship non-FFL + direct-ship FFL, etc.)
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
    // Stored in $codes[] (plural).
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
     * NOTE:
     * This is what your job runner should branch on for control flow.
     *
     * @var string
     */
    public $code;

    /**
     * Reason codes (machine readable), like ValidationResult codes[].
     *
     * Examples:
     * - [RETRY_TIMEOUT]
     * - [FATAL_MISSING_CREDS]
     * - [FATAL_RESTRICTED, FATAL_OUT_OF_STOCK]  (rare, but possible)
     *
     * @var string[]
     */
    public $codes;

    /**
     * Human-readable message suitable for logs / admin UI.
     *
     * Keep concise; if you have lots of sub-errors, truncate and store structured details in $details.
     *
     * @var string
     */
    public $message;

    /**
     * Some distributors may create >1 external order id.
     *
     * Example:
     * - Lipsey's: direct-ship non-FFL returns one id, direct-ship FFL returns another.
     * - Future-proofing for scenarios where a single "job" places multiple sub-orders.
     *
     * @var string[]
     */
    public $external_order_ids;

    /**
     * Optional HTTP status if available (0 if unknown/not applicable).
     *
     * Useful for classification (429 rate limit, 5xx upstream, etc.).
     *
     * @var int
     */
    public $http_status;

    /**
     * Optional vendor/provider-specific error code (empty if none).
     *
     * Examples:
     * - RSR StatusCode
     * - "NOT_AUTHORIZED"
     * - "RATE_LIMIT"
     *
     * @var string
     */
    public $provider_error_code;

    /**
     * Optional small debug details (redacted).
     *
     * Intended for:
     * - small structured context (e.g., which PO failed, which LANE, a few item tails)
     * - safe summaries of payload/response (not the full raw)
     *
     * @var array<string,mixed>
     */
    public $details;

    /**
     * @param bool              $ok
     * @param string            $code
     * @param string            $message
     * @param string[]          $codes
     * @param int               $http_status
     * @param string            $provider_error_code
     * @param array<string,mixed> $details
     * @param string[]          $external_order_ids
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
        // Convenience flag (often used in legacy call-sites).
        $this->ok = (bool) $ok;

        // Normalize control-plane code.
        // Default to BLOCK_FATAL if caller passes garbage.
        $code = is_string($code) ? trim($code) : '';
        $this->code = ($code !== '') ? $code : self::CODE_BLOCK_FATAL;

        // Human-readable summary.
        $this->message = (string) $message;

        // Normalize codes[] (trim + drop empties).
        $out_codes = array();
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $out_codes[] = $c;
            }
        }
        $this->codes = $out_codes;

        // Transport/provider metadata.
        $this->http_status = (int) $http_status;
        $this->provider_error_code = trim((string) $provider_error_code);

        // Normalize external order ids (trim + drop empties).
        $ids = array();
        foreach ($external_order_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $this->external_order_ids = $ids;

        // Keep details always as array.
        $this->details = is_array($details) ? $details : array();
    }

    // -----------------------------
    // Factories (match validation style)
    // -----------------------------

    /**
     * Success result.
     *
     * @param string $message
     * @param string[] $external_order_ids
     * @param array<string,mixed> $details
     * @return self
     */
    public static function ok($message = 'OK', array $external_order_ids = array(), array $details = array())
    {
        // OK typically has no reason codes; keep codes[] empty by default.
        return new self(true, self::CODE_OK, (string) $message, array(), 0, '', $details, $external_order_ids);
    }

    /**
     * Retryable failure result.
     *
     * Use for:
     * - rate limits / throttling
     * - timeouts / network blips
     * - upstream 5xx / transient API errors
     *
     * @param string $message
     * @param string[] $codes
     * @param array<string,mixed> $details
     * @param int $http_status
     * @param string $provider_error_code
     * @param string[] $external_order_ids
     * @return self
     */
    public static function block_retryable($message, array $codes = array(), array $details = array(), $http_status = 0, $provider_error_code = '', array $external_order_ids = array())
    {
        // Ensure at least one retry reason code if caller forgets.
        if (empty($codes)) {
            $codes = array(self::REASON_RETRY_UNKNOWN);
        }

        return new self(
            false,
            self::CODE_BLOCK_RETRYABLE,
            (string) $message,
            $codes,
            (int) $http_status,
            (string) $provider_error_code,
            $details,
            $external_order_ids
        );
    }

    /**
     * Fatal (terminal) failure result.
     *
     * Use for:
     * - missing credentials
     * - invalid payload / bad request
     * - restricted/prohibited shipping
     * - mapping failures (cannot map UPC -> PartNum / ItemNo, etc.)
     *
     * @param string $message
     * @param string[] $codes
     * @param array<string,mixed> $details
     * @param int $http_status
     * @param string $provider_error_code
     * @param string[] $external_order_ids
     * @return self
     */
    public static function block_fatal($message, array $codes = array(), array $details = array(), $http_status = 0, $provider_error_code = '', array $external_order_ids = array())
    {
        // Ensure at least one fatal reason code if caller forgets.
        if (empty($codes)) {
            $codes = array(self::REASON_FATAL_UNKNOWN);
        }

        return new self(
            false,
            self::CODE_BLOCK_FATAL,
            (string) $message,
            $codes,
            (int) $http_status,
            (string) $provider_error_code,
            $details,
            $external_order_ids
        );
    }

    /**
     * Convenience: cancelled is modeled as fatal (terminal) with a specific reason code.
     *
     * @param string $message
     * @param array<string,mixed> $details
     * @return self
     */
    public static function cancelled($message = 'Cancelled', array $details = array())
    {
        return self::block_fatal((string) $message, array(self::REASON_CANCELLED), $details);
    }

    /**
     * Convenience: dry-run marker.
     *
     * NOTE:
     * This returns code=OK but ok=false to intentionally force callers to treat it specially if desired.
     * If you'd rather treat dry runs as "success", set ok=true instead.
     *
     * @param string $message
     * @param array<string,mixed> $details
     * @param string[] $external_order_ids
     * @return self
     */
    public static function dry_run($message = 'Dry run', array $details = array(), array $external_order_ids = array())
    {
        return new self(false, self::CODE_OK, (string) $message, array(self::REASON_DRY_RUN), 0, '', $details, $external_order_ids);
    }

    // -----------------------------
    // Helpers
    // -----------------------------

    /**
     * True if this result indicates the job runner should retry.
     *
     * @return bool
     */
    public function is_retryable()
    {
        return ($this->code === self::CODE_BLOCK_RETRYABLE);
    }

    /**
     * True if this result indicates the job runner should stop retrying.
     *
     * @return bool
     */
    public function is_fatal()
    {
        return ($this->code === self::CODE_BLOCK_FATAL);
    }

    /**
     * Convenience accessor for the first external order id (if any).
     *
     * @return string|null
     */
    public function first_external_order_id()
    {
        return isset($this->external_order_ids[0]) ? $this->external_order_ids[0] : null;
    }
}

