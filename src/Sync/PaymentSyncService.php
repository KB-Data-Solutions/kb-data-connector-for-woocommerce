<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use DateTimeImmutable;
use DateTimeZone;
use KBDataSolutions\Sdk\Invoice\DecimalAmount;
use KBDataSolutions\Sdk\Payment\Request\CreatePaymentRequest;
use MinoxWooCommerce\Integration;
use RuntimeException;
use Throwable;
use WC_Order;

final class PaymentSyncService
{
    private const string HOOK = 'minox_wc_sync_confirmed_payment';
    private const string GROUP = 'minox-commerce';
    private const string META_PAYMENT_ID = '_minox_payment_id';
    private const string META_SNAPSHOT = '_minox_payment_snapshot';
    private const string META_CASH_CONFIRMED = '_minox_cash_confirmed_at';

    public static function register(): void
    {
        add_action('woocommerce_payment_complete', [self::class, 'schedule'], 20, 1);
        add_action(self::HOOK, [self::class, 'run'], 10, 2);
        add_filter('woocommerce_order_actions', [self::class, 'orderActions'], 10, 2);
        add_action('woocommerce_order_action_minox_confirm_cash_payment', [self::class, 'confirmCashPayment']);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function orderActions(array $actions, ?WC_Order $order = null): array
    {
        if ($order?->get_payment_method() === 'cod' && $order->get_meta(self::META_PAYMENT_ID) === '') {
            $actions['minox_confirm_cash_payment'] = __('Confirm full cash receipt (Minox)', 'kb-data-connector-for-woocommerce');
        }
        return $actions;
    }

    public static function confirmCashPayment(WC_Order $order): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to confirm a cash receipt.', 'kb-data-connector-for-woocommerce'), '', ['response' => 403]);
        }
        if ($order->get_payment_method() !== 'cod' || $order->get_meta(self::META_PAYMENT_ID) !== '') {
            return;
        }
        if ($order->get_meta(self::META_CASH_CONFIRMED) === '') {
            $order->update_meta_data(self::META_CASH_CONFIRMED, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM));
            $order->update_meta_data('_minox_cash_confirmed_by', (string) get_current_user_id());
            $order->save_meta_data();
            $order->add_order_note(__('Minox Connector: full cash receipt confirmed manually. The payment processing configured in the portal remains leading.', 'kb-data-connector-for-woocommerce'));
        }
        self::schedule($order->get_id());
    }

    public static function schedule(int $orderId): void
    {
        if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action') && !as_has_scheduled_action(self::HOOK, [$orderId, 0], self::GROUP)) {
            as_schedule_single_action(time() + 1, self::HOOK, [$orderId, 0], self::GROUP, true);
        }
    }

    public static function run(int $orderId, int $attempt = 0): void
    {
        $order = wc_get_order($orderId);
        $integration = Integration::instance();
        if (!$order instanceof WC_Order || !$integration->isConfigured()) {
            return;
        }
        try {
            (new self())->sync($order, $integration);
        } catch (Throwable) {
            if ($attempt < 5 && function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + min(3600, 60 * (2 ** $attempt)), self::HOOK, [$orderId, $attempt + 1], self::GROUP, true);
            } else {
                $order->add_order_note(__('Minox Connector: the payment confirmation could not be sent yet. Check the connector; the invoice was not booked again.', 'kb-data-connector-for-woocommerce'));
            }
        }
    }

    public function sync(WC_Order $order, IntegrationSettings $integration): void
    {
        $paidAt = $order->get_date_paid();
        $cashConfirmed = $order->get_meta(self::META_CASH_CONFIRMED);
        $manualCash = $order->get_payment_method() === 'cod' && is_string($cashConfirmed) && $cashConfirmed !== '';
        if ($order->get_meta(self::META_PAYMENT_ID) !== ''
            || (!$manualCash && (!$order->is_paid() || in_array($order->get_payment_method(), ['bacs', 'cod', 'cheque'], true)
                || $paidAt === null || $order->get_transaction_id() === ''))) {
            return;
        }
        if ($order->get_meta(OrderSyncService::META_INVOICE_ID) === '') {
            throw new RuntimeException('The invoice must be accepted before reporting its payment.');
        }
        $snapshot = $order->get_meta(self::META_SNAPSHOT);
        if ($snapshot === '') {
            $date = $manualCash ? new DateTimeImmutable($cashConfirmed) : ($paidAt === null ? null : new DateTimeImmutable($paidAt->format(DATE_ATOM)));
            if ($date === null) {
                return;
            }
            $transaction = $manualCash ? 'cash-wc-order-'.$order->get_id() : $order->get_transaction_id();
            $request = new CreatePaymentRequest(
                'wc-'.$order->get_id().'-'.substr(hash('sha256', $order->get_payment_method().'|'.$transaction), 0, 32),
                'wc-order-'.$order->get_id(),
                $order->get_payment_method(),
                $transaction,
                new DecimalAmount((string) $order->get_total()),
                $date,
            );
            $snapshot = json_encode($request->toArray(), JSON_THROW_ON_ERROR);
            $order->update_meta_data(self::META_SNAPSHOT, $snapshot);
            $order->save_meta_data();
        } else {
            $data = is_string($snapshot) ? json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
            if (!is_array($data)) {
                throw new RuntimeException('Invalid persisted payment snapshot.');
            }
            foreach (['external_payment_id', 'external_invoice_id', 'payment_method', 'transaction_reference', 'amount', 'paid_at'] as $key) {
                if (!isset($data[$key]) || !is_string($data[$key])) {
                    throw new RuntimeException('Incomplete persisted payment snapshot.');
                }
            }
            $request = new CreatePaymentRequest(
                $data['external_payment_id'],
                $data['external_invoice_id'],
                $data['payment_method'],
                $data['transaction_reference'],
                new DecimalAmount($data['amount']),
                new DateTimeImmutable($data['paid_at'])
            );
        }

        $client = $integration->makeClient();
        if ($client === null) {
            return;
        }
        $payment = $client->payments()->create($request, 'payment-'.$request->externalPaymentId);
        $order->update_meta_data(self::META_PAYMENT_ID, $payment->id);
        $order->save_meta_data();
        $order->add_order_note(__('Minox Connector: payment confirmation sent separately. Processing can be followed in the portal.', 'kb-data-connector-for-woocommerce'));
    }
}
