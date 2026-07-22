<?php
declare(strict_types=1);

namespace FFLHub\Receiving;

use FFLHub\FastBound\FastBoundApiClient;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\Settings\Options;
use WC_Order;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bound-book actions for serialized receiving scans.
 *
 * Receiving scans remain the local operational record. This service turns one
 * accepted serialized scan event into a FastBound acquisition, stores the
 * returned FastBound item ID, and later disposes that same item to the selected
 * receiving FFL contact.
 */
final class ReceivingFastBoundService
{
    private const ACQUISITION_TYPE = 'Dealer Transfer';
    private const DISPOSITION_TYPE = 'Dealer Transfer';
    private const DISPOSITION_REQUEST_TYPE = 'Regular';

    private ReceivingEventsStore $events;
    private FastBoundApiClient $client;

    public function __construct(?ReceivingEventsStore $events = null, ?FastBoundApiClient $client = null)
    {
        $this->events = $events ?: new ReceivingEventsStore();
        $this->client = $client ?: new FastBoundApiClient();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function acquire_event(int $event_id, array $input): array
    {
        global $wpdb;

        $lock_name = 'fflhub_fastbound_acquire_' . $event_id;
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock_name));
        if ($locked !== 1) {
            return $this->error('lock_timeout', __('FastBound acquisition is already running for this scan event.', 'ffl-hub'));
        }

        try {
            $event = $this->event_for_fastbound($event_id);
            if (empty($event['ok'])) {
                return $event;
            }
            $row = $event['event'];

            if ((string) ($row['fastbound_acquisition_item_id'] ?? '') !== '') {
                return [
                    'ok' => true,
                    'code' => 'already_acquired',
                    'message' => __('This item is already acquired in FastBound.', 'ffl-hub'),
                    'event' => $this->public_event((array) $row),
                ];
            }

            $manual = $this->manual_acquisition_fields($input, $row);
            if (empty($manual['ok'])) {
                return $manual;
            }

            $source = $this->source_contact((string) ($row['dist_id'] ?? ''), (string) ($input['source_contact_id'] ?? ''));
            if (empty($source['ok'])) {
                return $source;
            }

            $payload = $this->acquisition_payload($row, $manual['fields'], $source['contact']);
            $response = $this->client->create_and_commit_acquisition($payload);
            if (empty($response['ok']) || (string) ($response['item_id'] ?? '') === '') {
                $message = (string) ($response['message'] ?? __('FastBound acquisition failed.', 'ffl-hub'));
                if (!empty($response['ok'])) {
                    $message = __('FastBound acquisition did not return an item ID, so the scan cannot be safely disposed later.', 'ffl-hub');
                }
                $this->events->update_fastbound_fields($event_id, array_merge(
                    $this->manual_update_fields($manual['fields']),
                    [
                        'fastbound_status' => 'acquire_failed',
                        'fastbound_error' => $message,
                    ]
                ));

                return $this->error('fastbound_acquire_failed', $message);
            }

            $this->events->update_fastbound_fields($event_id, array_merge(
                $this->manual_update_fields($manual['fields']),
                [
                    'fastbound_acquisition_id' => (string) ($response['acquisition_id'] ?? ''),
                    'fastbound_acquisition_item_id' => (string) ($response['item_id'] ?? ''),
                    'fastbound_status' => 'acquired',
                    'fastbound_error' => '',
                    'fastbound_acquired_at' => current_time('mysql', true),
                ]
            ));

            return [
                'ok' => true,
                'code' => 'acquired',
                'message' => __('FastBound acquisition committed.', 'ffl-hub'),
                'event' => $this->public_event((array) $this->events->find_by_id($event_id)),
            ];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function dispose_event(int $event_id, array $input): array
    {
        global $wpdb;

        $lock_name = 'fflhub_fastbound_dispose_' . $event_id;
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock_name));
        if ($locked !== 1) {
            return $this->error('lock_timeout', __('FastBound disposition is already running for this scan event.', 'ffl-hub'));
        }

