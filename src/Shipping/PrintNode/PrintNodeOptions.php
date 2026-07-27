<?php
declare(strict_types=1);

namespace FFLHub\Shipping\PrintNode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stored PrintNode settings for admin-side warehouse printing.
 *
 * PrintNode is not a shipping-rate provider; it is the transport that pushes
 * finished label/slip PDFs from WordPress to the desktop PrintNode client.
 */
final class PrintNodeOptions
{
    public const OPTION_SETTINGS = 'fflhub_printnode_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'api_key' => '',
            'default_printer_id' => '',
            'copies' => '1',
            'job_delay_seconds' => '20',
            'expire_after_seconds' => '86400',
            'fit_to_page' => '0',
            'printers' => [],
            'printers_refreshed_at' => '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_all(): array
    {
        $settings = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $merged = array_merge(self::defaults(), $settings);
        $merged['enabled'] = !empty($merged['enabled']) ? '1' : '0';
        $merged['api_key'] = trim((string) ($merged['api_key'] ?? ''));
        $merged['default_printer_id'] = sanitize_text_field((string) ($merged['default_printer_id'] ?? ''));
        $merged['copies'] = (string) max(1, min(10, (int) ($merged['copies'] ?? 1)));
        $merged['job_delay_seconds'] = (string) max(0, min(3600, (int) ($merged['job_delay_seconds'] ?? 20)));
        $merged['expire_after_seconds'] = (string) max(60, min(604800, (int) ($merged['expire_after_seconds'] ?? 86400)));
        $merged['fit_to_page'] = !empty($merged['fit_to_page']) ? '1' : '0';
        $merged['printers'] = self::sanitize_printers($merged['printers'] ?? []);
        $merged['printers_refreshed_at'] = sanitize_text_field((string) ($merged['printers_refreshed_at'] ?? ''));

        return $merged;
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save(array $input, bool $clear_api_key = false): void
    {
        $current = self::get_all();
        $saved = [
            'enabled' => !empty($input['enabled']) ? '1' : '0',
            'api_key' => $clear_api_key ? '' : (string) ($current['api_key'] ?? ''),
            'default_printer_id' => sanitize_text_field((string) ($input['default_printer_id'] ?? '')),
            'copies' => (string) max(1, min(10, (int) ($input['copies'] ?? 1))),
            'job_delay_seconds' => (string) max(0, min(3600, (int) ($input['job_delay_seconds'] ?? 20))),
            'expire_after_seconds' => (string) max(60, min(604800, (int) ($input['expire_after_seconds'] ?? 86400))),
            'fit_to_page' => !empty($input['fit_to_page']) ? '1' : '0',
            'printers' => self::sanitize_printers($current['printers'] ?? []),
            'printers_refreshed_at' => sanitize_text_field((string) ($current['printers_refreshed_at'] ?? '')),
        ];

        $posted_key = trim((string) ($input['api_key'] ?? ''));
        if ($posted_key !== '') {
            $saved['api_key'] = $posted_key;
        }

        update_option(self::OPTION_SETTINGS, $saved, false);
    }

    /**
     * @param array<int,array<string,mixed>> $printers
     */
    public static function save_printers(array $printers): void
    {
        $settings = self::get_all();
        $settings['printers'] = self::sanitize_printers($printers);
        $settings['printers_refreshed_at'] = current_time('mysql', true);
        update_option(self::OPTION_SETTINGS, $settings, false);
    }

    public static function is_enabled(): bool
    {
        return ((string) (self::get_all()['enabled'] ?? '0')) === '1';
    }

    public static function api_key(): string
    {
        if (defined('FFLHUB_PRINTNODE_API_KEY') && trim((string) constant('FFLHUB_PRINTNODE_API_KEY')) !== '') {
            return trim((string) constant('FFLHUB_PRINTNODE_API_KEY'));
        }

        $env = getenv('FFLHUB_PRINTNODE_API_KEY');
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }

        return trim((string) (self::get_all()['api_key'] ?? ''));
    }

    public static function api_key_source(): string
    {
        if (defined('FFLHUB_PRINTNODE_API_KEY') && trim((string) constant('FFLHUB_PRINTNODE_API_KEY')) !== '') {
            return 'constant:FFLHUB_PRINTNODE_API_KEY';
        }

        $env = getenv('FFLHUB_PRINTNODE_API_KEY');
        if ($env !== false && trim((string) $env) !== '') {
            return 'environment:FFLHUB_PRINTNODE_API_KEY';
        }

        return self::api_key() !== '' ? 'saved_option' : 'none';
    }

    public static function api_key_mask(): string
    {
        $key = self::api_key();
        if ($key === '') {
            return '';
        }

        return '********' . substr($key, -4);
    }

    public static function api_key_source_label(): string
    {
        $source = self::api_key_source();
        if ($source === 'none') {
            return 'not configured';
        }

        if (strpos($source, 'constant:') === 0) {
            return 'constant ' . substr($source, strlen('constant:'));
        }

        if (strpos($source, 'environment:') === 0) {
            return 'environment variable ' . substr($source, strlen('environment:'));
        }

        return 'saved option';
    }

    public static function default_printer_id(): int
    {
        return max(0, (int) (self::get_all()['default_printer_id'] ?? 0));
    }

    public static function copies(): int
    {
        return max(1, min(10, (int) (self::get_all()['copies'] ?? 1)));
    }

    public static function job_delay_seconds(): int
    {
        return max(0, min(3600, (int) (self::get_all()['job_delay_seconds'] ?? 20)));
    }

    public static function expire_after_seconds(): int
    {
        return max(60, min(604800, (int) (self::get_all()['expire_after_seconds'] ?? 86400)));
    }

    public static function fit_to_page(): bool
    {
        return ((string) (self::get_all()['fit_to_page'] ?? '0')) === '1';
    }

    /**
     * @return array<int,array{id:int,name:string,description:string,state:string,computer_id:int,computer_name:string,is_default:bool}>
     */
    public static function printers(): array
    {
        return self::sanitize_printers(self::get_all()['printers'] ?? []);
    }

    public static function printers_refreshed_at(): string
    {
        return (string) (self::get_all()['printers_refreshed_at'] ?? '');
    }

    public static function configured(): bool
    {
        return self::is_enabled() && self::api_key() !== '' && self::default_printer_id() > 0;
    }

    /**
     * @param mixed $printers
     * @return array<int,array{id:int,name:string,description:string,state:string,computer_id:int,computer_name:string,is_default:bool}>
     */
    private static function sanitize_printers($printers): array
    {
        if (!is_array($printers)) {
            return [];
        }

        $out = [];
        foreach ($printers as $printer) {
            if (!is_array($printer)) {
                continue;
            }

            $id = max(0, (int) ($printer['id'] ?? 0));
            if ($id <= 0) {
                continue;
            }

            $out[] = [
                'id' => $id,
                'name' => sanitize_text_field((string) ($printer['name'] ?? '')),
                'description' => sanitize_text_field((string) ($printer['description'] ?? '')),
                'state' => sanitize_text_field((string) ($printer['state'] ?? '')),
                'computer_id' => max(0, (int) ($printer['computer_id'] ?? 0)),
                'computer_name' => sanitize_text_field((string) ($printer['computer_name'] ?? '')),
                'is_default' => !empty($printer['is_default']),
            ];
        }

        return array_values($out);
    }
}
