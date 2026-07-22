<?php
declare(strict_types=1);

namespace FFLHub\FastBound;

use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small HTTP gateway for the FastBound endpoints used by receiving.
 *
 * The receiving screen owns the operator workflow. This client only handles
 * credentials, request formatting, and the create/find calls FastBound needs
 * for bound-book acquisition and disposition records.
 */
final class FastBoundApiClient
{
    private const BASE_URL = 'https://cloud.fastbound.com';

    /**
     * @return array{ok:bool,message:string,errors:array<int,string>}
     */
    public function readiness(bool $requires_audit_user = true): array
    {
        $errors = [];

        if (!Options::get_fastbound_enabled()) {
            $errors[] = 'FastBound integration is disabled.';
        }
        if (Options::get_fastbound_account_number() === '') {
            $errors[] = 'FastBound account number is missing.';
        }
        if (Options::get_fastbound_api_key() === '') {
            $errors[] = 'FastBound API key is missing.';
        }
        if ($requires_audit_user && Options::get_fastbound_audit_user_email() === '') {
            $errors[] = 'FastBound audit user email is missing.';
        }

        return [
            'ok' => empty($errors),
            'message' => empty($errors) ? 'FastBound is configured.' : implode(' ', $errors),
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function create_or_find_contact_by_ffl(string $ffl_number): array
    {
        $ffl_number = FFLRowMapper::normalize_ffl_number($ffl_number);
        if ($ffl_number === '') {
            return $this->failure('A valid destination FFL number is required.');
        }

        $found = $this->find_contact_by_ffl($ffl_number);
        if (empty($found['ok'])) {
            return $found;
        }
        if (!empty($found['contact_id'])) {
            $found['created'] = false;
            return $found;
        }

        $body = [
            'externalId' => 'fflhub-ffl-' . $this->compact_ffl($ffl_number),
            'fflNumber' => $ffl_number,
            'lookupFFL' => true,
        ];

        $created = $this->request('POST', 'Contacts', [], $body, true);
        if (empty($created['ok'])) {
            return $created;
        }

        $found = $this->find_contact_by_ffl($ffl_number);
        if (!empty($found['ok']) && !empty($found['contact_id'])) {
            $found['created'] = true;
            return $found;
        }

        return $this->failure('FastBound accepted the contact create request, but the new contact could not be found by FFL number.');
    }

    /**
     * @return array<string,mixed>
     */
    public function find_contact_by_ffl(string $ffl_number): array
    {
        $ffl_number = FFLRowMapper::normalize_ffl_number($ffl_number);
        if ($ffl_number === '') {
            return $this->failure('A valid FFL number is required.');
        }

        $response = $this->request(
            'GET',
            'Contacts',
            [
                'fflNumber' => $ffl_number,
                'take' => 1,
                'skip' => 0,
            ],
            null,
            false
        );
        if (empty($response['ok'])) {
            return $response;
        }

        $contacts = is_array($response['body']['contacts'] ?? null) ? $response['body']['contacts'] : [];
        $contact = is_array($contacts[0] ?? null) ? $contacts[0] : [];
        $contact_id = trim((string) ($contact['id'] ?? ''));

        return [
            'ok' => true,
            'found' => $contact_id !== '',
            'contact_id' => $contact_id,
            'contact' => $contact,
            'message' => $contact_id !== '' ? 'FastBound contact found.' : 'No FastBound contact exists for this FFL number yet.',
            'errors' => [],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create_and_commit_acquisition(array $payload): array
    {
        $response = $this->request(
            'POST',
            'Acquisitions/CreateAndCommit',
            ['listAcquiredItems' => 'true'],
            $payload,
            true
        );
        if (empty($response['ok'])) {
            return $response;
        }

        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $item = is_array($items[0] ?? null) ? $items[0] : [];

        return array_merge($response, [
            'acquisition_id' => trim((string) ($body['acquisitionId'] ?? '')),
            'contact_id' => trim((string) ($body['contactId'] ?? '')),
            'item_id' => trim((string) ($item['id'] ?? '')),
            'item' => $item,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create_and_commit_disposition(array $payload): array
    {
        $response = $this->request(
            'POST',
            'Dispositions/CreateAndCommit',
            ['listDisposedItems' => 'true'],
            $payload,
            true
        );
        if (empty($response['ok'])) {
            return $response;
        }

        $body = is_array($response['body'] ?? null) ? $response['body'] : [];

        return array_merge($response, [
            'disposition_id' => trim((string) ($body['dispositionId'] ?? '')),
            'contact_id' => trim((string) ($body['contactId'] ?? '')),
        ]);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, bool $requires_audit_user = true): array
    {
        $ready = $this->readiness($requires_audit_user);
        if (!$ready['ok']) {
            return $this->failure($ready['message'], $ready['errors']);
        }

        $account_number = Options::get_fastbound_account_number();
        $api_key = Options::get_fastbound_api_key();
        $url = self::BASE_URL . '/' . rawurlencode($account_number) . '/api/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($account_number . ':' . $api_key),
        ];
        if ($requires_audit_user) {
            $headers['X-AuditUser'] = Options::get_fastbound_audit_user_email();
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => 45,
            'redirection' => 2,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $json = wp_json_encode($body);
            if (!is_string($json)) {
                return $this->failure('FastBound request payload could not be encoded as JSON.');
            }

            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $json;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $this->failure('FastBound request failed: ' . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = $raw_body !== '' ? json_decode($raw_body, true) : [];
        if ($raw_body !== '' && !is_array($decoded)) {
            $decoded = ['raw' => $raw_body];
        }

        if ($code < 200 || $code >= 300) {
            return $this->failure(
                'FastBound returned HTTP ' . $code . ': ' . $this->short_body($raw_body),
                [],
                $code,
                is_array($decoded) ? $decoded : []
            );
        }

        return [
            'ok' => true,
            'status_code' => $code,
            'body' => is_array($decoded) ? $decoded : [],
            'message' => 'FastBound request completed.',
            'errors' => [],
        ];
    }

    /**
     * @param array<int,string> $errors
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function failure(string $message, array $errors = [], int $status_code = 0, array $body = []): array
    {
        if (empty($errors)) {
            $errors[] = $message;
        }

        return [
            'ok' => false,
            'status_code' => $status_code,
            'body' => $body,
            'message' => $message,
            'errors' => $errors,
        ];
    }

    private function compact_ffl(string $ffl_number): string
    {
        $compact = preg_replace('/[^A-Z0-9]/', '', strtoupper($ffl_number));

        return is_string($compact) ? $compact : '';
    }

    private function short_body(string $body): string
    {
        $body = trim(wp_strip_all_tags($body));
        if ($body === '') {
            return '(empty response body)';
        }

        return strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
    }
}
