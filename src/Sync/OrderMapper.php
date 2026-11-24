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
use WC_Order_Item_Product;
use WC_Order_Refund;
use WC_Tax;

final class OrderMapper
{
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
        $lines = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $netAmount = (float) $item->get_total();
            $vatAmount = (float) $item->get_total_tax();
            $quantity = max(1, (int) $item->get_quantity());
            $product = $item->get_product();

            $lines[] = new InvoiceLine(
                externalId: (string) $item->get_id(),
                description: $item->get_name(),
                sku: $product !== false ? ($product->get_sku() ?: null) : null,
                quantity: (string) $quantity,
                unitPrice: $this->amount($netAmount / $quantity),
                netAmount: $this->amount($netAmount),
                vatAmount: $this->amount($vatAmount),
                grossAmount: $this->amount($netAmount + $vatAmount),
                vatRate: $this->vatRatePercentage($item),
            );
        }

        return $lines;
    }

    /** @return list<InvoiceLine> */
    private function refundLines(WC_Order_Refund $refund): array
    {
        $lines = [];

        foreach ($refund->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $netAmount = (float) $item->get_total();
            $vatAmount = (float) $item->get_total_tax();
            $quantity = max(1, abs((int) $item->get_quantity()));
            $product = $item->get_product();

            $lines[] = new InvoiceLine(
                externalId: (string) $item->get_id(),
                description: $item->get_name(),
                sku: $product !== false ? ($product->get_sku() ?: null) : null,
                quantity: (string) $quantity,
                unitPrice: $this->amount($netAmount / $quantity),
                netAmount: $this->amount($netAmount),
                vatAmount: $this->amount($vatAmount),
                grossAmount: $this->amount($netAmount + $vatAmount),
                vatRate: $this->vatRatePercentage($item),
            );
        }

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

    private function vatRatePercentage(WC_Order_Item_Product $item): string
    {
        $taxes = $item->get_taxes();
        $rateIds = array_keys($taxes['total'] ?? []);

        if ($rateIds === []) {
            return '0';
        }

        $percent = (float) rtrim(WC_Tax::get_rate_percent((int) $rateIds[0]), '%');

        return number_format($percent, 2, '.', '');
    }

    private function amount(float $value): DecimalAmount
    {
        return new DecimalAmount(number_format($value, 2, '.', ''));
    }
}
