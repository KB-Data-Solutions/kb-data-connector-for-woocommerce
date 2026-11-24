<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use Mockery;
use MinoxWooCommerce\Sync\InvoicePdfResolver;
use MinoxWooCommerce\Tests\Fakes\FakeWcpdf;
use PHPUnit\Framework\TestCase;
use WC_Order;

require_once __DIR__.'/../../Stubs/wcpdf-functions.php';

final class InvoicePdfResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeWcpdf::reset();

        Mockery::close();

        parent::tearDown();
    }

    public function test_resolve_passes_the_document_object_from_get_document_into_get_document_file(): void
    {
        $order = $this->makeOrder();
        $fakeDocument = new class
        {
            public string $marker = 'the-real-document-object';
        };

        $receivedDocumentTypeAndOrder = null;
        FakeWcpdf::$getDocument = function (string $documentType, WC_Order $order, bool $init) use ($fakeDocument, &$receivedDocumentTypeAndOrder) {
            $receivedDocumentTypeAndOrder = [$documentType, $order, $init];

            return $fakeDocument;
        };

        $receivedDocument = null;
        $tmpFile = tempnam(sys_get_temp_dir(), 'wcpdf-test-');
        file_put_contents($tmpFile, '%PDF-1.4 fake contents');

        FakeWcpdf::$getDocumentFile = function (object $document, string $outputFormat) use (&$receivedDocument, $tmpFile) {
            $receivedDocument = $document;

            return $tmpFile;
        };

        $result = (new InvoicePdfResolver())->resolve($order);

        unlink($tmpFile);

        $this->assertSame(['invoice', $order, true], $receivedDocumentTypeAndOrder);
        $this->assertSame($fakeDocument, $receivedDocument, 'wcpdf_get_document_file() must receive the document object wcpdf_get_document() returned, not the order.');
        $this->assertNotNull($result);
        $this->assertSame('application/pdf', $result->mediaType);
        $this->assertSame('%PDF-1.4 fake contents', $result->contents);
    }

    public function test_resolve_returns_null_when_get_document_finds_no_document(): void
    {
        FakeWcpdf::$getDocument = fn (): bool => false;

        $result = (new InvoicePdfResolver())->resolve($this->makeOrder());

        $this->assertNull($result);
    }

    public function test_resolve_returns_null_when_get_document_file_cannot_produce_a_readable_path(): void
    {
        FakeWcpdf::$getDocument = fn (): object => new class {};
        FakeWcpdf::$getDocumentFile = fn (): bool => false;

        $result = (new InvoicePdfResolver())->resolve($this->makeOrder());

        $this->assertNull($result);
    }

    public function test_resolve_never_throws_even_if_the_plugin_functions_do(): void
    {
        FakeWcpdf::$getDocument = function (): void {
            throw new \RuntimeException('simulated plugin failure');
        };

        $result = (new InvoicePdfResolver())->resolve($this->makeOrder());

        $this->assertNull($result);
    }

    private function makeOrder(): WC_Order
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_order_number')->andReturn('1042');

        return $order;
    }
}
