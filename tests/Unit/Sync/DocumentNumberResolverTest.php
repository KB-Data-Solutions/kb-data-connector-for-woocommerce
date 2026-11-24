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

final class DocumentNumberResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeWcpdf::reset();
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_formatted_invoice_number_of_the_pdf_invoice_is_used(): void
    {
        $order = Mockery::mock(WC_Order::class);
        $received = null;
        FakeWcpdf::$getDocument = function (string $type, object $subject, bool $init) use (&$received): object {
            $received = [$type, $subject, $init];

            return new class {
                public function get_number(): object
                {
                    return new class {
                        public function get_formatted(): string
                        {
                            return ' 2026 1335 ';
                        }
                    };
                }
            };
        };

        self::assertSame('2026 1335', (new InvoicePdfResolver())->invoiceNumber($order, false));
        self::assertSame(['invoice', $order, false], $received);
    }

    public function test_a_credit_note_uses_its_own_number_and_a_missing_document_gives_none(): void
    {
        $refund = Mockery::mock(WC_Order_Refund::class);
        FakeWcpdf::$getDocument = fn (string $type): object => new class {
            public function get_number(): string
            {
                return 'CN-2026-12';
            }
        };

        self::assertSame('CN-2026-12', (new InvoicePdfResolver())->creditNoteNumber($refund, true));

        FakeWcpdf::$getDocument = fn (): bool => false;
        self::assertNull((new InvoicePdfResolver())->creditNoteNumber($refund, true));

        FakeWcpdf::$getDocument = function (): never {
            throw new \RuntimeException('plugin exploded');
        };
        self::assertNull((new InvoicePdfResolver())->invoiceNumber(Mockery::mock(WC_Order::class), true));
    }
}
