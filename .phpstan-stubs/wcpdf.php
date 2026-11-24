<?php

function wcpdf_get_document(string $documentType, object $order, bool $init = false): object|false
{
    return false;
}

function wcpdf_get_document_file(object $document, string $outputFormat = 'pdf', string $errorHandling = 'exception'): string|false
{
    return false;
}

function WPO_WCPDF_Pro(): object
{
    return new \stdClass();
}
