<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use KBDataSolutions\Sdk\Invoice\Request\DocumentUpload;
use Throwable;
use WC_Order;
use WC_Order_Refund;

final class InvoicePdfResolver
{
    public const string RECOMMENDED_PLUGIN_SLUG = 'woocommerce-pdf-invoices-packing-slips';

    public const string PRO_PLUGIN_URL = 'https://wpovernight.com/downloads/woocommerce-pdf-invoices-packing-slips-professional/';

    public static function isAvailable(): bool
    {
        return function_exists('wcpdf_get_document') && function_exists('wcpdf_get_document_file');
    }

    public static function isCreditNoteAvailable(): bool
    {
        return self::isAvailable() && function_exists('WPO_WCPDF_Pro');
    }

    public function resolveCreditNote(WC_Order $order, WC_Order_Refund $refund): ?DocumentUpload
    {
        if (!self::isCreditNoteAvailable()) {
            return null;
        }

        try {
            $document = wcpdf_get_document('credit-note', $refund, true);

            return $document === false
                ? null
                : $this->upload($document, 'creditfactuur-'.$order->get_order_number().'-'.$refund->get_id().'.pdf');
        } catch (Throwable) {
            return null;
        }
    }

    public function resolve(WC_Order $order): ?DocumentUpload
    {
        if (!self::isAvailable()) {
            return null;
        }

        try {
            $document = wcpdf_get_document('invoice', $order, true);

            return $document === false ? null : $this->upload($document, 'factuur-'.$order->get_order_number().'.pdf');
        } catch (Throwable) {
            return null;
        }
    }

    public function invoiceNumber(WC_Order $order, bool $create): ?string
    {
        return self::isAvailable() ? $this->documentNumber('invoice', $order, $create) : null;
    }

    public function creditNoteNumber(WC_Order_Refund $refund, bool $create): ?string
    {
        return self::isCreditNoteAvailable() ? $this->documentNumber('credit-note', $refund, $create) : null;
    }

    private function documentNumber(string $type, WC_Order|WC_Order_Refund $order, bool $create): ?string
    {
        try {
            $document = wcpdf_get_document($type, $order, $create);

            if (!is_object($document) || !method_exists($document, 'get_number')) {
                return null;
            }

            $number = $document->get_number();
            $formatted = is_object($number) && method_exists($number, 'get_formatted') ? $number->get_formatted() : $number;

            return is_scalar($formatted) && trim((string) $formatted) !== '' ? trim((string) $formatted) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function upload(object $document, string $filename): ?DocumentUpload
    {
        /** @var string|false $path */
        $path = wcpdf_get_document_file($document, 'pdf');

        if (!is_string($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return new DocumentUpload(filename: $filename, contents: $contents, mediaType: 'application/pdf');
    }
}
