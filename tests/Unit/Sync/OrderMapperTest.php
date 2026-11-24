<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use KBDataSolutions\Sdk\Debtor\DebtorType;
use Mockery;
use MinoxWooCommerce\Sync\OrderMapper;
use PHPUnit\Framework\TestCase;
use WC_DateTime;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;
use WC_Tax;

final class OrderMapperTest extends TestCase
{
    protected function tearDown(): void
    {
        WC_Tax::$ratePercentages = [];
        Mockery::close();

        parent::tearDown();
    }

    public function test_maps_a_registered_consumer_order(): void
    {
        WC_Tax::$ratePercentages = [1 => '21.0000%'];

        $item = Mockery::mock(WC_Order_Item_Product::class);
        $item->shouldReceive('get_id')->andReturn(501);
        $item->shouldReceive('get_name')->andReturn('Widget');
        $item->shouldReceive('get_total')->andReturn('10.00');
        $item->shouldReceive('get_total_tax')->andReturn('2.10');
        $item->shouldReceive('get_quantity')->andReturn(2);
        $item->shouldReceive('get_product')->andReturn(false);
        $item->shouldReceive('get_taxes')->andReturn(['total' => [1 => '2.10']]);

        $order = $this->makeOrder([
            'billing_country' => 'NL',
            'id' => 1042,
            'order_number' => '1042',
            'total' => '12.10',
            'total_tax' => '2.10',
            'items' => [$item],
            'customer_id' => 42,
            'billing_company' => '',
            'billing_first_name' => 'Jane',
            'billing_last_name' => 'Doe',
            'billing_email' => 'jane@example.test',
            'vat_number' => '',
        ]);

        $invoice = (new OrderMapper())->toInvoiceRequest($order, null);

        self::assertSame('wc-order-1042', $invoice->externalInvoiceId);
        self::assertSame('1042', $invoice->externalOrderId);
        self::assertSame('NL', $invoice->destinationCountry);
        self::assertSame('12.10', $invoice->grossAmount->value);
        self::assertSame('10.00', $invoice->netAmount->value);
        self::assertSame('2.10', $invoice->vatAmount->value);
        self::assertCount(1, $invoice->lines);
        self::assertSame('21.00', $invoice->lines[0]->vatRate);
        self::assertSame('5.00', $invoice->lines[0]->unitPrice->value);

        self::assertSame('wc-customer-42', $invoice->debtor->externalCustomerId);
        self::assertFalse($invoice->debtor->oneTime);
        self::assertSame(DebtorType::Consumer, $invoice->debtor->type);
        self::assertSame('Jane', $invoice->debtor->firstName);
    }

    public function test_maps_a_guest_business_order_as_one_time(): void
    {
        $order = $this->makeOrder([
            'billing_country' => 'NL',
            'id' => 2001,
            'order_number' => '2001',
            'total' => '0',
            'total_tax' => '0',
            'customer_id' => 0,
            'billing_company' => 'Acme BV',
            'vat_number' => 'NL123456789B01',
        ]);

        $debtor = (new OrderMapper())->toDebtorRequest($order);

        self::assertTrue($debtor->oneTime);
        self::assertNull($debtor->externalCustomerId);
        self::assertSame(DebtorType::Business, $debtor->type);
        self::assertSame('Acme BV', $debtor->companyName);
        self::assertSame('NL123456789B01', $debtor->vatNumber);
    }

    public function test_never_declares_a_tax_treatment_leaving_it_for_the_api_to_infer(): void
    {
        $order = $this->makeOrder([
            'billing_country' => 'DE',
            'id' => 3001,
            'order_number' => '3001',
            'total' => '10.00',
            'total_tax' => '0',
            'customer_id' => 7,
            'billing_company' => 'Acme GmbH',
            'vat_number' => 'DE123456789',
        ]);

        $invoice = (new OrderMapper())->toInvoiceRequest($order, null);

        self::assertNull($invoice->taxTreatment);
    }

