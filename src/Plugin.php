<?php

declare(strict_types=1);

namespace MinoxWooCommerce;

use MinoxWooCommerce\Setup\SyncStatusNotices;
use MinoxWooCommerce\Setup\WizardPage;
use MinoxWooCommerce\Sync\ImportJobService;
use MinoxWooCommerce\Sync\OrderSyncQueue;
use MinoxWooCommerce\Sync\OrderSyncService;
use MinoxWooCommerce\Sync\PaymentMethodSyncService;
use MinoxWooCommerce\Sync\PaymentSyncService;

final class Plugin
{
    private const string FIRST_RUN_REDIRECT_OPTION = 'minox_wc_redirect_to_wizard';

    public static function boot(): void
    {
        add_filter('woocommerce_integrations', static function (array $integrations): array {
            $integrations[] = Integration::class;

            return $integrations;
        });

        WizardPage::register();
        OrderSyncService::register();
        OrderSyncQueue::register();
        SyncStatusNotices::register();
        PaymentMethodSyncService::register();
        PaymentSyncService::register();
        ImportJobService::register();

        add_action('admin_init', [self::class, 'maybeRedirectToWizard']);
    }

    public static function onActivation(): void
    {
        add_option(self::FIRST_RUN_REDIRECT_OPTION, 'yes');
    }

    public static function maybeRedirectToWizard(): void
    {
        if (get_option(self::FIRST_RUN_REDIRECT_OPTION) !== 'yes') {
            return;
        }

        delete_option(self::FIRST_RUN_REDIRECT_OPTION);

        if (wp_doing_ajax() || defined('WP_CLI')) {
            return;
        }

        wp_safe_redirect(WizardPage::url());
        exit;
    }
}
