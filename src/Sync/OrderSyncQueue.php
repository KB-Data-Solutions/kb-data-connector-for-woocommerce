<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use MinoxWooCommerce\Integration;
use WC_Order;
use WC_Order_Refund;

final class OrderSyncQueue
{
    public const string ORDER_HOOK = 'minox_wc_sync_order';
    public const string REFUND_HOOK = 'minox_wc_sync_refund';
    public const string RECONCILE_HOOK = 'minox_wc_reconcile';
    public const string META_STATUS = '_minox_sync_status';
    public const string META_ATTEMPTS = '_minox_sync_attempts';
    public const string META_ERROR = '_minox_sync_error';
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_RETRYING = 'retrying';
    public const string STATUS_FAILED = 'failed';
    public const string LIVE_SINCE_OPTION = 'minox_wc_live_since';
    public const int RECONCILE_LIMIT = 100;
    public const int LOOKBACK_DAYS = 90;

    public const array BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

    private const string GROUP = 'minox-commerce';
    private const int RECONCILE_INTERVAL = 3600;

    public function __construct(
        private readonly IntegrationSettings $integration,
        private readonly OrderSyncService $orders = new OrderSyncService(),
    ) {
    }

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'ensureScheduled'], 40);
        add_action(self::ORDER_HOOK, [self::class, 'handleOrder'], 10, 1);
        add_action(self::REFUND_HOOK, [self::class, 'handleRefund'], 10, 2);
        add_action(self::RECONCILE_HOOK, [self::class, 'handleReconcile']);
    }

    public static function ensureScheduled(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        $scheduled = as_has_scheduled_action(self::RECONCILE_HOOK, [], self::GROUP);

        if (!Integration::instance()->isConfigured()) {
            if ($scheduled && function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::RECONCILE_HOOK, [], self::GROUP);
            }

            return;
        }

        self::liveSince();

        if (!$scheduled) {
            as_schedule_recurring_action(time() + 300, self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, [], self::GROUP, true);
        }
    }

    public static function liveSince(): int
    {
        $since = get_option(self::LIVE_SINCE_OPTION);

        if (is_numeric($since) && (int) $since > 0) {
            return (int) $since;
        }

        $now = time();
        update_option(self::LIVE_SINCE_OPTION, $now, false);

        return $now;
    }

    public static function enqueueOrder(WC_Order $order): void
    {
        if ($order->get_meta(OrderSyncService::META_INVOICE_ID) !== '') {
            return;
        }

        self::markPending($order);
        self::schedule(self::ORDER_HOOK, [$order->get_id()], 0);
    }

    public static function enqueueRefund(int $orderId, WC_Order_Refund $refund): void
    {
        if ($refund->get_meta(OrderSyncService::META_INVOICE_ID) !== '') {
            return;
        }

        self::markPending($refund);
        self::schedule(self::REFUND_HOOK, [$orderId, $refund->get_id()], 0);
    }

    public static function handleOrder(int $orderId): void
    {
        (new self(Integration::instance()))->processOrder($orderId);
    }

    public static function handleRefund(int $orderId, int $refundId): void
    {
        (new self(Integration::instance()))->processRefund($orderId, $refundId);
    }

    public static function handleReconcile(): void
    {
        (new self(Integration::instance()))->reconcile();
    }

    public function processOrder(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        if ($order->get_meta(OrderSyncService::META_INVOICE_ID) !== '') {
            self::clear($order);

            return;
        }

        if (!$this->integration->isConfigured()) {
            return;
        }

        if (!in_array($order->get_status(), $this->eligibleStatuses(), true)) {
            self::clear($order);
            $order->add_order_note(sprintf(
                /* translators: %s: order status */
                __('Minox Connector: not sent to Minox, the order now has the status "%s".', 'kb-data-connector-for-woocommerce'),
                $order->get_status(),
            ));

            return;
        }

        $attempts = (int) $order->get_meta(self::META_ATTEMPTS);
        $outcome = $this->orders->syncOrder($order, $this->integration, $attempts === 0);

        if ($outcome->isBooked()) {
            self::clear($order);
            $this->queueOpenRefunds($order);

            return;
        }

        $this->handleFailure($order, $outcome, $attempts, self::ORDER_HOOK, [$orderId]);
    }

    public function processRefund(int $orderId, int $refundId): void
    {
        $order = wc_get_order($orderId);
        $refund = wc_get_order($refundId);

        if (!$order instanceof WC_Order || !$refund instanceof WC_Order_Refund) {
            return;
        }

        if ($refund->get_meta(OrderSyncService::META_INVOICE_ID) !== '' || !$this->integration->syncRefunds()) {
            self::clear($refund);

            return;
        }

        if (!$this->integration->isConfigured()) {
            return;
        }

        $attempts = (int) $refund->get_meta(self::META_ATTEMPTS);

        if ($order->get_meta(OrderSyncService::META_INVOICE_ID) === '') {
            if ($order->get_meta(self::META_STATUS) === self::STATUS_FAILED) {
                self::park($refund, __('The order itself could not be sent to Minox.', 'kb-data-connector-for-woocommerce'));

                return;
            }

            $this->handleFailure($refund, SyncOutcome::failed(__('Waiting until the order itself is in Minox.', 'kb-data-connector-for-woocommerce')), $attempts, self::REFUND_HOOK, [$orderId, $refundId]);

            return;
        }

        $outcome = $this->orders->syncOrderRefund($order, $refund, $this->integration, $attempts === 0);

        if ($outcome->isBooked()) {
            self::clear($refund);

            return;
        }

        $this->handleFailure($refund, $outcome, $attempts, self::REFUND_HOOK, [$orderId, $refundId]);
    }

    public function reconcile(): void
    {
        if (!$this->integration->isConfigured()) {
            return;
        }

        foreach ($this->owedIds('shop_order') as $orderId) {
            self::schedule(self::ORDER_HOOK, [$orderId], 0);
        }

        foreach ($this->owedIds('shop_order_refund') as $refundId) {
            $refund = wc_get_order($refundId);
            if ($refund instanceof WC_Order_Refund) {
                self::schedule(self::REFUND_HOOK, [$refund->get_parent_id(), $refundId], 0);
            }
        }

        foreach ($this->missedOrderIds() as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order instanceof WC_Order) {
                continue;
            }
            $order->add_order_note(__('Minox Connector: this order had not been sent to Minox yet and has now been scheduled.', 'kb-data-connector-for-woocommerce'));
            self::enqueueOrder($order);
        }
    }

    public function retryAll(): int
    {
        $count = 0;

        foreach ($this->idsWithStatus('shop_order', [self::STATUS_PENDING, self::STATUS_RETRYING, self::STATUS_FAILED]) as $orderId) {
            $order = wc_get_order($orderId);
            if ($order instanceof WC_Order && self::retryNow($order, self::ORDER_HOOK, [$orderId])) {
                $count++;
            }
        }

        foreach ($this->idsWithStatus('shop_order_refund', [self::STATUS_PENDING, self::STATUS_RETRYING, self::STATUS_FAILED]) as $refundId) {
            $refund = wc_get_order($refundId);
            if ($refund instanceof WC_Order_Refund && self::retryNow($refund, self::REFUND_HOOK, [$refund->get_parent_id(), $refundId])) {
                $count++;
            }
        }

        return $count;
    }

    public static function retryOrder(WC_Order $order): void
    {
        if ($order->get_meta(OrderSyncService::META_INVOICE_ID) === '') {
            self::retryNow($order, self::ORDER_HOOK, [$order->get_id()]);
        }

        foreach ($order->get_refunds() as $refund) {
            if ($refund instanceof WC_Order_Refund && $refund->get_meta(self::META_STATUS) !== '') {
                self::retryNow($refund, self::REFUND_HOOK, [$order->get_id(), $refund->get_id()]);
            }
        }
    }

    /** @return array{waiting: int, failed: int} */
    public function counts(): array
    {
        $waiting = 0;
        $failed = 0;

        foreach (['shop_order', 'shop_order_refund'] as $type) {
            $waiting += $this->countWithStatus($type, [self::STATUS_PENDING, self::STATUS_RETRYING]);
            $failed += $this->countWithStatus($type, [self::STATUS_FAILED]);
        }

        return ['waiting' => $waiting, 'failed' => $failed];
    }

    /** @return list<WC_Order> orders that are retrying or failed, for the settings page */
    public function problemOrders(int $limit = 20): array
    {
        $orders = [];

        foreach ($this->idsWithStatus('shop_order', [self::STATUS_RETRYING, self::STATUS_FAILED], $limit) as $orderId) {
            $order = wc_get_order($orderId);
            if ($order instanceof WC_Order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /** @return list<string> WooCommerce statuses (without "wc-") an order may be sent in */
    public function eligibleStatuses(): array
    {
        $statuses = $this->integration->syncTriggerStatus() === 'completed' ? ['completed'] : ['processing', 'completed'];

        if ($this->integration->syncRefunds()) {
            $statuses[] = 'refunded';
        }

        return $statuses;
    }

    /**
     * @param WC_Order|WC_Order_Refund $object
     * @param array<int, int> $args
     */
    private function handleFailure(WC_Order|WC_Order_Refund $object, SyncOutcome $outcome, int $attempts, string $hook, array $args): void
    {
        if ($outcome->status === SyncOutcome::SKIPPED) {
            return;
        }

        if (!$outcome->retryable) {
            self::park($object, (string) $outcome->message);

            return;
        }

        $object->update_meta_data(self::META_STATUS, self::STATUS_RETRYING);
        $object->update_meta_data(self::META_ATTEMPTS, (string) ($attempts + 1));
        $object->update_meta_data(self::META_ERROR, (string) $outcome->message);
        $object->save_meta_data();

        $delay = $outcome->retryAfterSeconds ?? self::BACKOFF[min($attempts, count(self::BACKOFF) - 1)];
        self::schedule($hook, $args, max(1, $delay));
    }

    private function queueOpenRefunds(WC_Order $order): void
    {
        if (!$this->integration->syncRefunds()) {
            return;
        }

        foreach ($order->get_refunds() as $refund) {
            if ($refund instanceof WC_Order_Refund && $refund->get_meta(OrderSyncService::META_INVOICE_ID) === '') {
                self::enqueueRefund($order->get_id(), $refund);
            }
        }
    }

    /** @return list<int> */
    private function owedIds(string $type): array
    {
        return $this->idsWithStatus($type, [self::STATUS_PENDING, self::STATUS_RETRYING]);
    }

    /** @return list<int> */
    private function missedOrderIds(): array
    {
        $since = max(self::liveSince(), time() - self::LOOKBACK_DAYS * 86400);

        return self::ids(wc_get_orders([
            'type' => 'shop_order',
            'status' => array_map(static fn (string $status): string => 'wc-'.$status, $this->eligibleStatuses()),
            'date_created' => '>'.$since,
            'limit' => self::RECONCILE_LIMIT,
            'return' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => OrderSyncService::META_INVOICE_ID, 'compare' => 'NOT EXISTS'],
                ['key' => self::META_STATUS, 'compare' => 'NOT EXISTS'],
            ],
        ]));
    }

    /**
     * @param list<string> $statuses
     * @return list<int>
     */
    private function idsWithStatus(string $type, array $statuses, int $limit = self::RECONCILE_LIMIT): array
    {
        return self::ids(wc_get_orders([
            'type' => $type,
            'limit' => $limit,
            'return' => 'ids',
            'meta_query' => [['key' => self::META_STATUS, 'value' => $statuses, 'compare' => 'IN']],
        ]));
    }

    /** @param list<string> $statuses */
    private function countWithStatus(string $type, array $statuses): int
    {
        $result = wc_get_orders([
            'type' => $type,
            'limit' => 1,
            'return' => 'ids',
            'paginate' => true,
            'meta_query' => [['key' => self::META_STATUS, 'value' => $statuses, 'compare' => 'IN']],
        ]);

        return is_object($result) && isset($result->total) ? (int) $result->total : 0;
    }

    /** @return list<int> */
    private static function ids(mixed $result): array
    {
        return is_array($result) ? array_values(array_map('intval', array_filter($result, 'is_numeric'))) : [];
    }

    private static function markPending(WC_Order|WC_Order_Refund $object): void
    {
        $object->update_meta_data(self::META_STATUS, self::STATUS_PENDING);
        $object->update_meta_data(self::META_ATTEMPTS, '0');
        $object->delete_meta_data(self::META_ERROR);
        $object->save_meta_data();
    }

    public static function takeOver(WC_Order|WC_Order_Refund $object, SyncOutcome $outcome, int $orderId): void
    {
        if (!$outcome->retryable) {
            self::park($object, (string) $outcome->message);

            return;
        }

        $object instanceof WC_Order_Refund ? self::enqueueRefund($orderId, $object) : self::enqueueOrder($object);
    }

    private static function park(WC_Order|WC_Order_Refund $object, string $message): void
    {
        $object->update_meta_data(self::META_STATUS, self::STATUS_FAILED);
        $object->update_meta_data(self::META_ERROR, $message);
        $object->save_meta_data();
    }

    private static function clear(WC_Order|WC_Order_Refund $object): void
    {
        if ($object->get_meta(self::META_STATUS) === '') {
            return;
        }

        $object->delete_meta_data(self::META_STATUS);
        $object->delete_meta_data(self::META_ATTEMPTS);
        $object->delete_meta_data(self::META_ERROR);
        $object->save_meta_data();
    }

    /** @param array<int, int> $args */
    private static function retryNow(WC_Order|WC_Order_Refund $object, string $hook, array $args): bool
    {
        if ($object->get_meta(OrderSyncService::META_INVOICE_ID) !== '') {
            return false;
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, $args, self::GROUP);
        }

        self::markPending($object);
        self::schedule($hook, $args, 0);

        return true;
    }

    /** @param array<int, int> $args */
    private static function schedule(string $hook, array $args, int $delay): void
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_has_scheduled_action')) {
            if ($delay === 0) {
                do_action($hook, ...$args);
            }

            return;
        }

        if (as_has_scheduled_action($hook, $args, self::GROUP)) {
            return;
        }

        as_schedule_single_action(time() + $delay, $hook, $args, self::GROUP, true);
    }
}