    public function test_sends_the_raw_destination_country_and_debtor_facts_without_interpretation(): void
    {
        $order = $this->makeOrder([
            'billing_country' => 'us',
            'id' => 4001,
            'order_number' => '4001',
            'total' => '10.00',
            'total_tax' => '0',
            'customer_id' => 11,
            'billing_company' => 'Acme Ltd',
            'vat_number' => '',
        ]);

        $invoice = (new OrderMapper())->toInvoiceRequest($order, null);

        self::assertSame('US', $invoice->destinationCountry);
        self::assertSame(DebtorType::Business, $invoice->debtor->type);
        self::assertSame('Acme Ltd', $invoice->debtor->companyName);
        self::assertNull($invoice->debtor->vatNumber);
    }

    public function test_passes_the_raw_payment_method_through_unmodified(): void
    {
        $order = $this->makeOrder([
            'id' => 5001,
            'order_number' => '5001',
            'payment_method' => 'mollie_wc_gateway_ideal',
        ]);

        $invoice = (new OrderMapper())->toInvoiceRequest($order, null);

        self::assertSame('mollie_wc_gateway_ideal', $invoice->paymentMethod);
    }

    public function test_payment_method_is_null_when_order_has_none(): void
    {
        $order = $this->makeOrder([
            'id' => 5002,
            'order_number' => '5002',
            'payment_method' => '',
        ]);

        $invoice = (new OrderMapper())->toInvoiceRequest($order, null);

        self::assertNull($invoice->paymentMethod);
    }

    public function test_refund_maps_negative_amounts_and_normalizes_quantity_to_positive(): void
    {
        WC_Tax::$ratePercentages = [1 => '21.0000%'];

        $order = $this->makeOrder([
            'billing_country' => 'NL',
            'id' => 1042,
            'order_number' => '1042',
            'customer_id' => 42,
            'payment_method' => 'mollie_wc_gateway_ideal',
        ]);

        $refundItem = Mockery::mock(WC_Order_Item_Product::class);
        $refundItem->shouldReceive('get_id')->andReturn(901);
        $refundItem->shouldReceive('get_name')->andReturn('Widget');
        $refundItem->shouldReceive('get_total')->andReturn('-10.00');
        $refundItem->shouldReceive('get_total_tax')->andReturn('-2.10');
        $refundItem->shouldReceive('get_quantity')->andReturn(-2);
        $refundItem->shouldReceive('get_product')->andReturn(false);
        $refundItem->shouldReceive('get_taxes')->andReturn(['total' => [1 => '-2.10']]);

        $refund = $this->makeRefund([
            'id' => 77,
            'total' => '-12.10',
            'total_tax' => '-2.10',
            'items' => [$refundItem],
        ]);

        $invoice = (new OrderMapper())->toRefundInvoiceRequest($order, $refund, null);

        self::assertSame('wc-order-1042-refund-77', $invoice->externalInvoiceId);
        self::assertSame('1042', $invoice->externalOrderId);
        self::assertSame('-12.10', $invoice->grossAmount->value);
        self::assertSame('-10.00', $invoice->netAmount->value);
        self::assertSame('-2.10', $invoice->vatAmount->value);
        self::assertSame('wc-customer-42', $invoice->debtor->externalCustomerId);
        self::assertSame('mollie_wc_gateway_ideal', $invoice->paymentMethod);

        self::assertCount(1, $invoice->lines);
        self::assertSame('2', $invoice->lines[0]->quantity);
        self::assertSame('-5.00', $invoice->lines[0]->unitPrice->value);
        self::assertSame('-10.00', $invoice->lines[0]->netAmount->value);
        self::assertSame('21.00', $invoice->lines[0]->vatRate);
    }

    public function test_refund_without_line_items_falls_back_to_a_single_total_line(): void
    {
        $order = $this->makeOrder(['id' => 1042, 'order_number' => '1042']);

        $refund = $this->makeRefund([
            'id' => 78,
            'total' => '-25.00',
            'total_tax' => '-4.34',
            'items' => [],
            'reason' => 'Verzendkosten gecrediteerd',
        ]);

        $invoice = (new OrderMapper())->toRefundInvoiceRequest($order, $refund, null);

        self::assertCount(1, $invoice->lines);
        self::assertSame('Verzendkosten gecrediteerd', $invoice->lines[0]->description);
        self::assertSame('1', $invoice->lines[0]->quantity);
        self::assertSame('-20.66', $invoice->lines[0]->netAmount->value);
        self::assertSame('-4.34', $invoice->lines[0]->vatAmount->value);
        self::assertSame('-25.00', $invoice->lines[0]->grossAmount->value);
    }

