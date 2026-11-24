<?php

declare(strict_types=1);

use MinoxWooCommerce\Tests\Fakes\FakeWcpdf;

if (!function_exists('wcpdf_get_document')) {
    function wcpdf_get_document(string $documentType, object $order, bool $init = false)
    {
        return FakeWcpdf::$getDocument !== null
            ? (FakeWcpdf::$getDocument)($documentType, $order, $init)
            : false;
    }
}

if (!function_exists('wcpdf_get_document_file')) {
    function wcpdf_get_document_file(object $document, string $outputFormat = 'pdf', string $errorHandling = 'exception')
    {
        return FakeWcpdf::$getDocumentFile !== null
            ? (FakeWcpdf::$getDocumentFile)($document, $outputFormat, $errorHandling)
            : false;
    }
}
