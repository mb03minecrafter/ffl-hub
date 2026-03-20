<?php

declare(strict_types=1);

namespace FFLHub\Checkout\Compliance;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;

use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;

use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Util\DebugLogUtil;

final class CartCompliance
{
    private const SESSION_KEY_RECEIVING_FFL    = 'fflhub_receiving_ffl_number';
    private const ORDER_META_KEY_RECEIVING_FFL = 'fflhub_receiving_ffl_number';
    private const NOTICE_DATA_KEY              = 'fflhub_code';
    private const NOTICE_DATA_VAL              = 'cart_compliance';

    // -----------------------------
    // PROFILING (unchanged behavior)
    // -----------------------------
    private const PROFILING_ENV  = 'FFLHUB_CART_COMPLIANCE_PROFILE';
    private const PROFILE_PREFIX = '[FFLHub CartCompliance PROFILE] ';

    /**
     * @var array<string,array{t0:float,mem0:int}>
     */
    private static array $profile_spans = [];

    // -----------------------------
    // DEBUG LOGGING (new, behavior-preserving)
    // -----------------------------
    private const DEBUG_ENV    = 'FFLHUB_CART_COMPLIANCE_DEBUG';
    private const DEBUG_PREFIX = '[FFLHub CartCompliance DEBUG] ';

    private FFLTable $ffl_table;
    private DistributorHandler $handler;

    /** @var string */
    private string $run_id = '';

    public function __construct(FFLTable $ffl_table, DistributorHandler $handler)
    {
        $this->ffl_table = $ffl_table;
        $this->handler   = $handler;
    }

    /**
     * Wire hooks (instance-based).
     *
     * IMPORTANT: caller should create ONE instance and call register().
     */
    public function register(): void
    {
        add_action(
            'woocommerce_store_api_checkout_update_order_meta',
            [$this, 'storeapi_checkout_gate'],
            1000,
            1
        );
    }

    /* ---------------- Profiling helpers ---------------- */

