<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use KBDataSolutions\Sdk\Exception\AuthenticationException;
use KBDataSolutions\Sdk\Invoice\Request\CreateInvoiceRequest;
use MinoxWooCommerce\Integration;
use Throwable;
use WC_Order;
use WC_Order_Refund;

class OrderSyncService
{
    public const string META_INVOICE_ID = '_minox_invoice_id';

    private const string META_DEBTOR_ID = '_minox_debtor_id';

    public static function register(): void
    {
        add_action('woocommerce_order_status_changed', [self::class, 'handleStatusChange'], 10, 3);
        add_action('woocommerce_order_refunded', [self::class, 'handleRefund'], 10, 2);
    }

    public static function handleStatusChange(int $orderId, string $from, string $to): void
    {
        $integration = Integration::instance();

        if (!$integration->isConfigured() || $to !== $integration->syncTriggerStatus()) {
            return;
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        OrderSyncQueue::enqueueOrder($order);
    }

    public static function handleRefund(int $orderId, int $refundId): void
    {
        $integration = Integration::instance();

        if (!$integration->isConfigured() || !$integration->syncRefunds()) {
            return;
        }

        $refund = wc_get_order($refundId);

        if (!$refund instanceof WC_Order_Refund) {
            return;
        }

        OrderSyncQueue::enqueueRefund($orderId, $refund);
    }

    public function syncOrder(WC_Order $order, IntegrationSettings $integration, bool $noteFailure = true): SyncOutcome
    {
        if ($order->get_meta(self::META_INVOICE_ID) !== '') {
            PaymentSyncService::schedule($order->get_id());
            return SyncOutcome::alreadySynced();
        }

        $client = $integration->makeClient();

        if ($client === null) {
            return SyncOutcome::skipped();
        }

        try {
            $mapper = new OrderMapper(new VatNumberResolver($integration->vatNumberMetaKey()));
            $document = $integration->attachPdfInvoice() ? (new InvoicePdfResolver())->resolve($order) : null;
            $invoiceRequest = $mapper->toInvoiceRequest($order, $document);
            $idempotencyKey = self::idempotencyKey('wc-order-'.$order->get_id(), $invoiceRequest);

            $debtor = $client->debtors()->create($invoiceRequest->debtor, $idempotencyKey.'-debtor');
            $order->update_meta_data(self::META_DEBTOR_ID, $debtor->id);

            $invoice = $client->invoices()->create($invoiceRequest, $idempotencyKey.'-invoice');
            $order->update_meta_data(self::META_INVOICE_ID, $invoice->id);
            $order->save_meta_data();
            ConnectionHealth::recordSuccess();
            PaymentSyncService::schedule($order->get_id());

            $order->add_order_note(sprintf(
                'Minox Koppeling: klant en factuur verzonden naar Minox (status: %s).',
                $invoice->rawStatus,
            ));

            return SyncOutcome::synced();
        } catch (Throwable $exception) {
            $outcome = self::classify($exception, sprintf('Order %d', $order->get_id()));

            if ($noteFailure) {
                $order->add_order_note('Minox Koppeling: synchronisatie mislukt: '.self::noteText($exception, $outcome));
            }

            return $outcome;
        }
    }

    public function syncOrderRefund(WC_Order $order, WC_Order_Refund $refund, IntegrationSettings $integration, bool $noteFailure = true): SyncOutcome
    {
        if ($order->get_meta(self::META_INVOICE_ID) === '') {
            return SyncOutcome::skipped();
        }

        if ($refund->get_meta(self::META_INVOICE_ID) !== '') {
            return SyncOutcome::alreadySynced();
        }

        $client = $integration->makeClient();

        if ($client === null) {
            return SyncOutcome::skipped();
        }

        try {
            $mapper = new OrderMapper(new VatNumberResolver($integration->vatNumberMetaKey()));
            $document = $integration->attachPdfInvoice() ? (new InvoicePdfResolver())->resolveCreditNote($order, $refund) : null;
            $invoiceRequest = $mapper->toRefundInvoiceRequest($order, $refund, $document);
            $idempotencyKey = self::idempotencyKey('wc-order-'.$order->get_id().'-refund-'.$refund->get_id(), $invoiceRequest);

            $debtor = $client->debtors()->create($invoiceRequest->debtor, $idempotencyKey.'-debtor');
            $refund->update_meta_data(self::META_DEBTOR_ID, $debtor->id);

            $invoice = $client->invoices()->create($invoiceRequest, $idempotencyKey.'-invoice');
            $refund->update_meta_data(self::META_INVOICE_ID, $invoice->id);
            $refund->save_meta_data();
            ConnectionHealth::recordSuccess();

            $order->add_order_note(sprintf(
                'Minox Koppeling: creditfactuur voor terugbetaling verzonden naar Minox (status: %s).',
                $invoice->rawStatus,
            ));

            return SyncOutcome::synced();
        } catch (Throwable $exception) {
            $outcome = self::classify($exception, sprintf('Terugbetaling %d (order %d)', $refund->get_id(), $order->get_id()));

            if ($noteFailure) {
                $order->add_order_note('Minox Koppeling: synchronisatie van terugbetaling mislukt: '.self::noteText($exception, $outcome));
            }

            return $outcome;
        }
    }

    private static function idempotencyKey(string $prefix, CreateInvoiceRequest $request): string
    {
        $payload = json_encode($request->toArray(), \JSON_THROW_ON_ERROR);
        $document = $request->document !== null ? hash('sha256', $request->document->contents) : '';

        return $prefix.'-'.substr(hash('sha256', $payload.'|'.$document), 0, 16);
    }

    private static function classify(Throwable $exception, string $subject): SyncOutcome
    {
        if ($exception instanceof AuthenticationException) {
            ConnectionHealth::recordAuthFailure($exception->getMessage());
        }

        return SyncOutcome::fromException($exception, $subject);
    }

    private static function noteText(Throwable $exception, SyncOutcome $outcome): string
    {
        return $exception->getMessage().($outcome->retryable
            ? ' De koppeling probeert het automatisch opnieuw.'
            : ' Dit wordt niet automatisch opnieuw geprobeerd; los de oorzaak op en kies bij de orderacties "Opnieuw naar Minox sturen".');
    }
}
