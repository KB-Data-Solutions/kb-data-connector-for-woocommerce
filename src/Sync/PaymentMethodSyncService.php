<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use KBDataSolutions\Sdk\PaymentMethod\PaymentMethod;
use MinoxWooCommerce\Integration;
use RuntimeException;
use Throwable;

final class PaymentMethodSyncService
{
    private const string HOOK = 'minox_wc_sync_payment_methods';
    private const string GROUP = 'minox-commerce';
    private const string FINGERPRINT = 'minox_wc_payment_methods_fingerprint';
    private const string ERROR_OPTION = 'minox_wc_payment_methods_error';

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'maybeSchedule'], 30);
        add_action('updated_option', [self::class, 'settingsChanged'], 20, 1);
        add_action('added_option', [self::class, 'settingsChanged'], 20, 1);
        add_action(self::HOOK, [self::class, 'run'], 10, 1);
    }

    public static function settingsChanged(string $option): void
    {
        if (str_starts_with($option, 'woocommerce_') && str_ends_with($option, '_settings')) {
            self::schedule();
        }
    }

    public static function maybeSchedule(): void
    {
        if (!current_user_can('manage_woocommerce') || wp_doing_ajax()) {
            return;
        }
        if (!function_exists('as_schedule_single_action') || !function_exists('as_has_scheduled_action') || !Integration::instance()->isConfigured()) {
            return;
        }
        $methods = self::registeredMethods();
        if (self::fingerprint($methods) === get_option(self::FINGERPRINT)) {
            return;
        }
        self::schedule();
    }

    public static function run(int $attempt = 0): void
    {
        $integration = Integration::instance();
        if (!$integration->isConfigured()) {
            return;
        }
        $methods = self::registeredMethods();
        $fingerprint = self::fingerprint($methods);
        if ($fingerprint === get_option(self::FINGERPRINT)) {
            return;
        }
        try {
            self::syncNow();
        } catch (Throwable) {
            update_option(self::ERROR_OPTION, __('The payment methods could not be sent yet. This is retried automatically.', 'kb-data-connector-for-woocommerce'), false);
            if ($attempt < 5) {
                self::schedule($attempt + 1);
            }
        }
    }

    /** @return int number of payment methods sent */
    public static function syncNow(): int
    {
        $integration = Integration::instance();
        if (!$integration->isConfigured()) {
            throw new RuntimeException(__('The connector is not set up yet.', 'kb-data-connector-for-woocommerce'));
        }
        $methods = self::registeredMethods();
        (new self())->sync($integration, $methods);
        update_option(self::FINGERPRINT, self::fingerprint($methods), false);
        delete_option(self::ERROR_OPTION);

        return count($methods);
    }

    public static function lastError(): ?string
    {
        $error = get_option(self::ERROR_OPTION);

        return is_string($error) && $error !== '' ? $error : null;
    }

    /** @param list<PaymentMethod> $methods */
    public function sync(IntegrationSettings $integration, array $methods): void
    {
        $client = $integration->makeClient();
        if ($client !== null) {
            $client->paymentMethods()->sync($methods);
        }
    }

    /** @return list<PaymentMethod> */
    public static function registeredMethods(): array
    {
        $methods = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            $label = sanitize_text_field($gateway->get_title());
            $methods[] = new PaymentMethod(
                (string) $gateway->id,
                $label !== '' ? $label : (string) $gateway->id,
                self::provider((string) $gateway->id),
                $gateway->enabled === 'yes'
            );
        }
        usort($methods, static fn (PaymentMethod $left, PaymentMethod $right): int => strcmp($left->key, $right->key));
        return $methods;
    }

    /** @param list<PaymentMethod> $methods */
    private static function fingerprint(array $methods): string
    {
        return hash('sha256', (Integration::baseUri() ?? '').'|'.Integration::instance()->get_option('client_id').'|'.
            json_encode(array_map(static fn (PaymentMethod $method): array => $method->toArray(), $methods), JSON_THROW_ON_ERROR));
    }

    private static function provider(string $key): ?string
    {
        return match (true) {
            str_starts_with($key, 'mollie_wc_gateway_') => 'Mollie',
            str_starts_with($key, 'stripe') => 'Stripe',
            str_starts_with($key, 'ppcp') || $key === 'paypal' => 'PayPal',
            default => null,
        };
    }

    private static function schedule(int $attempt = 0): void
    {
        if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')
            && !as_has_scheduled_action(self::HOOK, [$attempt], self::GROUP)) {
            $delay = $attempt === 0 ? 1 : min(3600, 60 * (2 ** ($attempt - 1)));
            as_schedule_single_action(time() + $delay, self::HOOK, [$attempt], self::GROUP, true);
        }
    }
}
