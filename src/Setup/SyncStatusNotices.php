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
            echo '<div class="notice notice-error"><p><strong>'.esc_html__('Minox Koppeling verstuurt niets: de ingestelde server-URL is onveilig.', 'kb-data-koppeling-voor-woocommerce').'</strong> '
                .esc_html__('KBDATA_KOPPELING_BASE_URI in wp-config.php moet met https:// beginnen (http:// mag alleen voor localhost). Orders blijven bewaard en worden verstuurd zodra dit is opgelost.', 'kb-data-koppeling-voor-woocommerce')
                .'</p></div>';
        }

        $authError = ConnectionHealth::authError();
        $counts = (new OrderSyncQueue(Integration::instance()))->counts();
        $link = '<a href="'.esc_url(WizardPage::settingsUrl()).'">'.esc_html__('Bekijk de Minox Koppeling', 'kb-data-koppeling-voor-woocommerce').'</a>';

        if ($authError !== null) {
            echo '<div class="notice notice-error"><p><strong>'.esc_html__('Minox Koppeling kan geen verbinding maken met KB Data.', 'kb-data-koppeling-voor-woocommerce').'</strong> '
                .esc_html(sprintf(
                    __('Melding: %s. Controleer de client ID en het secret. Orders blijven bewaard en worden automatisch verstuurd zodra de verbinding weer werkt.', 'kb-data-koppeling-voor-woocommerce'),
                    $authError,
                )).' '.$link.'</p></div>';
        }

        if ($counts['failed'] > 0) {
            echo '<div class="notice notice-error"><p>'.esc_html(sprintf(
                _n('%d order of terugbetaling kon niet naar Minox worden verstuurd en heeft aandacht nodig.', '%d orders of terugbetalingen konden niet naar Minox worden verstuurd en hebben aandacht nodig.', $counts['failed'], 'kb-data-koppeling-voor-woocommerce'),
                $counts['failed'],
            )).' '.$link.'</p></div>';
        }

        if ($authError === null && $counts['waiting'] > 0) {
            echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
                _n('%d order of terugbetaling wacht op verzending naar Minox; de koppeling probeert het automatisch opnieuw.', '%d orders of terugbetalingen wachten op verzending naar Minox; de koppeling probeert het automatisch opnieuw.', $counts['waiting'], 'kb-data-koppeling-voor-woocommerce'),
                $counts['waiting'],
            )).' '.$link.'</p></div>';
        }
    }

    public static function handleRetryAll(): void
    {
        check_admin_referer(self::RETRY_NONCE_ACTION);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Geen toegang.', 'kb-data-koppeling-voor-woocommerce'));
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
            $actions[self::ORDER_ACTION] = __('Opnieuw naar Minox sturen', 'kb-data-koppeling-voor-woocommerce');
        }

        return $actions;
    }

    public static function retryOrder(WC_Order $order): void
    {
        OrderSyncQueue::retryOrder($order);
        $order->add_order_note(__('Minox Koppeling: handmatig opnieuw ingepland voor verzending naar Minox.', 'kb-data-koppeling-voor-woocommerce'));
    }

    private static function onSettingsPage(): bool
    {
        return isset($_GET['page']) && $_GET['page'] === 'minox-koppeling-setup';
    }
}
