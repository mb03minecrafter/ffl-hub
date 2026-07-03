<?php

namespace FFLHub\Distributor\Services\BillHicks;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

/**
 * Builds Bill Hicks simplified EDI 850 order files.
 *
 * This class is intentionally file-builder only. It does not upload to FTP or
 * mark an order placed; that keeps the risky transport step separate from the
 * deterministic "what would we send?" step.
 */
final class BillHicksEdiOrderFileBuilder
{
    private DistributorTableInterface $product_table;

    public function __construct(DistributorTableInterface $product_table)
    {
        $this->product_table = $product_table;
    }

    /**
     * Build the tab-delimited 850 content for one Bill Hicks ship-to lane.
     *
     * Bill Hicks requires one header per file, so callers should pass only the
     * lines that share the same destination. The base distributor order
     * orchestration already splits FFL and non-FFL lanes for us.
     *
     * @param DistributorOrderLine[] $lines
     * @return array{
     *   ok:bool,
     *   errors:string[],
     *   content:string,
     *   filename:string,
     *   po_number:string,
     *   line_count:int,
     *   destination:string,
     *   ship_method:string
     * }
     */
    public function build_order_file(DistributorOrderRequest $request, string $lane, array $lines): array
    {
        $lane = strtolower(trim($lane));
        $dealer_fulfilled = ($lane === 'dealer_fulfilled');
        $lines = $this->valid_lines($lines);
        $errors = [];

        $customer_number = $this->clean_field(BillHicksFtpCredentials::edi_customer_number());
        $po_number = $this->sanitize_po((string) $request->merchant_order_id);
        $ship_method = '';

        if ($customer_number === '') {
            $errors[] = 'Missing Bill Hicks EDI customer number.';
        }

        if ($po_number === '') {
            $errors[] = 'Missing merchant PO/order id.';
        }

        if (empty($lines)) {
            $errors[] = 'No valid Bill Hicks EDI order lines were provided.';
        }

        $line_flags = $this->line_ffl_flags($lines);
        if (!$dealer_fulfilled && count($line_flags) > 1) {
            $errors[] = 'Bill Hicks EDI file builder received mixed FFL and non-FFL lines. Split these into separate files.';
        }

        // Dealer-batch files ship to our configured dealer address, so they do
        // not need a customer transfer FFL on the 850 header. Direct-ship FFL
        // lanes still require the receiving FFL destination and FFL number.
        $ffl_required = !$dealer_fulfilled && isset($line_flags[0]) && $line_flags[0] === 1;
        $ship_to = $this->resolve_ship_to($request, $ffl_required, $dealer_fulfilled);
        if (!$ship_to instanceof DistributorShipTo) {
            if ($dealer_fulfilled) {
                $errors[] = 'Missing dealer ship-to destination for Bill Hicks dealer batch order file.';
            } elseif ($ffl_required) {
                $errors[] = 'Missing FFL ship-to destination for Bill Hicks FFL order file.';
            } else {
                $errors[] = 'Missing customer ship-to destination for Bill Hicks non-FFL order file.';
            }
        }

        $ffl_number = (!$dealer_fulfilled && $ffl_required) ? trim((string) $request->receiving_ffl_number) : '';
        if ($ffl_required && $ffl_number === '') {
            $errors[] = 'Missing receiving FFL number for Bill Hicks FFL order file.';
        }

        $rows_by_upc = $this->bill_hicks_rows_for_lines($lines);
        $line_rows = [];
        $line_ship_methods = [];
        foreach ($lines as $line) {
            $upc = trim((string) $line->upc);
            $row = $rows_by_upc[$upc] ?? null;

            if (!is_array($row)) {
                $errors[] = sprintf('Bill Hicks product row not found for UPC %s.', $upc);
                continue;
            }

            $item_number = $this->clean_field((string) ($row['bill_hicks_item_number'] ?? ''));
            $description = $this->clean_field((string) ($row['product_name'] ?? ''));
            $price = $this->format_money($row['distributor_price'] ?? '');

            if ($item_number === '') {
                $errors[] = sprintf('Bill Hicks item number is missing for UPC %s.', $upc);
            }

            if ($description === '') {
                $errors[] = sprintf('Bill Hicks product name is missing for UPC %s.', $upc);
            }

            if ($price === '') {
                $errors[] = sprintf('Bill Hicks distributor price is missing for UPC %s.', $upc);
            }

            $line_ship_method = $this->ship_method_for_product_row($row);
            if ($line_ship_method !== '') {
                $line_ship_methods[$line_ship_method] = $line_ship_method;
            }

            $line_rows[] = [
                'L',
                $item_number,
                $description,
                (string) max(1, (int) $line->quantity),
                $price,
            ];
        }

        $ship_method = $this->ship_method_for_file($line_ship_methods);
        if ($ship_method === '' && empty($errors)) {
            $errors[] = 'Unable to determine Bill Hicks EDI ship method from product rows.';
        }

        if (!empty($errors) || !$ship_to instanceof DistributorShipTo) {
            return $this->result(false, $errors, '', '', $po_number, count($line_rows), '', $ship_method);
        }

        $destination_name = $this->destination_name($ship_to);
        $notes = $this->build_notes($request, $ship_to);

        $records = [];
        $records[] = [
            'HL',
            'Customer #',
            'Ship to#',
            'Ship to Name1',
            'Address 1',
            'Address 2',
            'city',
            'state',
            'zip',
            'cust po',
            'ship method',
            'notes',
            'FFL #',
        ];
        $records[] = [
            'H',
            $customer_number,
            '',
            $destination_name,
            $ship_to->address1,
            $ship_to->address2,
            $ship_to->city,
            $ship_to->state,
            $ship_to->zip,
            $po_number,
            $ship_method,
            $notes,
            $ffl_number,
            'END',
        ];
        $records[] = ['LL', 'Item', 'Description', 'Qty', 'Price'];
        foreach ($line_rows as $row) {
            $records[] = $row;
        }

        $content = $this->render_tab_delimited($records);
        $filename = $this->build_filename($po_number, $lane);

        return $this->result(true, [], $content, $filename, $po_number, count($line_rows), $destination_name, $ship_method);
    }

