<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Setup;

use MinoxWooCommerce\Integration;
use MinoxWooCommerce\Sync\ConnectionHealth;
use MinoxWooCommerce\Sync\OrderSyncQueue;
use MinoxWooCommerce\Sync\OrderSyncService;
use WC_Order;

final class SyncStatusNotices
{
    public const string RETRY_NONCE_ACTION = 'minox_wc_retry_sync';
    private const string ORDER_ACTION = 'minox_wc_resync';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_action('admin_post_minox_wc_retry_sync', [self::class, 'handleRetryAll']);
        add_filter('woocommerce_order_actions', [self::class, 'orderActions'], 10, 2);
        add_action('woocommerce_order_action_'.self::ORDER_ACTION, [self::class, 'retryOrder']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce') || !Integration::instance()->isConfigured() || self::onSettingsPage()) {
            return;
        }

        if (Integration::hasUnsafeBaseUriOverride()) {
            echo '<div class="notice notice-error"><p><strong>'.esc_html__('Minox Connector is not sending anything: the configured server URL is not secure.', 'kb-data-connector-for-woocommerce').'</strong> '
                .esc_html__('KBDATA_KOPPELING_BASE_URI in wp-config.php must start with https:// (http:// is only allowed for localhost). Orders are kept and sent once this is fixed.', 'kb-data-connector-for-woocommerce')
                .'</p></div>';
        }

        $authError = ConnectionHealth::authError();
        $counts = (new OrderSyncQueue(Integration::instance()))->counts();
        $link = '<a href="'.esc_url(WizardPage::settingsUrl()).'">'.esc_html__('View the Minox Connector', 'kb-data-connector-for-woocommerce').'</a>';

        if ($authError !== null) {
            echo '<div class="notice notice-error"><p><strong>'.esc_html__('Minox Connector cannot connect to KB Data.', 'kb-data-connector-for-woocommerce').'</strong> '
                .esc_html(sprintf(
                    /* translators: %s: error message from KB Data */
                    __('Message: %s. Check the client ID and secret. Orders are kept and sent automatically as soon as the connection works again.', 'kb-data-connector-for-woocommerce'),
                    $authError,
                )).' '.$link.'</p></div>';
        }

        if ($counts['failed'] > 0) {
            echo '<div class="notice notice-error"><p>'.esc_html(sprintf(
                /* translators: %d: number of orders and refunds */
                _n('%d order or refund could not be sent to Minox and needs attention.', '%d orders or refunds could not be sent to Minox and need attention.', $counts['failed'], 'kb-data-connector-for-woocommerce'),
                $counts['failed'],
            )).' '.$link.'</p></div>';
        }

        if ($authError === null && $counts['waiting'] > 0) {
            echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
                /* translators: %d: number of orders and refunds */
                _n('%d order or refund is waiting to be sent to Minox; the connector retries automatically.', '%d orders or refunds are waiting to be sent to Minox; the connector retries automatically.', $counts['waiting'], 'kb-data-connector-for-woocommerce'),
                $counts['waiting'],
            )).' '.$link.'</p></div>';
        }
    }

    public static function handleRetryAll(): void
    {
        check_admin_referer(self::RETRY_NONCE_ACTION);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'kb-data-connector-for-woocommerce'));
        }

        ConnectionHealth::clearAuthError();
        $count = (new OrderSyncQueue(Integration::instance()))->retryAll();

        wp_safe_redirect(add_query_arg(['sync_retry' => $count], WizardPage::settingsUrl()));
        exit;
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function orderActions(array $actions, mixed $order = null): array
    {
        if (!$order instanceof WC_Order || !Integration::instance()->isConfigured()) {
            return $actions;
        }

        $eligible = in_array($order->get_status(), (new OrderSyncQueue(Integration::instance()))->eligibleStatuses(), true);
        $needsResend = $order->get_meta(OrderSyncQueue::META_STATUS) !== ''
            || ($eligible && $order->get_meta(OrderSyncService::META_INVOICE_ID) === '');

        if ($needsResend) {
            $actions[self::ORDER_ACTION] = __('Send to Minox again', 'kb-data-connector-for-woocommerce');
        }

        return $actions;
    }

    public static function retryOrder(WC_Order $order): void
    {
        OrderSyncQueue::retryOrder($order);
        $order->add_order_note(__('Minox Connector: manually rescheduled for sending to Minox.', 'kb-data-connector-for-woocommerce'));
    }

    private static function onSettingsPage(): bool
    {
        return isset($_GET['page']) && $_GET['page'] === 'minox-koppeling-setup';
    }
}