    /** @param  array<string, mixed>  $overrides */
    public function test_shipping_and_fees_become_lines_and_free_shipping_lines_are_left_out(): void
    {
        WC_Tax::$ratePercentages = [7 => '12.0000%'];
        $product = $this->charge(WC_Order_Item_Product::class, 11, 'Echte Bakkers Speculaas', '45.11', '5.41', [7 => '5.41'], 13);
        $freeShipping = $this->charge(WC_Order_Item_Shipping::class, 12, 'Standaard bezorging', '0.00', '0.00', []);
        $shipping = $this->charge(WC_Order_Item_Shipping::class, 13, 'DPD 0-20 kg', '20.50', '2.46', [7 => '2.46']);
        $fee = $this->charge(WC_Order_Item_Fee::class, 14, 'Toeslag', '1.00', '0.12', [7 => '0.12']);

        $invoice = (new OrderMapper())->toInvoiceRequest($this->makeOrder([
            'total' => '74.60', 'total_tax' => '7.99', 'items' => [$product, $freeShipping, $shipping, $fee],
        ]), null);

        self::assertSame(['Echte Bakkers Speculaas', 'DPD 0-20 kg', 'Toeslag'], array_map(static fn ($line): string => $line->description, $invoice->lines));
        self::assertSame('66.61', $invoice->netAmount->value);
        self::assertSame('66.61', number_format(array_sum(array_map(static fn ($line): float => (float) $line->netAmount->value, $invoice->lines)), 2, '.', ''));
        self::assertSame('7.99', number_format(array_sum(array_map(static fn ($line): float => (float) $line->vatAmount->value, $invoice->lines)), 2, '.', ''));
        self::assertSame('12.00', $invoice->lines[1]->vatRate);
        self::assertSame('1', $invoice->lines[1]->quantity);
    }

    public function test_shipping_spread_over_two_vat_rates_is_split_per_rate(): void
    {
        WC_Tax::$ratePercentages = [1 => '21.0000%', 2 => '9.0000%'];
        $shipping = $this->charge(WC_Order_Item_Shipping::class, 20, 'Verzending', '10.00', '1.29', [1 => '0.84', 2 => '0.45']);

        $invoice = (new OrderMapper())->toInvoiceRequest($this->makeOrder(['total' => '11.29', 'total_tax' => '1.29', 'items' => [$shipping]]), null);

        $byRate = [];
        foreach ($invoice->lines as $line) {
            $byRate[$line->vatRate] = [$line->netAmount->value, $line->vatAmount->value];
            self::assertEqualsWithDelta((float) $line->netAmount->value * (float) $line->vatRate / 100, (float) $line->vatAmount->value, 0.01);
        }
        self::assertSame(['4.00', '0.84'], $byRate['21.00']);
        self::assertSame(['5.00', '0.45'], $byRate['9.00']);
        self::assertSame(['1.00', '0.00'], $byRate['0']);
        self::assertSame('10.00', number_format(array_sum(array_map(static fn ($line): float => (float) $line->netAmount->value, $invoice->lines)), 2, '.', ''));
    }

    public function test_a_refund_including_shipping_credits_the_shipping_line_too(): void
    {
        WC_Tax::$ratePercentages = [1 => '21.0000%'];
        $product = $this->charge(WC_Order_Item_Product::class, 30, 'Widget', '-10.00', '-2.10', [1 => '-2.10'], -1);
        $shipping = $this->charge(WC_Order_Item_Shipping::class, 31, 'PostNL', '-5.00', '-1.05', [1 => '-1.05']);

        $invoice = (new OrderMapper())->toRefundInvoiceRequest($this->makeOrder([]), $this->makeRefund([
            'total' => '-18.15', 'total_tax' => '-3.15', 'items' => [$product, $shipping],
        ]), null);

        self::assertCount(2, $invoice->lines);
        self::assertSame('-15.00', number_format(array_sum(array_map(static fn ($line): float => (float) $line->netAmount->value, $invoice->lines)), 2, '.', ''));
        self::assertSame('-15.00', $invoice->netAmount->value);
    }

