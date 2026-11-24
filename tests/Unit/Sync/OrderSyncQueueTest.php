<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use KBDataSolutions\Sdk\Client;
use KBDataSolutions\Sdk\Exception\AuthenticationException;
use KBDataSolutions\Sdk\Exception\ConflictException;
use KBDataSolutions\Sdk\Exception\RateLimitException;
use KBDataSolutions\Sdk\Exception\TransportException;
use KBDataSolutions\Sdk\Exception\ValidationException;
use MinoxWooCommerce\Sync\IntegrationSettings;
use MinoxWooCommerce\Sync\OrderSyncQueue;
use MinoxWooCommerce\Sync\OrderSyncService;
use MinoxWooCommerce\Sync\SyncOutcome;
use MinoxWooCommerce\Tests\Fakes\FakeOrder;
use MinoxWooCommerce\Tests\Fakes\FakeRefund;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OrderSyncQueueTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<array{hook: string, args: array<int, mixed>, delay: int}> */
    private array $scheduled = [];

    /** @var array<int, object> */
    private array $store = [];

    /** @var list<array<string, mixed>> */
    private array $queries = [];

    /** @var list<int>|null */
    private ?array $queryResult = null;

    private OrderSyncService&MockInterface $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->orders = Mockery::mock(OrderSyncService::class);

        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, mixed $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('as_has_scheduled_action')->alias(fn (string $hook, array $args = []): bool => array_filter(
            $this->scheduled,
            static fn (array $action): bool => $action['hook'] === $hook && $action['args'] === $args,
        ) !== []);
        Functions\when('as_schedule_single_action')->alias(function (int $timestamp, string $hook, array $args = []): int {
            $this->scheduled[] = ['hook' => $hook, 'args' => $args, 'delay' => $timestamp - time()];

            return 1;
        });
        Functions\when('as_unschedule_all_actions')->alias(function (string $hook, array $args = []): void {
            $this->scheduled = array_values(array_filter(
                $this->scheduled,
                static fn (array $action): bool => !($action['hook'] === $hook && $action['args'] === $args),
            ));
        });
        Functions\when('wc_get_order')->alias(fn (int $id): object|false => $this->store[$id] ?? false);
        Functions\when('wc_get_orders')->alias(function (array $args): array {
            $this->queries[] = $args;

            return $this->queryResult ?? [];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_enqueue_marks_the_order_as_owed_and_schedules_it(): void
    {
        $order = new FakeOrder(10);

        OrderSyncQueue::enqueueOrder($order);

        self::assertSame(OrderSyncQueue::STATUS_PENDING, $order->meta[OrderSyncQueue::META_STATUS]);
        self::assertSame([['hook' => OrderSyncQueue::ORDER_HOOK, 'args' => [10], 'delay' => 0]], $this->scheduled);
    }

    public function test_temporary_failures_are_retried_with_growing_delay_and_noted_once(): void
    {
        $order = $this->stored(new FakeOrder(10));
        $order->meta[OrderSyncQueue::META_STATUS] = OrderSyncQueue::STATUS_PENDING;
        $this->orders->expects('syncOrder')->with($order, Mockery::any(), true)->andReturn(SyncOutcome::failed('Order 10: Client authentication failed'));
        $this->orders->expects('syncOrder')->with($order, Mockery::any(), false)->andReturn(SyncOutcome::failed('Order 10: Client authentication failed'));

        $this->queue()->processOrder(10);
        $this->scheduled = [];
        $this->queue()->processOrder(10);

        self::assertSame(OrderSyncQueue::STATUS_RETRYING, $order->meta[OrderSyncQueue::META_STATUS]);
        self::assertSame('2', $order->meta[OrderSyncQueue::META_ATTEMPTS]);
        self::assertSame(OrderSyncQueue::BACKOFF[1], $this->scheduled[0]['delay']);
    }

    public function test_retries_never_give_up_and_cap_at_the_longest_delay(): void
    {
        $order = $this->stored(new FakeOrder(10));
        $order->meta[OrderSyncQueue::META_STATUS] = OrderSyncQueue::STATUS_RETRYING;
        $order->meta[OrderSyncQueue::META_ATTEMPTS] = '40';
        $this->orders->allows('syncOrder')->andReturn(SyncOutcome::failed('down'));

        $this->queue()->processOrder(10);

        self::assertSame(OrderSyncQueue::BACKOFF[count(OrderSyncQueue::BACKOFF) - 1], $this->scheduled[0]['delay']);
    }

    public function test_a_booked_order_is_cleared_and_its_open_refunds_are_queued(): void
    {
        $refund = new FakeRefund(20, 10);
        $order = $this->stored(new FakeOrder(10, 'processing', [$refund]));
        $order->meta[OrderSyncQueue::META_STATUS] = OrderSyncQueue::STATUS_RETRYING;
        $order->meta[OrderSyncQueue::META_ERROR] = 'down';
        $this->orders->allows('syncOrder')->andReturn(SyncOutcome::synced());

        $this->queue()->processOrder(10);

        self::assertArrayNotHasKey(OrderSyncQueue::META_STATUS, $order->meta);
        self::assertArrayNotHasKey(OrderSyncQueue::META_ERROR, $order->meta);
        self::assertSame(OrderSyncQueue::STATUS_PENDING, $refund->meta[OrderSyncQueue::META_STATUS]);
        self::assertContains(['hook' => OrderSyncQueue::REFUND_HOOK, 'args' => [10, 20], 'delay' => 0], $this->scheduled);
    }

    public function test_invalid_data_is_parked_for_a_human_and_not_retried(): void
    {
        $order = $this->stored(new FakeOrder(10));
        $this->orders->allows('syncOrder')->andReturn(SyncOutcome::rejected('Order 10: invalid VAT'));

        $this->queue()->processOrder(10);

        self::assertSame(OrderSyncQueue::STATUS_FAILED, $order->meta[OrderSyncQueue::META_STATUS]);
        self::assertSame('Order 10: invalid VAT', $order->meta[OrderSyncQueue::META_ERROR]);
        self::assertSame([], $this->scheduled);
    }

    public function test_an_order_cancelled_before_the_retry_is_not_sent(): void
    {
        $order = $this->stored(new FakeOrder(10, 'cancelled'));
        $order->meta[OrderSyncQueue::META_STATUS] = OrderSyncQueue::STATUS_RETRYING;
        $this->orders->expects('syncOrder')->never();

        $this->queue()->processOrder(10);

        self::assertArrayNotHasKey(OrderSyncQueue::META_STATUS, $order->meta);
        self::assertStringContainsString('cancelled', $order->notes[0]);
    }

    public function test_a_refund_waits_for_its_order_and_follows_a_parked_order(): void
    {
        $order = $this->stored(new FakeOrder(10));
        $refund = $this->stored(new FakeRefund(20, 10));
        $this->orders->expects('syncOrderRefund')->never();

        $this->queue()->processRefund(10, 20);
        self::assertSame(OrderSyncQueue::STATUS_RETRYING, $refund->meta[OrderSyncQueue::META_STATUS]);
        self::assertSame(['hook' => OrderSyncQueue::REFUND_HOOK, 'args' => [10, 20], 'delay' => OrderSyncQueue::BACKOFF[0]], $this->scheduled[0]);

        $order->meta[OrderSyncQueue::META_STATUS] = OrderSyncQueue::STATUS_FAILED;
        $this->queue()->processRefund(10, 20);
        self::assertSame(OrderSyncQueue::STATUS_FAILED, $refund->meta[OrderSyncQueue::META_STATUS]);
    }

    public function test_reconcile_requeues_owed_orders_and_only_looks_back_to_go_live(): void
    {
        $this->options[OrderSyncQueue::LIVE_SINCE_OPTION] = time() - 3600;
        $missed = $this->stored(new FakeOrder(11));
        $this->stored(new FakeOrder(10));
        $this->queryResult = [10];
        Functions\when('wc_get_orders')->alias(function (array $args): array {
            $this->queries[] = $args;

            return match (count($this->queries)) {
                1 => [10],
                3 => [11],
                default => [],
            };
        });

        $this->queue()->reconcile();

        self::assertContains(['hook' => OrderSyncQueue::ORDER_HOOK, 'args' => [10], 'delay' => 0], $this->scheduled);
        self::assertContains(['hook' => OrderSyncQueue::ORDER_HOOK, 'args' => [11], 'delay' => 0], $this->scheduled);
        self::assertSame(OrderSyncQueue::STATUS_PENDING, $missed->meta[OrderSyncQueue::META_STATUS]);
        self::assertSame('>'.$this->options[OrderSyncQueue::LIVE_SINCE_OPTION], $this->queries[2]['date_created']);
        self::assertSame(['wc-processing', 'wc-completed', 'wc-refunded'], $this->queries[2]['status']);
    }

    public function test_retry_all_resets_failed_orders_and_sends_them_now(): void
    {
        $order = $this->stored(new FakeOrder(10));
        $order->meta[OrderSyncQueue::META_STATUS] = OrderSyncQueue::STATUS_FAILED;
        $order->meta[OrderSyncQueue::META_ATTEMPTS] = '6';
        $this->scheduled[] = ['hook' => OrderSyncQueue::ORDER_HOOK, 'args' => [10], 'delay' => 86400];
        Functions\when('wc_get_orders')->alias(fn (array $args): array => $args['type'] === 'shop_order' ? [10] : []);

        self::assertSame(1, $this->queue()->retryAll());

        self::assertSame(OrderSyncQueue::STATUS_PENDING, $order->meta[OrderSyncQueue::META_STATUS]);
        self::assertSame('0', $order->meta[OrderSyncQueue::META_ATTEMPTS]);
        self::assertSame([['hook' => OrderSyncQueue::ORDER_HOOK, 'args' => [10], 'delay' => 0]], $this->scheduled);
    }

    public function test_failures_are_classified_as_retryable_or_needing_a_human(): void
    {
        self::assertTrue(SyncOutcome::fromException(new AuthenticationException('Client authentication failed'), 'Order 1')->retryable);
        self::assertTrue(SyncOutcome::fromException(new TransportException('timeout'), 'Order 1')->retryable);
        self::assertSame(120, SyncOutcome::fromException(new RateLimitException('slow down', 120), 'Order 1')->retryAfterSeconds);
        self::assertTrue(SyncOutcome::fromException(new ConflictException('busy', 'INVOICE_SUBMISSION_IN_PROGRESS'), 'Order 1')->retryable);
        self::assertFalse(SyncOutcome::fromException(new ConflictException('changed', 'INVOICE_ALREADY_SUBMITTED'), 'Order 1')->retryable);
        self::assertFalse(SyncOutcome::fromException(new ValidationException('invalid', []), 'Order 1')->retryable);
        self::assertFalse(SyncOutcome::fromException(new RuntimeException('mapper bug'), 'Order 1')->retryable);
    }

    /**
     * @template T of object
     * @param T $object
     * @return T
     */
    private function stored(object $object): object
    {
        $this->store[$object->get_id()] = $object;

        return $object;
    }

    private function queue(): OrderSyncQueue
    {
        return new OrderSyncQueue(new class implements IntegrationSettings {
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
                return null;
            }
        }, $this->orders);
    }
}
