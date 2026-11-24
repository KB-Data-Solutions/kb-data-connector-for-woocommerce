<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use DateTimeImmutable;
use KBDataSolutions\Sdk\Debtor\DebtorType;
use KBDataSolutions\Sdk\Debtor\Request\CreateDebtorRequest;
use KBDataSolutions\Sdk\Invoice\Currency;
use KBDataSolutions\Sdk\Invoice\DecimalAmount;
use KBDataSolutions\Sdk\Invoice\Request\CreateInvoiceRequest;
use KBDataSolutions\Sdk\Invoice\Request\DocumentUpload;
use KBDataSolutions\Sdk\Invoice\Request\InvoiceLine;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;
use WC_Tax;

final class OrderMapper
{
    private const array ITEM_TYPES = ['line_item', 'shipping', 'fee'];

    public function __construct(private readonly VatNumberResolver $vatNumbers = new VatNumberResolver()) {}

    public function toInvoiceRequest(WC_Order $order, ?DocumentUpload $document): CreateInvoiceRequest
    {
        return new CreateInvoiceRequest(
            externalInvoiceId: 'wc-order-'.$order->get_id(),
            invoiceDate: new DateTimeImmutable($order->get_date_created()?->format('Y-m-d H:i:s') ?? 'now'),
            currency: Currency::Eur,
            destinationCountry: strtoupper($order->get_billing_country()),
            netAmount: $this->amount((float) $order->get_total() - (float) $order->get_total_tax()),
            vatAmount: $this->amount((float) $order->get_total_tax()),
            grossAmount: $this->amount((float) $order->get_total()),
            lines: $this->invoiceLines($order),
            debtor: $this->toDebtorRequest($order),
            externalOrderId: (string) $order->get_order_number(),
            document: $document,
            paymentMethod: $order->get_payment_method() ?: null,
        );
    }

    public function toRefundInvoiceRequest(WC_Order $order, WC_Order_Refund $refund, ?DocumentUpload $document): CreateInvoiceRequest
    {
        return new CreateInvoiceRequest(
            externalInvoiceId: 'wc-order-'.$order->get_id().'-refund-'.$refund->get_id(),
            invoiceDate: new DateTimeImmutable($refund->get_date_created()?->format('Y-m-d H:i:s') ?? 'now'),
            currency: Currency::Eur,
            destinationCountry: strtoupper($order->get_billing_country()),
            netAmount: $this->amount((float) $refund->get_total() - (float) $refund->get_total_tax()),
            vatAmount: $this->amount((float) $refund->get_total_tax()),
            grossAmount: $this->amount((float) $refund->get_total()),
            lines: $this->refundLines($refund),
            debtor: $this->toDebtorRequest($order),
            externalOrderId: (string) $order->get_order_number(),
            document: $document,
            paymentMethod: $order->get_payment_method() ?: null,
        );
    }

    public function toDebtorRequest(WC_Order $order): CreateDebtorRequest
    {
        $customerId = $order->get_customer_id();
        $companyName = trim($order->get_billing_company());
        $isBusiness = $companyName !== '';

        $address = trim($order->get_billing_address_1().' '.$order->get_billing_address_2());

        return new CreateDebtorRequest(
            externalCustomerId: $customerId > 0 ? 'wc-customer-'.$customerId : null,
            oneTime: $customerId <= 0,
            type: $isBusiness ? DebtorType::Business : DebtorType::Consumer,
            country: strtoupper($order->get_billing_country()),
            companyName: $isBusiness ? $companyName : null,
            firstName: $order->get_billing_first_name() ?: null,
            lastName: $order->get_billing_last_name() ?: null,
            email: $order->get_billing_email() ?: null,
            vatNumber: $this->vatNumbers->resolve($order),
            address: $address !== '' ? $address : null,
            postalCode: $order->get_billing_postcode() ?: null,
            city: $order->get_billing_city() ?: null,
        );
    }

    /** @return list<InvoiceLine> */
    private function invoiceLines(WC_Order $order): array
    {
        return $this->linesFor($order->get_items(self::ITEM_TYPES));
    }

