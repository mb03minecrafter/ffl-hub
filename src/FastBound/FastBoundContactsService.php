<?php
declare(strict_types=1);

namespace FFLHub\FastBound;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pulls FastBound contacts into a local admin cache.
 *
 * The settings page uses this cache for dropdowns so admins can pick contacts
 * by name/location instead of manually finding FastBound UUIDs.
 */
final class FastBoundContactsService
{
    private const BASE_URL = 'https://cloud.fastbound.com';
    private const PAGE_SIZE = 500;
    private const MAX_PAGES = 20;

    /**
     * @return array<string,mixed>
     */
    public static function refresh_cache(): array
    {
        $started = microtime(true);
        $result = [
            'ok' => true,
            'stage' => 'fastbound_contacts_refresh',
            'contacts_found' => 0,
            'contacts_cached' => 0,
            'records_reported' => null,
            'elapsed_ms' => '0.00',
            'errors' => [],
        ];

        $account_number = Options::get_fastbound_account_number();
        $api_key = Options::get_fastbound_api_key();

        if ($account_number === '' || $api_key === '') {
            $result['ok'] = false;
            $result['errors'][] = 'FastBound account number and API key are required before contacts can be refreshed.';
            return self::finish($result, $started);
        }

        $contacts = [];
        $records_reported = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $page_result = self::request_contacts_page($account_number, $api_key, $page);
            if (!$page_result['ok']) {
                $result['ok'] = false;
                $result['errors'] = array_merge($result['errors'], $page_result['errors']);
                return self::finish($result, $started);
            }

            if (is_int($page_result['records'])) {
                $records_reported = $page_result['records'];
            }

            $page_contacts = is_array($page_result['contacts']) ? $page_result['contacts'] : [];
            foreach ($page_contacts as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $normalized = self::normalize_contact($contact);
                if (empty($normalized['id'])) {
                    continue;
                }

                $contacts[(string) $normalized['id']] = $normalized;
            }

            if (count($page_contacts) < self::PAGE_SIZE) {
                break;
            }

            if (is_int($records_reported) && count($contacts) >= $records_reported) {
                break;
            }
        }

        $normalized_contacts = array_values($contacts);
        usort($normalized_contacts, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        Options::set_fastbound_contact_cache($normalized_contacts);

        $result['contacts_found'] = count($contacts);
        $result['contacts_cached'] = count($normalized_contacts);
        $result['records_reported'] = $records_reported;

        return self::finish($result, $started);
    }

    /**
     * @return array{ok:bool,contacts:array<int,mixed>,records:?int,errors:array<int,string>}
     */
    private static function request_contacts_page(string $account_number, string $api_key, int $page): array
    {
        $url = add_query_arg(
            [
                'take' => self::PAGE_SIZE,
                'skip' => $page,
            ],
            self::BASE_URL . '/' . rawurlencode($account_number) . '/api/Contacts'
        );

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($account_number . ':' . $api_key),
        ];

        $audit_user = Options::get_fastbound_audit_user_email();
        if ($audit_user !== '') {
            $headers['X-AuditUser'] = $audit_user;
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 30,
                'redirection' => 2,
                'headers' => $headers,
            ]
        );

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'contacts' => [],
                'records' => null,
                'errors' => ['FastBound contacts request failed: ' . $response->get_error_message()],
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'contacts' => [],
                'records' => null,
                'errors' => ['FastBound contacts request returned HTTP ' . $code . ': ' . self::short_body($body)],
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'contacts' => [],
                'records' => null,
                'errors' => ['FastBound contacts response was not valid JSON.'],
            ];
        }

        $contacts = is_array($decoded['contacts'] ?? null) ? $decoded['contacts'] : [];
        $records = isset($decoded['records']) && is_numeric($decoded['records']) ? (int) $decoded['records'] : null;

        return [
            'ok' => true,
            'contacts' => $contacts,
            'records' => $records,
            'errors' => [],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize_contact(array $row): array
    {
        $normalized = [
            'id' => self::field($row, 'id'),
            'external_id' => self::field($row, 'externalId'),
            'ffl_number' => strtoupper(self::field($row, 'fflNumber')),
            'ffl_expires' => self::field($row, 'fflExpires'),
            'license_name' => self::field($row, 'licenseName'),
            'trade_name' => self::field($row, 'tradeName'),
            'organization_name' => self::field($row, 'organizationName'),
            'first_name' => self::field($row, 'firstName'),
            'middle_name' => self::field($row, 'middleName'),
            'last_name' => self::field($row, 'lastName'),
            'suffix' => self::field($row, 'suffix'),
            'premise_address1' => self::field($row, 'premiseAddress1'),
            'premise_address2' => self::field($row, 'premiseAddress2'),
            'premise_city' => self::field($row, 'premiseCity'),
            'premise_state' => strtoupper(self::field($row, 'premiseState')),
            'premise_zip_code' => self::field($row, 'premiseZipCode'),
            'is_primary_account_contact' => !empty($row['isPrimaryAccountContact']),
        ];

        $normalized['label'] = self::contact_label($normalized);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function field(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        if (is_scalar($value)) {
            return trim(wp_strip_all_tags((string) $value));
        }

        return '';
    }

    /**
     * @param array<string,mixed> $contact
     */
    private static function contact_label(array $contact): string
    {
        $name_parts = array_filter([
            (string) ($contact['license_name'] ?? ''),
            (string) ($contact['trade_name'] ?? ''),
            (string) ($contact['organization_name'] ?? ''),
            trim(implode(' ', array_filter([
                (string) ($contact['first_name'] ?? ''),
                (string) ($contact['middle_name'] ?? ''),
                (string) ($contact['last_name'] ?? ''),
                (string) ($contact['suffix'] ?? ''),
            ]))),
        ]);

        $name = reset($name_parts);
        if (!is_string($name) || trim($name) === '') {
            $name = (string) ($contact['id'] ?? '');
        }

        $location = trim(implode(', ', array_filter([
            (string) ($contact['premise_city'] ?? ''),
            (string) ($contact['premise_state'] ?? ''),
        ])));

        $pieces = [$name];
        if ((string) ($contact['ffl_number'] ?? '') !== '') {
            $pieces[] = 'FFL ' . (string) $contact['ffl_number'];
        }
        if ($location !== '') {
            $pieces[] = $location;
        }

        return implode(' - ', $pieces);
    }

    private static function short_body(string $body): string
    {
        $body = trim(wp_strip_all_tags($body));
        if ($body === '') {
            return '(empty response body)';
        }

        if (strlen($body) > 300) {
            return substr($body, 0, 300) . '...';
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function finish(array $result, float $started): array
    {
        $result['elapsed_ms'] = number_format((microtime(true) - $started) * 1000, 2, '.', '');
        return $result;
    }
}
