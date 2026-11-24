<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use DateTimeImmutable;
use KBDataSolutions\Sdk\Client;
use KBDataSolutions\Sdk\Exception\AuthenticationException;
use KBDataSolutions\Sdk\Exception\ConflictException;
use KBDataSolutions\Sdk\Exception\NotFoundException;
use KBDataSolutions\Sdk\ImportJob\ImportJob;
use KBDataSolutions\Sdk\ImportJob\Request\ImportJobCompletion;
use KBDataSolutions\Sdk\ImportJob\Request\ImportJobProgress;
use MinoxWooCommerce\Integration;
use Throwable;
use WC_Order;
use WC_Order_Refund;

final class ImportJobService
{
    public const string POLL_HOOK = 'minox_wc_poll_import_jobs';
    public const string BATCH_HOOK = 'minox_wc_import_batch';
    public const string STATE_OPTION = 'minox_wc_import_state';
    public const string LAST_RESULT_OPTION = 'minox_wc_import_last_result';
    public const int BATCH_SIZE = 50;
    private const string GROUP = 'minox-commerce';
    private const int POLL_INTERVAL = 300;
    private const int MAX_BATCH_ATTEMPTS = 5;

    public function __construct(
        private readonly IntegrationSettings $integration,
        private readonly OrderSyncService $orders = new OrderSyncService(),
    ) {
    }

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'ensureScheduled'], 40);
        add_action(self::POLL_HOOK, [self::class, 'handlePoll']);
        add_action(self::BATCH_HOOK, [self::class, 'handleBatch'], 10, 2);
    }

    public static function ensureScheduled(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }
        $scheduled = as_has_scheduled_action(self::POLL_HOOK, [], self::GROUP);
        if (!Integration::instance()->isConfigured()) {
            if ($scheduled && function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::POLL_HOOK, [], self::GROUP);
            }
            return;
        }
        if (!$scheduled) {
            as_schedule_recurring_action(time() + 60, self::POLL_INTERVAL, self::POLL_HOOK, [], self::GROUP, true);
        }
    }

    public static function pollSoon(): void
    {
        if (function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action')
            && !as_has_scheduled_action(self::POLL_HOOK, [], self::GROUP)) {
            as_enqueue_async_action(self::POLL_HOOK, [], self::GROUP, true);
        }
    }

    public static function handlePoll(): void
    {
        (new self(Integration::instance()))->poll();
    }

    public static function handleBatch(string $jobId, int $attempt = 0): void
    {
        (new self(Integration::instance()))->runBatch($jobId, $attempt);
    }

    public function poll(): void
    {
        $client = $this->client();
        if ($client === null) {
            return;
        }

        $state = $this->state();
        if ($state !== null && $this->batchScheduled($state['job_id'])) {
            return;
        }

        try {
            $jobs = $client->importJobs()->list();
            ConnectionHealth::recordSuccess();
        } catch (AuthenticationException $exception) {
            ConnectionHealth::recordAuthFailure($exception->getMessage());
            return;
        } catch (Throwable) {
            return;
        }

        foreach ($jobs as $job) {
            try {
                $claimed = $client->importJobs()->claim($job->id);
            } catch (ConflictException) {
                continue;
            } catch (Throwable) {
                return;
            }
            $this->start($claimed);
            return;
        }
    }

    public function runBatch(string $jobId, int $attempt = 0): void
    {
        $state = $this->state();
        $client = $this->client();
        if ($state === null || $state['job_id'] !== $jobId || $client === null) {
            return;
        }

        $orders = $this->orders($state);
        $syncRefunds = $this->integration->syncRefunds();

        try {
            foreach ($orders as $order) {
                $this->processOrder($order, $syncRefunds, $state);
            }
        } catch (Throwable $exception) {
            $this->retryBatch($jobId, $attempt, $exception);
            return;
        }

        $state['offset'] += count($orders);
        $finished = count($orders) < self::BATCH_SIZE;

        try {
            if ($finished) {
                $client->importJobs()->complete($jobId, ImportJobCompletion::fromTotals(
                    $state['orders_sent'],
                    $state['orders_failed'],
                    $state['refunds_sent'],
                    $state['last_error'],
                ));
                $this->finish($state, __('The import has finished.', 'kb-data-connector-for-woocommerce'));
                return;
            }
            $client->importJobs()->reportProgress($jobId, new ImportJobProgress(
                (string) $state['offset'],
                $state['orders_sent'],
                $state['orders_failed'],
                $state['refunds_sent'],
                $state['last_error'],
            ));
        } catch (ConflictException | NotFoundException) {
            $this->finish($state, __('The import has stopped (cancelled in the portal).', 'kb-data-connector-for-woocommerce'));
            return;
        } catch (Throwable $exception) {
            $state['last_error'] = $exception->getMessage();
        }

        $this->saveState($state);
        $this->scheduleBatch($jobId, 0);
    }

    /** @return array{job_id: string, from: string, to: string, offset: int, orders_sent: int, orders_failed: int, refunds_sent: int, last_error: ?string}|null */
    public static function currentState(): ?array
    {
        $state = get_option(self::STATE_OPTION);

        return is_array($state) && isset($state['job_id'], $state['from'], $state['to']) ? self::normaliseState($state) : null;
    }

    /** @param array{job_id: string, from: string, to: string, offset: int, orders_sent: int, orders_failed: int, refunds_sent: int, last_error: ?string} $state */
    private function processOrder(WC_Order $order, bool $syncRefunds, array &$state): void
    {
        $outcome = $this->orders->syncOrder($order, $this->integration);
        if ($outcome->isFailed()) {
            $state['orders_failed']++;
            $state['last_error'] = $outcome->message;
            OrderSyncQueue::takeOver($order, $outcome, $order->get_id());
            return;
        }
        if (!$outcome->isBooked()) {
            return;
        }
        $state['orders_sent']++;

        if (!$syncRefunds) {
            return;
        }
        foreach ($order->get_refunds() as $refund) {
            if (!$refund instanceof WC_Order_Refund) {
                continue;
            }
            $refundOutcome = $this->orders->syncOrderRefund($order, $refund, $this->integration);
            if ($refundOutcome->isBooked()) {
                $state['refunds_sent']++;
            } elseif ($refundOutcome->isFailed()) {
                $state['orders_failed']++;
                $state['last_error'] = $refundOutcome->message;
                OrderSyncQueue::takeOver($refund, $refundOutcome, $order->get_id());
            }
        }
    }

    /**
     * @param array{from: string, to: string, offset: int} $state
     * @return list<WC_Order>
     */
    private function orders(array $state): array
    {
        $statuses = $this->integration->syncTriggerStatus() === 'completed' ? ['wc-completed'] : ['wc-processing', 'wc-completed'];
        if ($this->integration->syncRefunds()) {
            $statuses[] = 'wc-refunded';
        }

        $from = (new DateTimeImmutable($state['from'].' 00:00:00', wp_timezone()))->getTimestamp();
        $to = (new DateTimeImmutable($state['to']))->getTimestamp();

        $orders = wc_get_orders([
            'type' => 'shop_order',
            'status' => $statuses,
            'date_created' => $from.'...'.$to,
            'orderby' => 'ID',
            'order' => 'ASC',
            'limit' => self::BATCH_SIZE,
            'offset' => $state['offset'],
        ]);

        return is_array($orders) ? array_values(array_filter($orders, static fn (mixed $order): bool => $order instanceof WC_Order)) : [];
    }

    private function start(ImportJob $job): void
    {
        $this->saveState([
            'job_id' => $job->id,
            'from' => $job->fromDate,
            'to' => $job->toDate,
            'offset' => $job->cursor !== null && ctype_digit($job->cursor) ? (int) $job->cursor : 0,
            'orders_sent' => $job->ordersSent,
            'orders_failed' => $job->ordersFailed,
            'refunds_sent' => $job->refundsSent,
            'last_error' => null,
        ]);
        $this->scheduleBatch($job->id, 0);
    }

    private function retryBatch(string $jobId, int $attempt, Throwable $exception): void
    {
        $state = $this->state();
        if ($state !== null) {
            $state['last_error'] = $exception->getMessage();
            $this->saveState($state);
        }
        if ($attempt < self::MAX_BATCH_ATTEMPTS) {
            $this->scheduleBatch($jobId, $attempt + 1);
        }
    }

    /** @param array{job_id: string, orders_sent: int, orders_failed: int, refunds_sent: int} $state */
    private function finish(array $state, string $message): void
    {
        delete_option(self::STATE_OPTION);
        update_option(self::LAST_RESULT_OPTION, [
            'message' => $message,
            'orders_sent' => $state['orders_sent'],
            'orders_failed' => $state['orders_failed'],
            'refunds_sent' => $state['refunds_sent'],
            'finished_at' => time(),
        ], false);
    }

    private function scheduleBatch(string $jobId, int $attempt): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        $delay = $attempt === 0 ? 1 : min(3600, 60 * (2 ** ($attempt - 1)));
        as_schedule_single_action(time() + $delay, self::BATCH_HOOK, [$jobId, $attempt], self::GROUP, true);
    }

    private function batchScheduled(string $jobId): bool
    {
        if (!function_exists('as_has_scheduled_action')) {
            return false;
        }
        for ($attempt = 0; $attempt <= self::MAX_BATCH_ATTEMPTS; $attempt++) {
            if (as_has_scheduled_action(self::BATCH_HOOK, [$jobId, $attempt], self::GROUP)) {
                return true;
            }
        }

        return false;
    }

    private function client(): ?Client
    {
        return $this->integration->isConfigured() ? $this->integration->makeClient() : null;
    }

    /** @return array{job_id: string, from: string, to: string, offset: int, orders_sent: int, orders_failed: int, refunds_sent: int, last_error: ?string}|null */
    private function state(): ?array
    {
        return self::currentState();
    }

    /** @param array{job_id: string, from: string, to: string, offset: int, orders_sent: int, orders_failed: int, refunds_sent: int, last_error: ?string} $state */
    private function saveState(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * @param array<array-key, mixed> $state
     * @return array{job_id: string, from: string, to: string, offset: int, orders_sent: int, orders_failed: int, refunds_sent: int, last_error: ?string}
     */
    private static function normaliseState(array $state): array
    {
        return [
            'job_id' => (string) $state['job_id'],
            'from' => (string) $state['from'],
            'to' => (string) $state['to'],
            'offset' => (int) ($state['offset'] ?? 0),
            'orders_sent' => (int) ($state['orders_sent'] ?? 0),
            'orders_failed' => (int) ($state['orders_failed'] ?? 0),
            'refunds_sent' => (int) ($state['refunds_sent'] ?? 0),
            'last_error' => isset($state['last_error']) && is_string($state['last_error']) ? $state['last_error'] : null,
        ];
    }
}
