<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeZone;
use KBDataSolutions\Sdk\Client;
use KBDataSolutions\Sdk\Http\TransportResponse;
use MinoxWooCommerce\Sync\ImportJobService;
use MinoxWooCommerce\Sync\IntegrationSettings;
use MinoxWooCommerce\Sync\OrderSyncService;
use MinoxWooCommerce\Tests\Fakes\FakeAuthenticator;
use MinoxWooCommerce\Tests\Fakes\FakeTransport;
use Mockery;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Refund;

final class ImportJobServiceTest extends TestCase
{
    private const string JOB = '{"id":"%s","status":"%s","from_date":"2026-10-01","to_date":"2026-11-18T10:00:00+00:00","cursor":%s,"orders_sent":%d,"orders_failed":0,"refunds_sent":0}';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array{hook: string, args: array<int, mixed>}> */
    private array $scheduled = [];

    /** @var list<array<string, mixed>> */
    private array $orderQueries = [];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transport = new FakeTransport();

        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);

            return true;
        });
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Amsterdam'));
        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\when('as_schedule_single_action')->alias(function (int $timestamp, string $hook, array $args = []): int {
            $this->scheduled[] = ['hook' => $hook, 'args' => $args];

            return 1;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_poll_claims_the_first_free_job_and_schedules_the_first_batch(): void
    {
        $this->transport->queueResponse($this->json('{"data":{"jobs":['.sprintf(self::JOB, '7', 'pending', 'null', 0).','.sprintf(self::JOB, '8', 'pending', 'null', 0).']}}'));
        $this->transport->queueResponse(new TransportResponse(409, [], '{"error":{"code":"IMPORT_NOT_CLAIMABLE","message":"taken"}}'));
        $this->transport->queueResponse($this->json('{"data":'.sprintf(self::JOB, '8', 'processing', '"100"', 100).'}'));

        $this->service()->poll();

        $state = ImportJobService::currentState();
        self::assertNotNull($state);
        self::assertSame('8', $state['job_id']);
        self::assertSame(100, $state['offset'], 'Resumes from the server-side cursor.');
        self::assertSame(100, $state['orders_sent']);
        self::assertSame([['hook' => ImportJobService::BATCH_HOOK, 'args' => ['8', 0]]], $this->scheduled);
    }

    public function test_last_batch_books_orders_and_refunds_then_completes_the_job(): void
    {
        $this->startState('9');
        $order = $this->syncedOrder(11, [$this->syncedRefund()]);
        $this->expectOrders([$order]);
        $this->transport->queueResponse($this->json('{"data":'.sprintf(self::JOB, '9', 'completed', '"1"', 1).'}'));

        $this->service()->runBatch('9');

        $request = $this->transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('/api/v1/import-jobs/9/complete', $request->path);
        self::assertSame(
            ['status' => 'completed', 'orders_sent' => 1, 'orders_failed' => 0, 'refunds_sent' => 1, 'last_error' => null],
            json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertNull(ImportJobService::currentState());
        self::assertSame(1, $this->options[ImportJobService::LAST_RESULT_OPTION]['orders_sent']);
        self::assertSame(['wc-processing', 'wc-completed', 'wc-refunded'], $this->orderQueries[0]['status']);
    }

    public function test_full_batch_reports_progress_and_schedules_the_next_batch(): void
    {
        $this->startState('9');
        $orders = array_map(fn (int $id): WC_Order => $this->syncedOrder($id), range(1, ImportJobService::BATCH_SIZE));
        $this->expectOrders($orders);
        $this->transport->queueResponse($this->json('{"data":'.sprintf(self::JOB, '9', 'processing', '"50"', 50).'}'));

        $this->service()->runBatch('9');

        $request = $this->transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('/api/v1/import-jobs/9/progress', $request->path);
        self::assertSame('50', json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR)['cursor']);
        self::assertSame(50, ImportJobService::currentState()['offset'] ?? null);
        self::assertContains(['hook' => ImportJobService::BATCH_HOOK, 'args' => ['9', 0]], $this->scheduled);
    }

    public function test_refunds_are_skipped_entirely_when_refund_booking_is_off(): void
    {
        $this->startState('9');
        $refund = Mockery::mock(WC_Order_Refund::class);
        $refund->shouldNotReceive('get_meta');
        $this->expectOrders([$this->syncedOrder(11, [$refund])]);
        $this->transport->queueResponse($this->json('{"data":'.sprintf(self::JOB, '9', 'completed', '"1"', 1).'}'));

        $this->service(syncRefunds: false)->runBatch('9');

        self::assertSame(['wc-processing', 'wc-completed'], $this->orderQueries[0]['status']);
        $body = json_decode((string) $this->transport->lastRequest()?->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['refunds_sent']);
    }

    public function test_failed_orders_are_counted_with_their_error(): void
    {
        $this->startState('9');
        $broken = Mockery::mock(WC_Order::class)->shouldIgnoreMissing();
        $broken->allows('get_meta')->andReturn('');
        $broken->allows('get_id')->andReturn(42);
        $this->expectOrders([$broken]);
        $this->transport->queueResponse($this->json('{"data":'.sprintf(self::JOB, '9', 'failed', '"1"', 0).'}'));

        $this->service()->runBatch('9');

        $body = json_decode((string) $this->transport->lastRequest()?->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('failed', $body['status']);
        self::assertSame(1, $body['orders_failed']);
        self::assertStringStartsWith('Order 42:', (string) $body['last_error']);
    }

    public function test_job_cancelled_in_the_portal_stops_the_import(): void
    {
        $this->startState('9');
        $this->expectOrders(array_map(fn (int $id): WC_Order => $this->syncedOrder($id), range(1, ImportJobService::BATCH_SIZE)));
        $this->transport->queueResponse(new TransportResponse(409, [], '{"error":{"code":"IMPORT_NOT_PROCESSING","message":"cancelled"}}'));

        $this->service()->runBatch('9');

        self::assertNull(ImportJobService::currentState());
        self::assertSame([], array_filter($this->scheduled, static fn (array $action): bool => $action['hook'] === ImportJobService::BATCH_HOOK));
    }

    public function test_batch_for_an_unknown_job_does_nothing(): void
    {
        $this->startState('9');
        Functions\expect('wc_get_orders')->never();

        $this->service()->runBatch('10');

        self::assertSame([], $this->transport->sentRequests());
    }

    private function startState(string $jobId): void
    {
        $this->options[ImportJobService::STATE_OPTION] = [
            'job_id' => $jobId, 'from' => '2026-10-01', 'to' => '2026-11-18T10:00:00+00:00', 'offset' => 0,
            'orders_sent' => 0, 'orders_failed' => 0, 'refunds_sent' => 0, 'last_error' => null,
        ];
    }

    /** @param list<WC_Order> $orders */
    private function expectOrders(array $orders): void
    {
        Functions\when('wc_get_orders')->alias(function (array $args) use ($orders): array {
            $this->orderQueries[] = $args;

            return $orders;
        });
    }

    /** @param list<WC_Order_Refund> $refunds */
    private function syncedOrder(int $id, array $refunds = []): WC_Order
    {
        $order = Mockery::mock(WC_Order::class);
        $order->allows('get_id')->andReturn($id);
        $order->allows('get_meta')->with(OrderSyncService::META_INVOICE_ID)->andReturn('inv-'.$id);
        $order->allows('get_refunds')->andReturn($refunds);

        return $order;
    }

    private function syncedRefund(): WC_Order_Refund
    {
        $refund = Mockery::mock(WC_Order_Refund::class);
        $refund->allows('get_id')->andReturn(99);
        $refund->allows('get_meta')->with(OrderSyncService::META_INVOICE_ID)->andReturn('credit-99');

        return $refund;
    }

    private function json(string $body): TransportResponse
    {
        return new TransportResponse(200, [], $body);
    }

    private function service(bool $syncRefunds = true): ImportJobService
    {
        $client = Client::create('id', 'secret', 'https://example.test', 'woocommerce', transport: $this->transport, authenticator: new FakeAuthenticator());

        return new ImportJobService(new class ($client, $syncRefunds) implements IntegrationSettings {
            public function __construct(private readonly Client $client, private readonly bool $refunds)
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
                return $this->refunds;
            }
            public function vatNumberMetaKey(): ?string
            {
                return null;
            }
            public function makeClient(): ?Client
            {
                return $this->client;
            }
        });
    }
}
