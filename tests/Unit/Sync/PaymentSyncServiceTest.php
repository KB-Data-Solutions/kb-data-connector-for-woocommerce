<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use KBDataSolutions\Sdk\Client;
use KBDataSolutions\Sdk\Exception\SdkException;
use KBDataSolutions\Sdk\Http\TransportResponse;
use KBDataSolutions\Sdk\PaymentMethod\PaymentMethod;
use MinoxWooCommerce\Sync\IntegrationSettings;
use MinoxWooCommerce\Sync\PaymentMethodSyncService;
use MinoxWooCommerce\Sync\PaymentSyncService;
use MinoxWooCommerce\Tests\Fakes\FakeAuthenticator;
use MinoxWooCommerce\Tests\Fakes\FakeTransport;
use Mockery;
use PHPUnit\Framework\TestCase;
use WC_DateTime;
use WC_Order;

final class PaymentSyncServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_confirmed_payment_is_sent_separately_using_existing_credentials(): void
    {
        $transport = new FakeTransport();
        $transport->queueResponse($this->accepted());
        $meta = ['_minox_invoice_id' => 'invoice-1'];
        $order = $this->order($meta);
        (new PaymentSyncService())->sync($order, $this->settings($transport));
        $request = $transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('/api/v1/payments', $request->path);
        self::assertSame('Bearer fake-token', $request->headers['Authorization']);
        $body = json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('wc-order-42', $body['external_invoice_id']);
        self::assertSame('12.10', $body['amount']);
        self::assertSame('confirmed', $body['status']);
        self::assertArrayNotHasKey('methods', $body);
        self::assertSame('payment-1', $meta['_minox_payment_id']);
        $last = $transport->lastRequest();
        (new PaymentSyncService())->sync($order, $this->settings($transport));
        self::assertSame($last, $transport->lastRequest());
    }

    public function test_unpaid_orders_and_offline_choices_are_not_automatically_declared_paid(): void
    {
        foreach (['bacs', 'cod', 'cheque', 'mollie_wc_gateway_ideal'] as $method) {
            $transport = new FakeTransport();
            $meta = ['_minox_invoice_id' => 'invoice-1'];
            $order = $this->order($meta, $method, $method !== 'mollie_wc_gateway_ideal');
            (new PaymentSyncService())->sync($order, $this->settings($transport));
            self::assertNull($transport->lastRequest());
            self::assertArrayNotHasKey('_minox_payment_id', $meta);
        }
    }

    public function test_a_paid_status_without_a_transaction_reference_is_not_enough(): void
    {
        $transport = new FakeTransport();
        $meta = ['_minox_invoice_id' => 'invoice-1'];
        (new PaymentSyncService())->sync($this->order($meta, transaction: ''), $this->settings($transport));
        self::assertNull($transport->lastRequest());
    }

    public function test_cash_is_only_reported_after_an_explicit_manual_confirmation(): void
    {
        $transport = new FakeTransport();
        $transport->queueResponse($this->accepted());
        $meta = ['_minox_invoice_id' => 'invoice-1', '_minox_cash_confirmed_at' => '2026-01-01T12:00:00+00:00'];
        $order = $this->order($meta, method: 'cod', paid: false, transaction: '');
        (new PaymentSyncService())->sync($order, $this->settings($transport));
        $request = $transport->lastRequest();
        self::assertNotNull($request);
        $body = json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cash-wc-order-42', $body['transaction_reference']);
        self::assertSame('cod', $body['payment_method']);
        self::assertSame('payment-1', $meta['_minox_payment_id']);
    }

    public function test_retry_reuses_the_exact_payment_snapshot_and_idempotency_key(): void
    {
        $transport = new FakeTransport();
        $transport->queueResponse(new TransportResponse(500, [], '{"error":{"code":"TEMPORARY","message":"Try later"}}'));
        $transport->queueResponse($this->accepted());
        $meta = ['_minox_invoice_id' => 'invoice-1'];
        $order = $this->order($meta);
        try {
            (new PaymentSyncService())->sync($order, $this->settings($transport));
            self::fail('The temporary API failure must propagate to the retry handler.');
        } catch (SdkException) {
            self::assertArrayHasKey('_minox_payment_snapshot', $meta);
            self::assertArrayNotHasKey('_minox_payment_id', $meta);
        }
        $first = $transport->lastRequest();
        (new PaymentSyncService())->sync($order, $this->settings($transport));
        $retry = $transport->lastRequest();
        self::assertNotNull($first);
        self::assertNotNull($retry);
        self::assertSame($first->body, $retry->body);
        self::assertSame($first->headers['Idempotency-Key'], $retry->headers['Idempotency-Key']);
    }

    public function test_catalogue_uses_its_own_request_without_an_invoice(): void
    {
        $transport = new FakeTransport();
        $transport->queueResponse(new TransportResponse(200, [], '{"data":{"methods":[{"key":"custom_gateway","label":"Mijn betaalmethode","provider":null,"enabled":true}]}}'));
        (new PaymentMethodSyncService())->sync($this->settings($transport), [new PaymentMethod('custom_gateway', 'Mijn betaalmethode')]);
        $request = $transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('/api/v1/payment-methods', $request->path);
        self::assertStringNotContainsString('external_invoice_id', (string) $request->body);
    }

    /** @param array<string, string> $meta */
    private function order(array &$meta, string $method = 'mollie_wc_gateway_ideal', bool $paid = true, string $transaction = 'tr-42'): WC_Order
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('is_paid')->andReturn($paid);
        $order->shouldReceive('get_payment_method')->andReturn($method);
        $order->shouldReceive('get_transaction_id')->andReturn($transaction);
        $order->shouldReceive('get_date_paid')->andReturn(new WC_DateTime('2026-01-01T12:00:00+00:00'));
        $order->shouldReceive('get_total')->andReturn('12.10');
        $order->shouldReceive('get_meta')->andReturnUsing(static function (string $key) use (&$meta): string {
            return $meta[$key] ?? '';
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(static function (string $key, string $value) use (&$meta): void {
            $meta[$key] = $value;
        });
        $order->shouldReceive('save_meta_data')->andReturnNull();
        $order->shouldReceive('add_order_note')->andReturnNull();
        return $order;
    }

    private function settings(FakeTransport $transport): IntegrationSettings
    {
        $client = Client::create(
            'existing-id',
            'existing-secret',
            'https://example.test',
            'woocommerce',
            transport: $transport,
            authenticator: new FakeAuthenticator()
        );
        return new class ($client) implements IntegrationSettings {
            public function __construct(private readonly Client $client)
            {
            }
            public function isConfigured(): bool
            {
                return true;
            }
            public function syncTriggerStatus(): string
            {
                return 'processing';
            }
            public function attachPdfInvoice(): bool
            {
                return false;
            }
            public function syncRefunds(): bool
            {
                return true;
            }
            public function vatNumberMetaKey(): ?string
            {
                return null;
            }
            public function makeClient(): ?Client
            {
                return $this->client;
            }
        };
    }

    private function accepted(): TransportResponse
    {
        return new TransportResponse(202, [], '{"data":{"id":"payment-1","external_payment_id":"wc-42","status":"pending","error_code":null}}');
    }
}