    /**
     * Write already-built content to disk.
     *
     * FTP upload should happen elsewhere; this helper only creates the local
     * file that the uploader can transmit.
     *
     * @return array{ok:bool,path:string,error:string}
     */
    public function write_content_to_file(string $content, string $directory, string $filename): array
    {
        $directory = rtrim(trim($directory), "/\\");
        $filename = trim($filename);

        if ($content === '' || $directory === '' || $filename === '') {
            return ['ok' => false, 'path' => '', 'error' => 'Missing content, directory, or filename.'];
        }

        if (function_exists('wp_mkdir_p') && !wp_mkdir_p($directory)) {
            return ['ok' => false, 'path' => '', 'error' => 'Unable to create local Bill Hicks EDI directory.'];
        }

        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $bytes = file_put_contents($path, $content, LOCK_EX);
        if ($bytes === false) {
            return ['ok' => false, 'path' => $path, 'error' => 'Unable to write local Bill Hicks EDI file.'];
        }

        return ['ok' => true, 'path' => $path, 'error' => ''];
    }

    /**
     * @param array<int,mixed> $lines
     * @return DistributorOrderLine[]
     */
    private function valid_lines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($line instanceof DistributorOrderLine && trim($line->upc) !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param DistributorOrderLine[] $lines
     * @return int[]
     */
    private function line_ffl_flags(array $lines): array
    {
        $flags = [];
        foreach ($lines as $line) {
            $flags[$line->ffl_required ? 1 : 0] = $line->ffl_required ? 1 : 0;
        }

        return array_values($flags);
    }

    private function resolve_ship_to(DistributorOrderRequest $request, bool $ffl_required, bool $dealer_fulfilled): ?DistributorShipTo
    {
        if ($dealer_fulfilled) {
            return $request->ship_to_customer instanceof DistributorShipTo ? $request->ship_to_customer : null;
        }

        if ($ffl_required) {
            return $request->ship_to_ffl instanceof DistributorShipTo ? $request->ship_to_ffl : null;
        }

        return $request->ship_to_customer;
    }

    /**
     * @param DistributorOrderLine[] $lines
     * @return array<string,array<string,mixed>>
     */
    private function bill_hicks_rows_for_lines(array $lines): array
    {
        $upcs = [];
        foreach ($lines as $line) {
            $upc = trim((string) $line->upc);
            if ($upc !== '') {
                $upcs[$upc] = $upc;
            }
        }

        return $this->product_table->get_rows_by_upcs(array_values($upcs));
    }

    private function destination_name(DistributorShipTo $ship_to): string
    {
        $company = $this->clean_field($ship_to->company);
        if ($company !== '') {
            return $company;
        }

        return $this->clean_field($ship_to->name);
    }

    private function build_notes(DistributorOrderRequest $request, DistributorShipTo $ship_to): string
    {
        $parts = [];

        $name = $this->clean_field($ship_to->name);
        $phone = $this->clean_field($ship_to->phone);
        if ($name !== '' || $phone !== '') {
            $label = trim($name . ($phone !== '' ? ' #' . $phone : ''));
            if ($label !== '') {
                $parts[] = '(' . $label . ')';
            }
        }

        $request_notes = $this->clean_field((string) $request->notes);
        if ($request_notes !== '') {
            $parts[] = $request_notes;
        }

        return $this->clean_field(implode(' ', $parts));
    }

    /**
     * @param array<int,array<int,string>> $records
     */
    private function render_tab_delimited(array $records): string
    {
        $lines = [];
        foreach ($records as $record) {
            $fields = [];
            foreach ($record as $field) {
                $fields[] = $this->clean_field((string) $field);
            }
            $lines[] = implode("\t", $fields);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function clean_field(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\t\r\n]+/', ' ', $value);
        $value = is_string($value) ? $value : '';
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Keep PO values file-safe and BHC-friendly.
     */
    private function sanitize_po(string $po): string
    {
        $po = $this->clean_field($po);
        $po = preg_replace('/[^A-Za-z0-9]+/', '', $po);
        $po = is_string($po) ? trim($po) : '';

        return $po !== '' ? substr($po, 0, 40) : '';
    }

    private function format_money($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }

        return number_format((float) $raw, 2, '.', '');
    }

    /**
     * Bill Hicks wants the shipping method on the file header, not on each line.
     * The catalog category code is the most reliable source we have:
     * - pistols/revolvers: UPSH
     * - rifles/shotguns/SBR/SBS/barreled actions: UPS
     * - accessories, ammo, magazines, suppressors, and other non-long-gun rows: UPSR
     *
     * @param array<string,mixed> $row
     */
    private function ship_method_for_product_row(array $row): string
    {
        $category_code = strtoupper(trim((string) ($row['category'] ?? '')));
        $item_type = strtoupper(trim((string) ($row['item_type'] ?? '')));

        if (in_array($category_code, ['H602', 'H603'], true) || $this->contains_any($item_type, ['PISTOL', 'REVOLVER', 'HANDGUN'])) {
            return 'UPSH';
        }

        if (in_array($category_code, ['H600', 'H601', 'H605', 'H607', 'H608'], true)) {
            return 'UPS';
        }

        if ($this->contains_any($item_type, ['RIFLE', 'SHOTGUN', 'LONG GUN', 'LONGGUN', 'BARRELED ACTION'])) {
            return 'UPS';
        }

        return 'UPSR';
    }

    /**
     * Bill Hicks has one ship-method field on the 850 header. If a dealer
     * batch mixes product types, use the most restrictive method needed by
     * any line so the full file remains orderable.
     *
     * @param array<string,string> $methods
     */
    private function ship_method_for_file(array $methods): string
    {
        if (isset($methods['UPSH'])) {
            return 'UPSH';
        }

        if (isset($methods['UPS'])) {
            return 'UPS';
        }

        if (isset($methods['UPSR'])) {
            return 'UPSR';
        }

        return '';
    }

    /**
     * @param string[] $needles
     */
    private function contains_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function build_filename(string $po_number, string $lane): string
    {
        $suffix = gmdate('Ymd_His');
        $lane = preg_replace('/[^a-z0-9_\-]+/i', '-', strtolower(trim($lane)));
        $lane = is_string($lane) && $lane !== '' ? $lane : 'order';

        return sprintf('BHC_%s_%s_%s.txt', $this->sanitize_po($po_number), $lane, $suffix);
    }

    /**
     * @param string[] $errors
     * @return array{
     *   ok:bool,
     *   errors:string[],
     *   content:string,
     *   filename:string,
     *   po_number:string,
     *   line_count:int,
     *   destination:string,
     *   ship_method:string
     * }
     */
    private function result(
        bool $ok,
        array $errors,
        string $content,
        string $filename,
        string $po_number,
        int $line_count,
        string $destination,
        string $ship_method
    ): array {
        return [
            'ok' => $ok,
            'errors' => array_values($errors),
            'content' => $content,
            'filename' => $filename,
            'po_number' => $po_number,
            'line_count' => $line_count,
            'destination' => $destination,
            'ship_method' => $ship_method,
        ];
    }
}