        try {
            $event = $this->event_for_fastbound($event_id);
            if (empty($event['ok'])) {
                return $event;
            }
            $row = $event['event'];

            if ((string) ($row['fastbound_disposition_id'] ?? '') !== '' || (string) ($row['fastbound_status'] ?? '') === 'disposed') {
                return [
                    'ok' => true,
                    'code' => 'already_disposed',
                    'message' => __('This item is already disposed in FastBound.', 'ffl-hub'),
                    'event' => $this->public_event((array) $row),
                ];
            }

            $item_id = trim((string) ($row['fastbound_acquisition_item_id'] ?? ''));
            if ($item_id === '') {
                return $this->error('not_acquired', __('Acquire this serial number in FastBound before disposing it.', 'ffl-hub'));
            }

            $ffl_number = FFLRowMapper::normalize_ffl_number((string) ($input['destination_ffl_number'] ?? ''));
            if ($ffl_number === '') {
                $ffl_number = $this->order_ffl_number($row);
            }
            if ($ffl_number === '') {
                return $this->error('missing_destination_ffl', __('Enter the destination FFL number before disposition.', 'ffl-hub'));
            }

            $contact = $this->client->create_or_find_contact_by_ffl($ffl_number);
            if (empty($contact['ok']) || (string) ($contact['contact_id'] ?? '') === '') {
                $message = (string) ($contact['message'] ?? __('FastBound destination contact could not be created.', 'ffl-hub'));
                $this->events->update_fastbound_fields($event_id, [
                    'fastbound_status' => 'dispose_failed',
                    'fastbound_error' => $message,
                ]);

                return $this->error('fastbound_contact_failed', $message);
            }

            $payload = $this->disposition_payload($row, $item_id, (string) $contact['contact_id']);
            $response = $this->client->create_and_commit_disposition($payload);
            if (empty($response['ok'])) {
                $message = (string) ($response['message'] ?? __('FastBound disposition failed.', 'ffl-hub'));
                $this->events->update_fastbound_fields($event_id, [
                    'fastbound_status' => 'dispose_failed',
                    'fastbound_error' => $message,
                ]);

                return $this->error('fastbound_dispose_failed', $message);
            }

            $this->events->update_fastbound_fields($event_id, [
                'fastbound_disposition_id' => (string) ($response['disposition_id'] ?? ''),
                'fastbound_disposition_contact_id' => (string) $contact['contact_id'],
                'fastbound_status' => 'disposed',
                'fastbound_error' => '',
                'fastbound_disposed_at' => current_time('mysql', true),
            ]);

            return [
                'ok' => true,
                'code' => 'disposed',
                'message' => __('FastBound disposition committed.', 'ffl-hub'),
                'event' => $this->public_event((array) $this->events->find_by_id($event_id)),
            ];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function event_for_fastbound(int $event_id): array
    {
        $ready = $this->client->readiness(true);
        if (!$ready['ok']) {
            return $this->error('fastbound_not_ready', $ready['message']);
        }

        $row = $this->events->find_by_id($event_id);
        if (!is_array($row)) {
            return $this->error('event_not_found', __('Receiving scan event was not found.', 'ffl-hub'));
        }
        if ((string) ($row['result'] ?? '') !== 'accepted') {
            return $this->error('event_not_accepted', __('Only accepted receiving scan events can be sent to FastBound.', 'ffl-hub'));
        }
        if ((string) ($row['serial_number'] ?? '') === '') {
            return $this->error('missing_serial', __('A serial number is required for FastBound.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'event' => $row,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function manual_acquisition_fields(array $input, array $event): array
    {
        $fields = [
            'manufacturer' => $this->text($input['manufacturer'] ?? '', 100),
            'model' => $this->text($input['model'] ?? '', 100),
            'caliber' => $this->text($input['caliber'] ?? '', 100),
            'type' => $this->text($input['firearm_type'] ?? '', 100),
        ];

        if ($fields['model'] === '') {
            $fields['model'] = $this->product_name($event);
        }

        $missing = [];
        $labels = [
            'manufacturer' => __('manufacturer', 'ffl-hub'),
            'model' => __('model', 'ffl-hub'),
            'caliber' => __('caliber', 'ffl-hub'),
            'type' => __('firearm type', 'ffl-hub'),
        ];
        foreach ($fields as $key => $value) {
            if ($value === '') {
                $missing[] = (string) ($labels[$key] ?? $key);
            }
        }

        if (!empty($missing)) {
            return $this->error(
                'missing_fastbound_item_fields',
                sprintf(
                    /* translators: %s: comma-separated field names. */
                    __('FastBound requires these acquisition fields: %s.', 'ffl-hub'),
                    implode(', ', $missing)
                )
            );
        }

        return [
            'ok' => true,
            'fields' => $fields,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function source_contact(string $distributor_id, string $selected_contact_id): array
    {
        $contacts = Options::get_fastbound_contacts_for_distributor($distributor_id);
        if (empty($contacts)) {
            return $this->error('missing_source_contact', __('No enabled FastBound source contact is mapped for this distributor.', 'ffl-hub'));
        }

        if ($selected_contact_id !== '') {
            foreach ($contacts as $contact) {
                if ((string) ($contact['fastbound_contact_id'] ?? '') === $selected_contact_id) {
                    return $this->validate_source_contact($contact);
                }
            }

            return $this->error('invalid_source_contact', __('Selected FastBound source contact is not mapped to this distributor.', 'ffl-hub'));
        }

        if (count($contacts) > 1) {
            return $this->error('source_contact_required', __('Select which FastBound source contact/location this distributor shipment came from.', 'ffl-hub'));
        }

        return $this->validate_source_contact($contacts[0]);
    }

    /**
     * @param array<string,mixed> $contact
     * @return array<string,mixed>
     */
    private function validate_source_contact(array $contact): array
    {
        $contact_id = trim((string) ($contact['fastbound_contact_id'] ?? ''));
        $external_id = trim((string) ($contact['fastbound_contact_external_id'] ?? ''));
        if ($contact_id === '' && $external_id === '') {
            return $this->error('source_contact_missing_id', __('Mapped FastBound source contact is missing both contact ID and external ID.', 'ffl-hub'));
        }

        return [
            'ok' => true,
            'contact' => $contact,
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,string> $fields
     * @param array<string,mixed> $source_contact
     * @return array<string,mixed>
     */
    private function acquisition_payload(array $event, array $fields, array $source_contact): array
    {
        $payload = [
            'externalId' => 'fflhub-receiving-event-' . (int) ($event['id'] ?? 0),
            'date' => $this->fastbound_business_date(),
            'type' => self::ACQUISITION_TYPE,
            'purchaseOrderNumber' => $this->text($event['merchant_po'] ?? '', 100),
            'shipmentTrackingNumber' => $this->text($event['tracking_number'] ?? '', 100),
            'note' => $this->note($event, 'Acquired from FFLHub receiving event'),
            'items' => [
                [
                    'externalId' => 'fflhub-receiving-event-' . (int) ($event['id'] ?? 0),
                    'manufacturer' => $fields['manufacturer'],
                    'model' => $fields['model'],
                    'serial' => $this->text($event['serial_number'] ?? '', 100),
                    'caliber' => $fields['caliber'],
                    'type' => $fields['type'],
                    'upc' => $this->text($event['upc'] ?? '', 50),
                    'sku' => $this->product_sku($event),
                    'note' => $this->note($event, 'Received through FFLHub'),
                ],
            ],
        ];

        $contact_id = trim((string) ($source_contact['fastbound_contact_id'] ?? ''));
        $external_id = trim((string) ($source_contact['fastbound_contact_external_id'] ?? ''));
        if ($contact_id !== '') {
            $payload['contactId'] = $contact_id;
        } elseif ($external_id !== '') {
            $payload['contactExternalId'] = $external_id;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function disposition_payload(array $event, string $item_id, string $contact_id): array
    {
        return [
            'requestType' => self::DISPOSITION_REQUEST_TYPE,
            'contactId' => $contact_id,
            'externalId' => 'fflhub-disposition-event-' . (int) ($event['id'] ?? 0),
            'date' => $this->fastbound_business_date(),
            'type' => self::DISPOSITION_TYPE,
            'purchaseOrderNumber' => $this->text($event['merchant_po'] ?? '', 100),
            'shipmentTrackingNumber' => $this->text($event['tracking_number'] ?? '', 100),
            'note' => $this->note($event, 'Disposed from FFLHub receiving event'),
            'items' => [
                [
                    'id' => $item_id,
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,string>
     */
    private function manual_update_fields(array $fields): array
    {
        return [
            'fastbound_manufacturer' => (string) ($fields['manufacturer'] ?? ''),
            'fastbound_model' => (string) ($fields['model'] ?? ''),
            'fastbound_caliber' => (string) ($fields['caliber'] ?? ''),
            'fastbound_firearm_type' => (string) ($fields['type'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function public_event(array $event): array
    {
        return [
            'id' => (int) ($event['id'] ?? 0),
            'upc' => (string) ($event['upc'] ?? ''),
            'serial_number' => (string) ($event['serial_number'] ?? ''),
            'fastbound_status' => (string) ($event['fastbound_status'] ?? ''),
            'fastbound_acquisition_id' => (string) ($event['fastbound_acquisition_id'] ?? ''),
            'fastbound_acquisition_item_id' => (string) ($event['fastbound_acquisition_item_id'] ?? ''),
            'fastbound_disposition_id' => (string) ($event['fastbound_disposition_id'] ?? ''),
            'fastbound_disposition_contact_id' => (string) ($event['fastbound_disposition_contact_id'] ?? ''),
            'fastbound_error' => (string) ($event['fastbound_error'] ?? ''),
            'fastbound_manufacturer' => (string) ($event['fastbound_manufacturer'] ?? ''),
            'fastbound_model' => (string) ($event['fastbound_model'] ?? ''),
            'fastbound_caliber' => (string) ($event['fastbound_caliber'] ?? ''),
            'fastbound_firearm_type' => (string) ($event['fastbound_firearm_type'] ?? ''),
            'fastbound_acquired_at' => (string) ($event['fastbound_acquired_at'] ?? ''),
            'fastbound_disposed_at' => (string) ($event['fastbound_disposed_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $event
     */
    private function order_ffl_number(array $event): string
    {
        $order_id = (int) ($event['order_id'] ?? 0);
        if ($order_id <= 0) {
            return '';
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return '';
        }

        return FFLRowMapper::normalize_ffl_number((string) $order->get_meta('fflhub_receiving_ffl_number', true));
    }

    /**
     * @param array<string,mixed> $event
     */
    private function product_name(array $event): string
    {
        $product_id = (int) ($event['product_id'] ?? 0);
        $product = $product_id > 0 ? wc_get_product($product_id) : null;

        return $product instanceof WC_Product ? $this->text($product->get_name(), 100) : '';
    }

    /**
     * @param array<string,mixed> $event
     */
    private function product_sku(array $event): string
    {
        $product_id = (int) ($event['product_id'] ?? 0);
        $product = $product_id > 0 ? wc_get_product($product_id) : null;

        return $product instanceof WC_Product ? $this->text($product->get_sku(), 50) : '';
    }

    /**
     * @param array<string,mixed> $event
     */
    private function note(array $event, string $prefix): string
    {
        $parts = [
            $prefix,
            'event #' . (int) ($event['id'] ?? 0),
            'order #' . (int) ($event['order_id'] ?? 0),
        ];

        return $this->text(implode(' ', $parts), 1000);
    }

    /**
     * FastBound validates acquisition/disposition dates against the account's
     * business day. Sending UTC "now" can drift into tomorrow for a US account,
     * so receiving sends the local WordPress date at midnight.
     */
    private function fastbound_business_date(): string
    {
        return current_time('Y-m-d') . 'T00:00:00';
    }

    /**
     * @param mixed $value
     */
    private function text($value, int $max): string
    {
        $text = sanitize_text_field((string) ($value ?? ''));
        if ($max > 0 && strlen($text) > $max) {
            $text = substr($text, 0, $max);
        }

        return $text;
    }

    /**
     * @return array<string,mixed>
     */
    private function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ];
    }
}