    private static function profiling_enabled(): bool
    {
        if (defined(self::PROFILING_ENV)) {
            return (bool) constant(self::PROFILING_ENV);
        }

        $env = getenv(self::PROFILING_ENV);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            return in_array($env, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function prof_start(string $span, array $context = []): void
    {
        $enabled = self::profiling_enabled();
        if (!$enabled) {
            return;
        }

        self::$profile_spans[$span] = [
            't0'   => microtime(true),
            'mem0' => function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0,
        ];

        if (!empty($context)) {
            DebugLogUtil::log_if_ctx($enabled, self::PROFILE_PREFIX, "START {$span}", $context, self::PROFILING_ENV);
        } else {
            DebugLogUtil::log_if($enabled, self::PROFILE_PREFIX, "START {$span}", self::PROFILING_ENV);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function prof_end(string $span, array $context = []): void
    {
        $enabled = self::profiling_enabled();
        if (!$enabled) {
            return;
        }

        $t1   = microtime(true);
        $mem1 = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $t0   = isset(self::$profile_spans[$span]['t0']) ? (float) self::$profile_spans[$span]['t0'] : null;
        $mem0 = isset(self::$profile_spans[$span]['mem0']) ? (int) self::$profile_spans[$span]['mem0'] : 0;

        $ms   = ($t0 !== null) ? (int) round(($t1 - $t0) * 1000.0) : -1;
        $dmem = $mem1 - $mem0;

        $payload = array_merge($context, [
            'ms'        => $ms,
            'mem_delta' => $dmem,
            'mem_now'   => $mem1,
        ]);

        DebugLogUtil::log_if_ctx($enabled, self::PROFILE_PREFIX, "END {$span}", $payload, self::PROFILING_ENV);

        unset(self::$profile_spans[$span]);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @param array<string,mixed> $context
     * @return T
     */
    private static function prof(string $span, callable $fn, array $context = [])
    {
        self::prof_start($span, $context);
        try {
            return $fn();
        } finally {
            self::prof_end($span, $context);
        }
    }

    /* ---------------- Debug helpers ---------------- */

    private function debug_enabled(): bool
    {
        if (defined(self::DEBUG_ENV)) {
            return (bool) constant(self::DEBUG_ENV);
        }

        $env = getenv(self::DEBUG_ENV);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            if (in_array($env, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (defined('WP_DEBUG') && WP_DEBUG);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function dbg(string $event, array $payload = []): void
    {
        $enabled = $this->debug_enabled();
        if (!$enabled) {
            return;
        }

        $base = [
            'run_id' => $this->run_id,
            'event'  => $event,
        ];

        DebugLogUtil::log_if_ctx(
            $enabled,
            self::DEBUG_PREFIX,
            'event',
            array_merge($base, $payload),
            self::DEBUG_ENV
        );
    }

    private function dbg_line(string $message): void
    {
        $enabled = $this->debug_enabled();
        if (!$enabled) {
            return;
        }

        DebugLogUtil::log_if(
            $enabled,
            self::DEBUG_PREFIX,
            $message,
            self::DEBUG_ENV
        );
    }

    private static function log_text(string $value, int $max = 120): string
    {
        $value = trim((string) $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        if ($value === '') {
            return '';
        }

        if ($max > 3 && strlen($value) > $max) {
            return substr($value, 0, $max - 3) . '...';
        }

        return $value;
    }

    private static function log_scalar($value): string
    {
        if ($value === null) {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_scalar($value)) {
            return '-';
        }

        $s = self::log_text((string) $value, 90);
        return ($s !== '') ? $s : '-';
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function summarize_validation_details(array $details): array
    {
        $out = [];

        if (isset($details['required_by_upc']) && is_array($details['required_by_upc'])) {
            $required_rows = [];
            foreach ($details['required_by_upc'] as $upc => $qty) {
                $upc = trim((string) $upc);
                if ($upc === '') {
                    continue;
                }

                $required_rows[] = [
                    'upc' => $upc,
                    'qty' => (int) $qty,
                ];
            }

            $out['required_count'] = count($required_rows);
            if (!empty($required_rows)) {
                $out['required'] = array_slice($required_rows, 0, 8);
                if (count($required_rows) > 8) {
                    $out['required_more'] = count($required_rows) - 8;
                }
            }
        }

        if (isset($details['items']) && is_array($details['items'])) {
            $item_rows = [];

            foreach ($details['items'] as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $upc = preg_replace('/\D+/', '', (string) $key);
                if (!is_string($upc)) {
                    $upc = '';
                }
                if ($upc === '') {
                    $upc = preg_replace('/\D+/', '', (string) ($row['upc'] ?? ''));
                    if (!is_string($upc)) {
                        $upc = '';
                    }
                }
                if ($upc === '') {
                    $upc = trim((string) $key);
                }

                $item = ['upc' => $upc];

                if (array_key_exists('requiredQty', $row)) {
                    $item['required'] = (int) $row['requiredQty'];
                }
                if (array_key_exists('qty', $row)) {
                    $item['qty'] = (int) $row['qty'];
                }
                if (array_key_exists('blocked', $row)) {
                    $item['blocked'] = ((bool) $row['blocked']) ? 1 : 0;
                }
                if (array_key_exists('allocated', $row)) {
                    $item['allocated'] = ((bool) $row['allocated']) ? 1 : 0;
                }
                if (array_key_exists('canDropship', $row)) {
                    $cd = $row['canDropship'];
                    $item['can_dropship'] = ($cd === null) ? 'null' : (((bool) $cd) ? 1 : 0);
                }

                $reason = self::log_text((string) ($row['reason'] ?? ''), 40);
                if ($reason !== '') {
                    $item['reason'] = $reason;
                }

                $msg = self::log_text((string) ($row['message'] ?? ''), 80);
                if ($msg !== '' && strtoupper($msg) !== 'OK') {
                    $item['msg'] = $msg;
                }

                $item_rows[] = $item;
            }

            $out['item_count'] = count($item_rows);
            if (!empty($item_rows)) {
                $out['items'] = array_slice($item_rows, 0, 10);
                if (count($item_rows) > 10) {
                    $out['items_more'] = count($item_rows) - 10;
                }
            }
        }

        foreach (['local_only', 'quota_triggered', 'cache_ttl_seconds'] as $k) {
            if (array_key_exists($k, $details)) {
                $out[$k] = $details[$k];
            }
        }

        return $out;
    }

    /**
     * @param string[] $lanes
     * @param string[] $codes
     * @param array<string,mixed> $details_summary
     */
    private function dbg_vote_blocked_pretty(
        string $cart_dist_id,
        string $voter_id,
        string $label,
        array $lanes,
        array $codes,
        string $message,
        array $details_summary
    ): void {
        $lane_str = empty($lanes) ? '-' : implode(',', array_map('strval', $lanes));
        $code_str   = empty($codes) ? '-' : implode(',', array_map('strval', $codes));

        $this->dbg_line(sprintf(
            'BLOCKED cart=%s voter=%s label=%s lanes=%s codes=%s msg="%s"',
            $cart_dist_id,
            $voter_id,
            self::log_text($label, 28),
            $lane_str,
            $code_str,
            self::log_text($message, 170)
        ));

        if (isset($details_summary['items']) && is_array($details_summary['items'])) {
            foreach ($details_summary['items'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $this->dbg_line(sprintf(
                    'BLOCKED_ITEM upc=%s req=%s qty=%s blocked=%s allocated=%s canDropship=%s reason=%s msg=%s',
                    self::log_scalar($row['upc'] ?? null),
                    self::log_scalar($row['required'] ?? null),
                    self::log_scalar($row['qty'] ?? null),
                    self::log_scalar($row['blocked'] ?? null),
                    self::log_scalar($row['allocated'] ?? null),
                    self::log_scalar($row['can_dropship'] ?? null),
                    self::log_scalar($row['reason'] ?? null),
                    self::log_scalar($row['msg'] ?? null)
                ));
            }

            if (isset($details_summary['items_more'])) {
                $this->dbg_line(sprintf('BLOCKED_ITEM more=%s', self::log_scalar($details_summary['items_more'])));
            }
        }
    }

    /**
     * Attempt to extract stable identifiers from a cart/order line.
     * This is best-effort and never affects behavior.
     *
     * @param mixed $line
     * @return array<string,mixed>
     */
    private function summarize_line($line): array
    {
        $out = [];

        if (is_array($line)) {
            foreach (['sku', 'upc', 'item_number', 'itemNumber', 'product_id', 'variation_id', 'qty', 'quantity', 'name', 'title'] as $k) {
                if (array_key_exists($k, $line) && $line[$k] !== null && $line[$k] !== '') {
                    $out[$k] = $line[$k];
                }
            }
            return $out;
        }

        if (is_object($line)) {
            foreach (['sku', 'upc', 'item_number', 'itemNumber', 'product_id', 'variation_id', 'qty', 'quantity', 'name', 'title'] as $k) {
                if (isset($line->{$k}) && $line->{$k} !== null && $line->{$k} !== '') {
                    $out[$k] = $line->{$k};
                }
            }

            // If it has a toArray(), grab a few keys from that too.
            if (method_exists($line, 'toArray')) {
                try {
                    $arr = $line->toArray();
                    if (is_array($arr)) {
                        foreach (['sku', 'upc', 'item_number', 'itemNumber', 'product_id', 'variation_id', 'qty', 'quantity', 'name', 'title'] as $k) {
                            if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') {
                                $out[$k] = $arr[$k];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // swallow
                }
            }

            return $out;
        }

        return $out;
    }

    /**
     * @param array<int,mixed> $lines
     * @return array<int,array<string,mixed>>
     */
    private function summarize_lines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $s = $this->summarize_line($line);
            if (!empty($s)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /* ---------------- Hook gates ---------------- */

    private function is_real_checkout_submit_request(): bool
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        if ($method !== 'POST') {
            return false;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        // Store API checkout endpoint used by Blocks
        return str_contains($uri, '/wp-json/wc/store/v1/checkout');
    }

    public function storeapi_checkout_gate(\WC_Order $order): void
    {
        // unique id for this request execution
        $this->run_id = gmdate('His') . '-' . substr(sha1((string) microtime(true)), 0, 6);

        if (!$this->is_real_checkout_submit_request()) {
            return;
        }

        $this->dbg('checkout_gate.hit', [
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ]);

        try {
            $this->validate_cart_for_compliance($order);
        } catch (\Throwable $e) {
            $order->add_order_note('FFLHub: Checkout blocked by compliance validation: ' . $e->getMessage());
            $order->save();

            $this->dbg('checkout_gate.exception', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            throw $e;
        }
    }

    /* ---------------- Entry points ---------------- */

    public function validate_cart_for_compliance(?\WC_Order $order = null): void
    {
        self::prof_start('validate_cart_for_compliance.total');

        try {
            if (!function_exists('WC') || !WC()->cart) {
                return;
            }

            $handler = $this->handler;
            $checkout_data = [];

            $ship_customer = self::prof(
                'build_ship_to_customer_or_null(cart)',
                function () use ($checkout_data) {
                    return CheckoutOrderRequestBuilder::build_ship_to_customer_or_null($checkout_data);
                }
            );

            if (!($ship_customer instanceof DistributorShipTo)) {
                return;
            }

            [$receiving_ffl_number, $ship_ffl] = self::prof(
                'resolve_ffl_context(cart)',
                function () use ($order) {
                    return $this->resolve_ffl_context(true, $order);
                }
            );

            $blocked = self::prof(
                'run_distributor_validations(cart)',
                function () use ($handler, $ship_customer, $ship_ffl, $receiving_ffl_number) {
                    return $this->run_distributor_validations($handler, $ship_customer, $ship_ffl, $receiving_ffl_number);
                }
            );

            $this->dbg('validate_cart_for_compliance.blocked_summary', [
                'blocked_count' => is_array($blocked) ? count($blocked) : 0,
                'blocked'       => is_array($blocked) ? array_map(function ($b) {
                    return [
                        'id'     => $b['id'] ?? '',
                        'label'  => $b['label'] ?? '',
                        'lane' => $b['lane'] ?? '',
                        'codes'  => $b['codes'] ?? [],
                        'msg'    => $b['message'] ?? '',
                    ];
                }, $blocked) : [],
            ]);

            self::prof(
                'emit_blocked_notices(cart)',
                function () use ($blocked) {
                    $this->emit_blocked_notices($blocked, null);
                    return null;
                },
                ['blocked_count' => is_array($blocked) ? count($blocked) : 0]
            );
        } finally {
            self::prof_end('validate_cart_for_compliance.total');
        }
    }

    /**
     * Session-only resolution (intended).
     *
     * @return array{0:?string,1:?DistributorShipTo}
     */
    private function resolve_ffl_context(bool $verify_session, ?\WC_Order $order = null): array
    {
        self::prof_start('resolve_ffl_context.total', ['verify_session' => $verify_session ? '1' : '0']);

        try {
            $resolved_from_order = false;

            $receiving_ffl_number = self::prof(
                'resolve_receiving_ffl_number',
                function () {
                    return CheckoutOrderRequestBuilder::resolve_receiving_ffl_number(
                        self::SESSION_KEY_RECEIVING_FFL,
                        null
                    );
                }
            );

            if ($receiving_ffl_number === null && $order instanceof \WC_Order) {
                $from_order = strtoupper(trim((string) $order->get_meta(self::ORDER_META_KEY_RECEIVING_FFL, true)));
                if ($from_order !== '' && preg_match('/^[A-Z0-9-]+$/', $from_order)) {
                    $receiving_ffl_number = $from_order;
                    $resolved_from_order = true;
                }
            }

            if ($verify_session) {
                CheckoutOrderRequestBuilder::persist_receiving_ffl_to_session(
                    $receiving_ffl_number,
                    self::SESSION_KEY_RECEIVING_FFL,
                    null
                );
            }

            $ship_ffl = null;

            if ($receiving_ffl_number) {
                $ship_ffl = CheckoutOrderRequestBuilder::build_ship_to_ffl_or_null(
                    $this->ffl_table,
                    (string) $receiving_ffl_number,
                    null
                );

                if (!($ship_ffl instanceof DistributorShipTo)) {
                    $ship_ffl = null;
                }
            }

            $this->dbg('resolve_ffl_context.result', [
                'verify_session'       => $verify_session ? 1 : 0,
                'receiving_ffl_number' => $receiving_ffl_number !== null ? (string) $receiving_ffl_number : null,
                'ship_ffl_ok'          => ($ship_ffl instanceof DistributorShipTo) ? 1 : 0,
                'resolved_from_order'  => $resolved_from_order ? 1 : 0,
            ]);

            return [$receiving_ffl_number !== null ? (string) $receiving_ffl_number : null, $ship_ffl];
        } finally {
            self::prof_end('resolve_ffl_context.total');
        }
    }

    /**
     * @param array<int,array{id:string,label:string,message:string,codes:array,details:array,lane:string,pretty?:array}> $blocked
     */
    private function emit_blocked_notices(array $blocked, ?\WP_Error $errors = null): void
    {
        self::prof_start('emit_blocked_notices.total', [
            'blocked_count' => is_array($blocked) ? count($blocked) : 0,
        ]);

        try {
            // Always clear prior FFLHub compliance notices so stale errors do not linger
            // after a subsequent successful real-time revalidation.
            $this->clear_existing_compliance_notices();

            $seen = [];

            foreach ($blocked as $b) {
                $pretty = isset($b['pretty']) && is_array($b['pretty']) ? $b['pretty'] : [];

                if (!empty($pretty)) {
                    foreach ($pretty as $msg) {
                        $msg = trim((string) $msg);
                        if ($msg === '') {
                            continue;
                        }
                        if (isset($seen[$msg])) {
                            continue;
                        }
                        $seen[$msg] = true;

                        wc_add_notice($msg, 'error', [
                            self::NOTICE_DATA_KEY => self::NOTICE_DATA_VAL,
                        ]);
                        if ($errors instanceof \WP_Error) {
                            $errors->add('fflhub_cart_compliance', $msg);
                        }
                    }
                    continue;
                }

                $fallback = __('We couldn\'t validate one or more items in your cart. Please contact us for help.', 'ffl-hub');
                if (isset($seen[$fallback])) {
                    continue;
                }
                $seen[$fallback] = true;

                wc_add_notice($fallback, 'error', [
                    self::NOTICE_DATA_KEY => self::NOTICE_DATA_VAL,
                ]);
                if ($errors instanceof \WP_Error) {
                    $errors->add('fflhub_cart_compliance', $fallback);
                }
            }
        } finally {
            self::prof_end('emit_blocked_notices.total');
        }
    }

    /**
     * Remove only FFLHub cart-compliance notices from the Woo notice bag.
     * Leaves unrelated checkout errors intact.
     */
    private function clear_existing_compliance_notices(): void
    {
        if (!function_exists('wc_get_notices') || !function_exists('wc_set_notices')) {
            return;
        }

        $all = wc_get_notices();
        if (!is_array($all) || empty($all)) {
            return;
        }

        $missing_msg = __(
            'This cart contains items that must ship to a receiving FFL. Please select a receiving FFL to continue checkout.',
            'ffl-hub'
        );
        $fallback_msg = __('We couldn\'t validate one or more items in your cart. Please contact us for help.', 'ffl-hub');

        foreach ($all as $type => $notices) {
            if (!is_array($notices)) {
                continue;
            }

            $kept = [];

            foreach ($notices as $notice) {
                if (!is_array($notice)) {
                    $kept[] = $notice;
                    continue;
                }

                $data = isset($notice['data']) && is_array($notice['data']) ? $notice['data'] : [];
                $msg  = trim((string) ($notice['notice'] ?? ''));

                $is_marked = (($data[self::NOTICE_DATA_KEY] ?? '') === self::NOTICE_DATA_VAL);
                $is_legacy_known = ($msg !== '' && ($msg === $missing_msg || $msg === $fallback_msg));

                if ($is_marked || $is_legacy_known) {
                    continue;
                }

                $kept[] = $notice;
            }

            $all[$type] = $kept;
        }

        wc_set_notices($all);
    }

    /**
     * @return array<int,array{id:string,label:string,message:string,codes:array,details:array,lane:string,pretty?:array}>
     */
    private function run_distributor_validations(
        DistributorHandler $handler,
        DistributorShipTo $ship_customer,
        ?DistributorShipTo $ship_ffl,
        ?string $receiving_ffl_number
    ): array {
        self::prof_start('run_distributor_validations.total');

        try {
            $by_dist = self::prof(
                'build_lines_grouped_by_distributor_from_cart',
                function () {
                    return CheckoutOrderRequestBuilder::build_lines_grouped_by_distributor_from_cart();
                }
            );

            if (empty($by_dist)) {
                return [];
            }

            $enabled_distributors = self::prof(
                'get_enabled_distributors_for_validation',
                function () use ($handler) {
                    return $handler->get_enabled_distributors_for_validation();
                }
            );

            if (empty($enabled_distributors)) {
                return [];
            }

            $blocked = [];

            foreach ($by_dist as $cart_dist_id => $ctx) {
                $cart_dist_id = strtolower(trim((string) $cart_dist_id));
                if ($cart_dist_id === '') {
                    continue;
                }

                $lines   = isset($ctx['lines']) && is_array($ctx['lines']) ? $ctx['lines'] : [];
                $has_ffl = !empty($ctx['has_ffl']);

                if (empty($lines)) {
                    continue;
                }

                $this->dbg('cart.dist.lane', [
                    'cart_dist_id' => $cart_dist_id,
                    'has_ffl'      => $has_ffl ? 1 : 0,
                    'line_count'   => count($lines),
                    'lines'        => $this->summarize_lines($lines),
                ]);

                // This customer-facing message is specifically about a missing selection.
                // Only fire it when the receiving FFL number itself is absent.
                if ($has_ffl && !$receiving_ffl_number) {
                    $this->dbg('block.ffl_missing', [
                        'cart_dist_id'          => $cart_dist_id,
                        'has_ffl'               => 1,
                        'receiving_ffl_present' => $receiving_ffl_number ? 1 : 0,
                        'ship_ffl_present'      => ($ship_ffl instanceof DistributorShipTo) ? 1 : 0,
                        'codes'                 => ['FFLHUB_RECEIVING_FFL_REQUIRED'],
                        'lane'                => 'ffl_missing',
                    ]);

                    $blocked[] = [
                        'id'      => $cart_dist_id,
                        'label'   => $cart_dist_id,
                        'message' => 'FFL required but not selected',
                        'codes'   => ['FFLHUB_RECEIVING_FFL_REQUIRED'],
                        'details' => [],
                        'lane'   => 'ffl_missing',
                        'pretty'  => [
                            __('This cart contains items that must ship to a receiving FFL. Please select a receiving FFL to continue checkout.', 'ffl-hub'),
                        ],
                    ];
                    continue;
                }

                $dest_state = strtoupper(trim((string) (
                    ($has_ffl && $ship_ffl instanceof DistributorShipTo) ? $ship_ffl->state : $ship_customer->state
                )));

                if (!preg_match('/^[A-Z]{2}$/', $dest_state)) {
                    $this->dbg('cart.dest_state.invalid', [
                        'cart_dist_id' => $cart_dist_id,
                        'dest_state'   => $dest_state,
                    ]);
                    continue;
                }

                $merchant_order_id = CheckoutOrderRequestBuilder::current_merchant_order_id($cart_dist_id);

                $req = new DistributorOrderRequest(
                    $lines,
                    $ship_customer,
                    ($ship_ffl instanceof DistributorShipTo) ? $ship_ffl : null,
                    $merchant_order_id,
                    $dest_state,
                    $receiving_ffl_number ? (string) $receiving_ffl_number : '',
                    'FFLHub checkout validation (single DOR; validate against all distributors)'
                );

                // Vote across all validators
                foreach ($enabled_distributors as $voter_id => $voter) {
                    $voter_id_str = (string) $voter_id;

                    $dist = $voter['instance'] ?? null;
                    if (!($dist instanceof DistributorBase)) {
                        continue;
                    }

                    $label = isset($voter['label']) ? (string) $voter['label'] : $voter_id_str;

                    $lines_for_voter = method_exists($dist, 'filter_lines_for_validation')
                        ? $dist->filter_lines_for_validation($req->valid_lines())
                        : $req->valid_lines();

                    if (empty($lines_for_voter)) {
                        continue;
                    }

                    $voter_req = new DistributorOrderRequest(
                        $lines_for_voter,
                        $req->ship_to_customer,
                        $req->ship_to_ffl,
                        $req->merchant_order_id,
                        $req->dest_state,
                        $req->receiving_ffl_number,
                        $req->notes
                    );

                    $vr = $this->call_validate($dist, $voter_req);
                    if (!($vr instanceof DistributorOrderValidationResult)) {
                        continue;
                    }

                    if ($vr->ok) {
                        $this->dbg('vote.ok', [
                            'cart_dist_id' => $cart_dist_id,
                            'voter_id'     => strtolower(trim($voter_id_str)),
                            'label'        => $label,
                            'lane_hint'  => (!empty($voter_req->ffl_required_lines()) && empty($voter_req->non_ffl_required_lines()))
                                ? 'direct_ship_ffl'
                                : ((!empty($voter_req->non_ffl_required_lines()) && empty($voter_req->ffl_required_lines()))
                                    ? 'direct_ship_non_ffl'
                                    : 'mixed'),
                        ]);
                        continue;
                    }

                    // Ignore out-of-stock blocks unless the voter is the cart's source distributor.
                    if ($this->should_ignore_vote_for_cart_source($cart_dist_id, $voter_id_str, $vr)) {
                        $details_summary = $this->summarize_validation_details(
                            is_array($vr->details) ? $vr->details : []
                        );

                        $this->dbg('vote.ignored_oos_non_source', [
                            'cart_dist_id' => $cart_dist_id,
                            'voter_id'     => strtolower(trim($voter_id_str)),
                            'label'        => $label,
                            'codes'        => is_array($vr->codes) ? $vr->codes : [],
                            'message'      => (string) ($vr->message ?? ''),
                            'details'      => $details_summary,
                        ]);
                        continue;
                    }

                    // Vague message for system-ish failures; otherwise show pretty restriction messaging
                    if ($this->should_use_vague_customer_message($vr)) {
                        $details_summary = $this->summarize_validation_details(
                            is_array($vr->details) ? $vr->details : []
                        );

                        $this->dbg('vote.blocked.vague', [
                            'cart_dist_id' => $cart_dist_id,
                            'voter_id'     => strtolower(trim($voter_id_str)),
                            'label'        => $label,
                            'lane'       => 'single',
                            'codes'        => is_array($vr->codes) ? $vr->codes : [],
                            'message'      => (string) ($vr->message ?? ''),
                            'details'      => $details_summary,
                            'line_count'   => count($lines_for_voter),
                        ]);

                        $blocked[] = [
                            'id'      => $voter_id_str,
                            'label'   => $label,
                            'message' => (string) ($vr->message ?: 'Validation failed'),
                            'codes'   => is_array($vr->codes) ? $vr->codes : [],
                            'details' => [],
                            'lane'   => 'single',
                            'pretty'  => [
                                __('We couldn\'t validate one or more items in your cart. Please contact us for help.', 'ffl-hub'),
                            ],
                        ];
                        continue;
                    }

                    $lanes = $this->resolve_lanes_for_pretty($vr, $voter_req);
                    $details_summary = $this->summarize_validation_details(
                        is_array($vr->details) ? $vr->details : []
                    );

                    $this->dbg('vote.blocked.pretty', [
                        'cart_dist_id' => $cart_dist_id,
                        'voter_id'     => strtolower(trim($voter_id_str)),
                        'label'        => $label,
                        'lanes'        => $lanes,
                        'codes'        => is_array($vr->codes) ? $vr->codes : [],
                        'message'      => (string) ($vr->message ?? ''),
                        'details'      => $details_summary,
                        'line_count'   => count($lines_for_voter),
                    ]);

                    $this->dbg_vote_blocked_pretty(
                        $cart_dist_id,
                        strtolower(trim($voter_id_str)),
                        $label,
                        $lanes,
                        is_array($vr->codes) ? $vr->codes : [],
                        (string) ($vr->message ?? ''),
                        $details_summary
                    );

                    foreach ($lanes as $lane) {
                        $pretty_msgs = CheckoutOrderRequestBuilder::build_pretty_validation_messages(
                            $label,
                            $vr,
                            $lane,
                            $ship_customer,
                            $ship_ffl
                        );

                        $blocked[] = [
                            'id'      => $voter_id_str,
                            'label'   => $label,
                            'message' => (string) ($vr->message ?: 'Validation failed'),
                            'codes'   => is_array($vr->codes) ? $vr->codes : [],
                            'details' => is_array($vr->details) ? $vr->details : [],
                            'lane'   => $lane,
                            'pretty'  => $pretty_msgs,
                        ];
                    }
                }
            }

            return $blocked;
        } finally {
            self::prof_end('run_distributor_validations.total');
        }
    }

    private function should_use_vague_customer_message(DistributorOrderValidationResult $vr): bool
    {
        $codes = is_array($vr->codes) ? $vr->codes : [];
        $msg   = strtolower((string) ($vr->message ?? ''));

        if (in_array('FFLHUB_VALIDATE_EXCEPTION', $codes, true)) {
            return true;
        }

        foreach ($codes as $c) {
            $c = (string) $c;
            if (stripos($c, 'TIMEOUT') !== false) return true;
            if (stripos($c, 'NETWORK') !== false) return true;
            if (stripos($c, 'REMOTE') !== false) return true;
            if (stripos($c, 'HTTP_5') !== false) return true;
            if (stripos($c, 'SERVER_ERROR') !== false) return true;
            if (stripos($c, 'TEMP') !== false) return true;
        }

        if (str_contains($msg, 'exception')) return true;
        if (str_contains($msg, 'timeout')) return true;
        if (str_contains($msg, 'temporar')) return true;
        if (str_contains($msg, 'try again')) return true;

        return false;
    }

    /**
     * @return string[] each of: 'direct_ship_non_ffl'|'direct_ship_ffl'
     */
    private function resolve_lanes_for_pretty(
        DistributorOrderValidationResult $vr,
        DistributorOrderRequest $voter_req
    ): array {
        $details = is_array($vr->details) ? $vr->details : [];

        $out = [];
        if (isset($details['direct_ship_non_ffl']) && is_array($details['direct_ship_non_ffl'])) {
            $out[] = 'direct_ship_non_ffl';
        }
        if (isset($details['direct_ship_ffl']) && is_array($details['direct_ship_ffl'])) {
            $out[] = 'direct_ship_ffl';
        }
        if (isset($details['dealer_fulfilled']) && is_array($details['dealer_fulfilled'])) {
            $out[] = 'dealer_fulfilled';
        }

        if (!empty($out)) {
            return array_values(array_unique($out));
        }

        $has_non = !empty($voter_req->non_ffl_required_lines());
        $has_ffl = !empty($voter_req->ffl_required_lines());

        if ($has_ffl && !$has_non) return ['direct_ship_ffl'];
        if ($has_non && !$has_ffl) return ['direct_ship_non_ffl'];

        return ['direct_ship_non_ffl', 'direct_ship_ffl'];
    }

    private function call_validate(
        DistributorBase $dist,
        DistributorOrderRequest $req
    ): ?DistributorOrderValidationResult {
        try {
            return $dist->validate_order_request($req);
        } catch (\Throwable $e) {
            $this->dbg('validate.exception', [
                'dist'  => get_class($dist),
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return new DistributorOrderValidationResult(
                false,
                'Validation error: ' . $e->getMessage(),
                ['FFLHUB_VALIDATE_EXCEPTION'],
                []
            );
        }
    }

    /**
     * Returns true if this validation result represents an out-of-stock condition.
     */
    private function is_out_of_stock_vote(DistributorOrderValidationResult $vr): bool
    {
        $codes = is_array($vr->codes) ? $vr->codes : [];
        foreach ($codes as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c === '') {
                continue;
            }

            // New-style explicit codes (from local validator) + old-style fuzzy matches
            if (
                str_contains($c, '_OUT_OF_STOCK') ||
                str_contains($c, 'OUT_OF_STOCK') ||
                str_contains($c, 'OOS') ||
                str_contains($c, 'NO_STOCK') ||
                str_contains($c, 'INSUFFICIENT_STOCK') ||
                str_contains($c, 'QTY_UNAVAILABLE')
            ) {
                return true;
            }
        }

        $details = is_array($vr->details) ? $vr->details : [];

        // Legacy details.reason
        if (isset($details['reason'])) {
            $r = strtoupper(trim((string) $details['reason']));
            if ($r === 'OUT_OF_STOCK' || $r === 'OOS') {
                return true;
            }
        }

        // New local validator shape: details.items[*].reason
        if (isset($details['items']) && is_array($details['items'])) {
            foreach ($details['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $reason = strtolower(trim((string) ($item['reason'] ?? '')));
                if (in_array($reason, ['insufficient', 'out_of_stock', 'oos'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * For non-source voters, ignore OOS blocks entirely.
     */
    private function should_ignore_vote_for_cart_source(
        string $cart_dist_id,
        string $voter_id,
        DistributorOrderValidationResult $vr
    ): bool {
        $cart_dist_id = strtolower(trim($cart_dist_id));
        $voter_id     = strtolower(trim($voter_id));

        if ($cart_dist_id === '' || $voter_id === '') {
            return false;
        }

        // Only ignore OUT_OF_STOCK when voter is NOT the source distributor.
        if ($cart_dist_id !== $voter_id && $this->is_out_of_stock_vote($vr)) {
            return true;
        }

        return false;
    }
}

