<?php
declare(strict_types=1);

namespace FFLHub\Shipping\PrintNode;

use PrintNode\Client;
use PrintNode\Credentials\ApiKey;
use PrintNode\Entity\PrintJob;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the official PrintNode PHP client.
 *
 * FFL Hub keeps direct use of the third-party objects here so admin pages and
 * WMS services can work with plain arrays and WP_Error results.
 */
final class PrintNodeClient
{
    private string $api_key;
    private ?Client $client = null;

    public function __construct(?string $api_key = null)
    {
        $this->api_key = trim((string) ($api_key ?? PrintNodeOptions::api_key()));
    }

    /**
     * @return array<int,array{id:int,name:string,description:string,state:string,computer_id:int,computer_name:string,is_default:bool}>|WP_Error
     */
    public function printers()
    {
        $client = $this->client();
        if (is_wp_error($client)) {
            return $client;
        }

        try {
            $printers = $client->viewPrinters();
        } catch (\Throwable $e) {
            return new WP_Error('fflhub_printnode_printers_failed', 'Could not load PrintNode printers: ' . $e->getMessage());
        }

        $out = [];
        foreach (is_array($printers) ? $printers : [] as $printer) {
            try {
                $computer = $printer->computer ?? null;
                $out[] = [
                    'id' => (int) ($printer->id ?? 0),
                    'name' => (string) ($printer->name ?? ''),
                    'description' => (string) ($printer->description ?? ''),
                    'state' => (string) ($printer->state ?? ''),
                    'computer_id' => is_object($computer) ? (int) ($computer->id ?? 0) : 0,
                    'computer_name' => is_object($computer) ? (string) ($computer->name ?? '') : '',
                    'is_default' => !empty($printer->default),
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }

        return array_values(array_filter($out, static fn(array $printer): bool => (int) ($printer['id'] ?? 0) > 0));
    }

    /**
     * @param array<string,mixed> $args
     * @return array{id:string,title:string}|WP_Error
     */
    public function print_pdf(array $args)
    {
        $client = $this->client();
        if (is_wp_error($client)) {
            return $client;
        }

        $printer_id = max(0, (int) ($args['printer_id'] ?? PrintNodeOptions::default_printer_id()));
        $body = (string) ($args['body'] ?? '');
        $title = sanitize_text_field((string) ($args['title'] ?? 'FFL Hub Print Job'));
        if ($printer_id <= 0) {
            return new WP_Error('fflhub_printnode_missing_printer', 'Choose a PrintNode printer first.');
        }

        if (trim($body) === '' || strpos(ltrim($body), '%PDF') !== 0) {
            return new WP_Error('fflhub_printnode_invalid_pdf', 'PrintNode job content did not look like a PDF.');
        }

        try {
            $print_job = new PrintJob($client);
            $print_job->title = $title;
            $print_job->source = 'FFL Hub';
            $print_job->printer = (string) $printer_id;
            $print_job->contentType = 'pdf_base64';
            $print_job->content = base64_encode($body);
            $print_job->qty = PrintNodeOptions::copies();
            $print_job->expireAfter = PrintNodeOptions::expire_after_seconds();

            $options = [];
            if (PrintNodeOptions::fit_to_page()) {
                $options['fit_to_page'] = true;
            }
            if (!empty($options)) {
                $print_job->options = $options;
            }

            $id = (string) $client->createPrintJob($print_job);
        } catch (\Throwable $e) {
            return new WP_Error('fflhub_printnode_print_failed', 'PrintNode rejected the print job: ' . $e->getMessage());
        }

        if ($id === '') {
            return new WP_Error('fflhub_printnode_empty_print_id', 'PrintNode did not return a print job id.');
        }

        return [
            'id' => $id,
            'title' => $title,
        ];
    }

    /**
     * @return Client|WP_Error
     */
    private function client()
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        if ($this->api_key === '') {
            return new WP_Error('fflhub_printnode_missing_api_key', 'PrintNode API key is not configured.');
        }

        try {
            $this->client = new Client(new ApiKey($this->api_key));
        } catch (\Throwable $e) {
            return new WP_Error('fflhub_printnode_invalid_api_key', 'PrintNode API key could not be used: ' . $e->getMessage());
        }

        return $this->client;
    }
}