    /**
     * @param class-string $class
     * @param array<int, string> $taxes
     */
    private function charge(string $class, int $id, string $name, string $total, string $tax, array $taxes, ?int $quantity = null): object
    {
        $item = Mockery::mock($class);
        $item->shouldReceive('get_id')->andReturn($id);
        $item->shouldReceive('get_name')->andReturn($name);
        $item->shouldReceive('get_total')->andReturn($total);
        $item->shouldReceive('get_total_tax')->andReturn($tax);
        $item->shouldReceive('get_taxes')->andReturn(['total' => $taxes]);
        if ($quantity !== null) {
            $item->shouldReceive('get_quantity')->andReturn($quantity);
            $item->shouldReceive('get_product')->andReturn(false);
        }

        return $item;
    }

    private function makeRefund(array $overrides): WC_Order_Refund
    {
        $defaults = [
            'id' => 1,
            'date_created' => new WC_DateTime('2026-08-02 09:00:00'),
            'total' => '0',
            'total_tax' => '0',
            'items' => [],
            'reason' => '',
        ];
        $data = array_merge($defaults, $overrides);

        $refund = Mockery::mock(WC_Order_Refund::class);
        $refund->shouldReceive('get_id')->andReturn($data['id']);
        $refund->shouldReceive('get_date_created')->andReturn($data['date_created']);
        $refund->shouldReceive('get_total')->andReturn($data['total']);
        $refund->shouldReceive('get_total_tax')->andReturn($data['total_tax']);
        $refund->shouldReceive('get_items')->andReturn($data['items']);
        $refund->shouldReceive('get_reason')->andReturn($data['reason']);

        return $refund;
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeOrder(array $overrides): WC_Order
    {
        $defaults = [
            'billing_country' => 'NL',
            'id' => 1,
            'order_number' => '1',
            'date_created' => new WC_DateTime('2026-08-01 12:00:00'),
            'total' => '0',
            'total_tax' => '0',
            'items' => [$this->makeItem()],
            'customer_id' => 0,
            'billing_company' => '',
            'billing_first_name' => '',
            'billing_last_name' => '',
            'billing_email' => '',
            'billing_address_1' => '',
            'billing_address_2' => '',
            'billing_postcode' => '',
            'billing_city' => '',
            'vat_number' => '',
            'payment_method' => '',
        ];
        $data = array_merge($defaults, $overrides);

        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_billing_country')->andReturn($data['billing_country']);
        $order->shouldReceive('get_id')->andReturn($data['id']);
        $order->shouldReceive('get_order_number')->andReturn($data['order_number']);
        $order->shouldReceive('get_date_created')->andReturn($data['date_created']);
        $order->shouldReceive('get_total')->andReturn($data['total']);
        $order->shouldReceive('get_total_tax')->andReturn($data['total_tax']);
        $order->shouldReceive('get_items')->andReturn($data['items']);
        $order->shouldReceive('get_customer_id')->andReturn($data['customer_id']);
        $order->shouldReceive('get_billing_company')->andReturn($data['billing_company']);
        $order->shouldReceive('get_billing_first_name')->andReturn($data['billing_first_name']);
        $order->shouldReceive('get_billing_last_name')->andReturn($data['billing_last_name']);
        $order->shouldReceive('get_billing_email')->andReturn($data['billing_email']);
        $order->shouldReceive('get_billing_address_1')->andReturn($data['billing_address_1']);
        $order->shouldReceive('get_billing_address_2')->andReturn($data['billing_address_2']);
        $order->shouldReceive('get_billing_postcode')->andReturn($data['billing_postcode']);
        $order->shouldReceive('get_billing_city')->andReturn($data['billing_city']);
        $order->shouldReceive('get_meta')->andReturnUsing(static fn (string $key): string => $key === '_vat_number' ? $data['vat_number'] : '');
        $order->shouldReceive('get_payment_method')->andReturn($data['payment_method']);

        return $order;
    }

    private function makeItem(): WC_Order_Item_Product
    {
        $item = Mockery::mock(WC_Order_Item_Product::class);
        $item->shouldReceive('get_id')->andReturn(1);
        $item->shouldReceive('get_name')->andReturn('Widget');
        $item->shouldReceive('get_total')->andReturn('10.00');
        $item->shouldReceive('get_total_tax')->andReturn('0.00');
        $item->shouldReceive('get_quantity')->andReturn(1);
        $item->shouldReceive('get_product')->andReturn(false);
        $item->shouldReceive('get_taxes')->andReturn(['total' => []]);

        return $item;
    }
}
