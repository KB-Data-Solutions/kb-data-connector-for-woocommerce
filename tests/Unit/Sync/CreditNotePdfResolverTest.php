<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use Mockery;
use MinoxWooCommerce\Sync\InvoicePdfResolver;
use MinoxWooCommerce\Tests\Fakes\FakeWcpdf;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Refund;

require_once __DIR__.'/../../Stubs/wcpdf-functions.php';
require_once __DIR__.'/../../Stubs/wcpdf-pro-functions.php';

final class CreditNotePdfResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeWcpdf::reset();
        Mockery::close();
        parent::tearDown();
    }

    public function test_credit_note_is_requested_for_the_refund_and_named_after_order_and_refund(): void
    {
        $refund = Mockery::mock(WC_Order_Refund::class);
        $refund->allows('get_id')->andReturn(77);
        $document = new \stdClass();
        $received = null;
        FakeWcpdf::$getDocument = function (string $type, object $subject, bool $init) use ($document, &$received) {
            $received = [$type, $subject, $init];

            return $document;
        };
        $file = tempnam(sys_get_temp_dir(), 'wcpdf-cn-');
        file_put_contents($file, '%PDF-1.4 credit note');
        FakeWcpdf::$getDocumentFile = fn (object $doc): string => $doc === $document ? $file : '';

        $upload = (new InvoicePdfResolver())->resolveCreditNote($this->order(), $refund);

        self::assertTrue(InvoicePdfResolver::isCreditNoteAvailable());
        self::assertSame(['credit-note', $refund, true], $received);
        self::assertNotNull($upload);
        self::assertSame('creditfactuur-1001-77.pdf', $upload->filename);
        self::assertSame('%PDF-1.4 credit note', $upload->contents);
        unlink($file);
    }

    public function test_no_credit_note_document_means_no_pdf_and_no_exception(): void
    {
        $refund = Mockery::mock(WC_Order_Refund::class);
        $refund->allows('get_id')->andReturn(77);
        FakeWcpdf::$getDocument = fn (): bool => false;

        self::assertNull((new InvoicePdfResolver())->resolveCreditNote($this->order(), $refund));

        FakeWcpdf::$getDocument = function (): never {
            throw new \RuntimeException('plugin exploded');
        };

        self::assertNull((new InvoicePdfResolver())->resolveCreditNote($this->order(), $refund));
    }

    private function order(): WC_Order
    {
        $order = Mockery::mock(WC_Order::class);
        $order->allows('get_order_number')->andReturn('1001');

        return $order;
    }
}