    /** @return list<InvoiceLine> */
    private function refundLines(WC_Order_Refund $refund): array
    {
        $lines = $this->linesFor($refund->get_items(self::ITEM_TYPES));

        if ($lines !== []) {
            return $lines;
        }

        $netAmount = (float) $refund->get_total() - (float) $refund->get_total_tax();
        $vatAmount = (float) $refund->get_total_tax();
        $vatRate = $netAmount !== 0.0 ? number_format(abs($vatAmount / $netAmount) * 100, 2, '.', '') : '0';

        return [
            new InvoiceLine(
                externalId: null,
                description: $refund->get_reason() ?: 'Terugbetaling',
                sku: null,
                quantity: '1',
                unitPrice: $this->amount($netAmount),
                netAmount: $this->amount($netAmount),
                vatAmount: $this->amount($vatAmount),
                grossAmount: $this->amount($netAmount + $vatAmount),
                vatRate: $vatRate,
            ),
        ];
    }

    /**
     * @param iterable<mixed> $items
     * @return list<InvoiceLine>
     */
    private function linesFor(iterable $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $product = $item->get_product();
                $quantity = max(1, abs((int) $item->get_quantity()));
                $sku = $product !== false ? ($product->get_sku() ?: null) : null;
                array_push($lines, ...$this->linesPerRate($item, $item->get_name(), $sku, $quantity));
            } elseif ($item instanceof WC_Order_Item_Shipping || $item instanceof WC_Order_Item_Fee) {
                if (round((float) $item->get_total(), 2) === 0.0 && round((float) $item->get_total_tax(), 2) === 0.0) {
                    continue;
                }
                array_push($lines, ...$this->linesPerRate($item, $item->get_name(), null, 1));
            }
        }

        return $lines;
    }

    /** @return list<InvoiceLine> */
    private function linesPerRate(WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item, string $description, ?string $sku, int $quantity): array
    {
        $net = round((float) $item->get_total(), 2);
        $vat = round((float) $item->get_total_tax(), 2);
        $taxes = array_filter(
            array_map(static fn (mixed $amount): float => round((float) $amount, 2), $item->get_taxes()['total'] ?? []),
            static fn (float $amount): bool => $amount !== 0.0,
        );

        if (count($taxes) <= 1) {
            $rateId = array_key_first($taxes) ?? array_key_first($item->get_taxes()['total'] ?? []);

            return [$this->line((string) $item->get_id(), $description, $sku, $quantity, $net, $vat, $rateId !== null ? $this->ratePercent((int) $rateId) : '0')];
        }

        $lines = [];
        $allocated = 0.0;

        foreach ($taxes as $rateId => $rateVat) {
            $percent = $this->ratePercent((int) $rateId);
            $rateNet = (float) $percent > 0 ? round($rateVat * 100 / (float) $percent, 2) : 0.0;
            $allocated += $rateNet;
            $lines[] = $this->line($item->get_id().'-'.$rateId, $description, $sku, 1, $rateNet, $rateVat, $percent);
        }

        $remainder = round($net - $allocated, 2);

        if ($remainder !== 0.0) {
            $lines[] = $this->line($item->get_id().'-0', $description, $sku, 1, $remainder, 0.0, '0');
        }

        return $lines;
    }

    private function line(string $externalId, string $description, ?string $sku, int $quantity, float $net, float $vat, string $vatRate): InvoiceLine
    {
        return new InvoiceLine(
            externalId: $externalId,
            description: $description,
            sku: $sku,
            quantity: (string) $quantity,
            unitPrice: $this->amount($net / $quantity),
            netAmount: $this->amount($net),
            vatAmount: $this->amount($vat),
            grossAmount: $this->amount($net + $vat),
            vatRate: $vatRate,
        );
    }

    private function ratePercent(int $rateId): string
    {
        return number_format((float) rtrim(WC_Tax::get_rate_percent($rateId), '%'), 2, '.', '');
    }

    private function amount(float $value): DecimalAmount
    {
        return new DecimalAmount(number_format($value, 2, '.', ''));
    }
}
