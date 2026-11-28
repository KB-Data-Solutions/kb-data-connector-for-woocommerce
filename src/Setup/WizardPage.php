<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Setup;

use KBDataSolutions\Sdk\Exception\NotFoundException;
use KBDataSolutions\Sdk\Exception\SdkException;
use InvalidArgumentException;
use MinoxWooCommerce\Integration;
use MinoxWooCommerce\Security\CredentialCipher;
use MinoxWooCommerce\Sync\ConnectionHealth;
use MinoxWooCommerce\Sync\ImportJobService;
use MinoxWooCommerce\Sync\InvoicePdfResolver;
use MinoxWooCommerce\Sync\OrderSyncQueue;
use MinoxWooCommerce\Sync\PaymentMethodSyncService;
use MinoxWooCommerce\Sync\VatNumberResolver;
use Throwable;

final class WizardPage
{
    private const string SLUG = 'minox-koppeling-setup';

    private const string NONCE_ACTION = 'minox_wc_wizard';

    private const string SETTINGS_NONCE_ACTION = 'minox_wc_settings';

    private const string PAYMENT_METHODS_NONCE_ACTION = 'minox_wc_sync_payment_methods';



    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuItem']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_post_minox_wc_wizard_step', [self::class, 'handleSubmit']);
        add_action('admin_post_minox_wc_settings_save', [self::class, 'handleSettingsSave']);
        add_action('admin_post_minox_wc_sync_payment_methods', [self::class, 'handlePaymentMethodSync']);
    }

    public static function addMenuItem(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Minox Connector: Setup', 'kb-data-connector-for-woocommerce'),
            __('Minox Connector', 'kb-data-connector-for-woocommerce'),
            'manage_woocommerce',
            self::SLUG,
            [self::class, 'render'],
        );
    }

    public static function url(int $step = 1): string
    {
        return add_query_arg(
            ['page' => self::SLUG, 'step' => $step],
            admin_url('admin.php'),
        );
    }

    public static function settingsUrl(): string
    {
        return add_query_arg(['page' => self::SLUG], admin_url('admin.php'));
    }

    public static function handleSubmit(): void
    {
        check_admin_referer(self::NONCE_ACTION);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'kb-data-connector-for-woocommerce'));
        }

        $step = (int) ($_POST['step'] ?? 1);
        $settingsKey = 'woocommerce_minox_koppeling_settings';
        $settings = get_option($settingsKey, []);
        $settings = is_array($settings) ? $settings : [];

        match ($step) {
            1 => $settings = Integration::instance()->encryptSettings(array_merge($settings, [
                'client_id' => sanitize_text_field((string) ($_POST['client_id'] ?? '')),
                'client_secret' => sanitize_text_field((string) ($_POST['client_secret'] ?? '')),
            ])),
            2 => $settings = array_merge($settings, [
                'sync_trigger_status' => in_array($_POST['sync_trigger_status'] ?? '', ['processing', 'completed'], true)
                    ? $_POST['sync_trigger_status']
                    : 'processing',
                'sync_refunds' => self::postedSyncRefunds(),
                'vat_number_meta_key' => self::postedVatNumberMetaKey(),
            ]),
            default => null,
        };

        update_option($settingsKey, $settings);

        if ($step === 1) {
            self::retryAfterCredentialChange();
        }

        $nextStep = $step >= 3 ? 3 : $step + 1;
        wp_safe_redirect(self::url($nextStep));
        exit;
    }

    public static function handleSettingsSave(): void
    {
        check_admin_referer(self::SETTINGS_NONCE_ACTION);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'kb-data-connector-for-woocommerce'));
        }

        $settingsKey = 'woocommerce_minox_koppeling_settings';
        $settings = get_option($settingsKey, []);
        $settings = is_array($settings) ? $settings : [];

        $settings = Integration::instance()->encryptSettings(array_merge($settings, [
            'client_id' => sanitize_text_field((string) ($_POST['client_id'] ?? '')),
            'client_secret' => sanitize_text_field((string) ($_POST['client_secret'] ?? '')),
            'sync_trigger_status' => in_array($_POST['sync_trigger_status'] ?? '', ['processing', 'completed'], true)
                ? $_POST['sync_trigger_status']
                : 'processing',
            'attach_pdf_invoice' => InvoicePdfResolver::isAvailable() && ($_POST['attach_pdf_invoice'] ?? '') === 'yes'
                ? 'yes'
                : 'no',
            'sync_refunds' => self::postedSyncRefunds(),
            'vat_number_meta_key' => self::postedVatNumberMetaKey(),
        ]));

        update_option($settingsKey, $settings);
        self::retryAfterCredentialChange();

        wp_safe_redirect(add_query_arg('updated', 1, self::settingsUrl()));
        exit;
    }

    private static function retryAfterCredentialChange(): void
    {
        $changed = trim((string) ($_POST['client_id'] ?? '')) !== '' || trim((string) ($_POST['client_secret'] ?? '')) !== '';

        if (!$changed) {
            return;
        }

        ConnectionHealth::clearAuthError();

        if (Integration::instance()->isConfigured()) {
            (new OrderSyncQueue(Integration::instance()))->retryAll();
        }
    }

    public static function handlePaymentMethodSync(): void
    {
        check_admin_referer(self::PAYMENT_METHODS_NONCE_ACTION);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'kb-data-connector-for-woocommerce'));
        }

        try {
            $args = ['payment_methods' => 'synced', 'count' => PaymentMethodSyncService::syncNow()];
        } catch (Throwable) {
            $args = ['payment_methods' => 'failed'];
        }

        wp_safe_redirect(add_query_arg($args, self::settingsUrl()));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $integration = Integration::instance();

        echo '<div class="wrap minox-wc-wizard">';
        self::renderHeader();

        if ($integration->isConfigured() && !isset($_GET['step'])) {
            self::renderSettingsPage($integration);
            echo '</div>';

            return;
        }

        $step = max(1, min(3, (int) ($_GET['step'] ?? 1)));
        self::renderStepIndicator($step);

        echo '<div class="minox-wc-card">';

        match ($step) {
            1 => self::renderStep1(),
            2 => self::renderStep2(),
            3 => self::renderStep3(),
        };

        echo '</div></div>';
    }

    private static function renderSettingsPage(Integration $integration): void
    {
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                .esc_html__('Settings saved.', 'kb-data-connector-for-woocommerce').'</p></div>';
        }

        echo '<div class="minox-wc-card">';
        echo '<h2>'.esc_html__('Settings', 'kb-data-connector-for-woocommerce').'</h2>';
        echo '<p>'.esc_html__('The connection with Minox is active. You can change the settings below.', 'kb-data-connector-for-woocommerce').'</p>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(self::SETTINGS_NONCE_ACTION);
        echo '<input type="hidden" name="action" value="minox_wc_settings_save">';

        self::maskedCredentialField('client_id', __('Client ID', 'kb-data-connector-for-woocommerce'), $integration->get_option('client_id'), true);
        self::maskedCredentialField('client_secret', __('Client Secret', 'kb-data-connector-for-woocommerce'), $integration->get_option('client_secret'), false);

        echo '<div class="minox-wc-field"><label>'.esc_html__('Send the invoice when', 'kb-data-connector-for-woocommerce').'</label>';
        echo '<p><label><input type="radio" name="sync_trigger_status" value="processing" '
            .checked($integration->syncTriggerStatus(), 'processing', false).'> '
            .esc_html__('Processing (payment received), recommended', 'kb-data-connector-for-woocommerce').'</label></p>';
        echo '<p><label><input type="radio" name="sync_trigger_status" value="completed" '
            .checked($integration->syncTriggerStatus(), 'completed', false).'> '
            .esc_html__('Completed (shipped/picked up)', 'kb-data-connector-for-woocommerce').'</label></p>';
        echo '</div>';

        echo '<div class="minox-wc-field"><label>'.esc_html__('Attach invoice PDF', 'kb-data-connector-for-woocommerce').'</label>';

        if (InvoicePdfResolver::isAvailable()) {
            echo '<p><label><input type="checkbox" name="attach_pdf_invoice" value="yes" '
                .checked($integration->attachPdfInvoice(), true, false).'> '
                .esc_html__('Attach the invoice PDF to the booking in Minox', 'kb-data-connector-for-woocommerce').'</label></p>';

            if ($integration->attachPdfInvoice() && $integration->syncRefunds()) {
                self::renderCreditNotePluginMissingNotice();
            }
        } else {
            echo '<input type="hidden" name="attach_pdf_invoice" value="no">';
            self::renderPdfPluginMissingNotice();
        }

        echo '</div>';

        self::renderSyncRefundsField($integration);

        echo TaxRoundingNotice::render();
        echo VatNumberSourceSettings::render($integration->vatNumberMetaKey(), 'vat_number_meta_key');

        self::submitButton(__('Save settings', 'kb-data-connector-for-woocommerce'));
        echo '</form>';

        self::renderSyncHealth();
        self::renderPaymentMethodSync();
        self::renderImportStatus();

        echo '<p><a href="'.esc_url(self::url(1)).'">'
            .esc_html__('Set up the connection again (wizard)', 'kb-data-connector-for-woocommerce').'</a></p>';
        echo '</div>';
    }

    private static function renderSyncHealth(): void
    {
        $queue = new OrderSyncQueue(Integration::instance());
        $counts = $queue->counts();
        $authError = ConnectionHealth::authError();
        $lastSuccess = ConnectionHealth::lastSuccessAt();

        echo '<div class="minox-wc-field"><label>'.esc_html__('Connection and delivery', 'kb-data-connector-for-woocommerce').'</label>';

        if (isset($_GET['sync_retry'])) {
            echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(
                /* translators: %d: number of orders and refunds */
                _n('%d order or refund rescheduled.', '%d orders or refunds rescheduled.', absint($_GET['sync_retry']), 'kb-data-connector-for-woocommerce'),
                absint($_GET['sync_retry']),
            )).'</p></div>';
        }

        if ($authError !== null) {
            echo '<div class="notice notice-error inline"><p>'.esc_html(sprintf(
                /* translators: %s: error message from KB Data */
                __('KB Data refuses the connection: %s. Check the client ID and secret above (and that they belong to this environment). Orders are kept and sent as soon as the connection works.', 'kb-data-connector-for-woocommerce'),
                $authError,
            )).'</p></div>';
        }

        echo '<p>'.esc_html($lastSuccess !== null
            ? sprintf(
                /* translators: %s: date and time */
                __('Last successful connection with KB Data: %s.', 'kb-data-connector-for-woocommerce'),
                wp_date('d-m-Y H:i', $lastSuccess),
            )
            : __('No successful connection with KB Data recorded yet.', 'kb-data-connector-for-woocommerce')).'</p>';

        echo '<p>'.esc_html(sprintf(
            /* translators: 1: number of waiting orders, 2: number of orders that need attention */
            __('Waiting to be sent: %1$d. Needs attention: %2$d.', 'kb-data-connector-for-woocommerce'),
            $counts['waiting'],
            $counts['failed'],
        )).'</p>';

        $problems = $queue->problemOrders();
        if ($problems !== []) {
            echo '<ul>';
            foreach ($problems as $order) {
                echo '<li><a href="'.esc_url($order->get_edit_order_url()).'">'.esc_html('#'.$order->get_order_number()).'</a> — '
                    .esc_html($order->get_meta(OrderSyncQueue::META_STATUS) === OrderSyncQueue::STATUS_FAILED
                        ? __('needs attention', 'kb-data-connector-for-woocommerce')
                        : __('being retried', 'kb-data-connector-for-woocommerce'))
                    .': '.esc_html((string) $order->get_meta(OrderSyncQueue::META_ERROR)).'</li>';
            }
            echo '</ul>';
        }

        echo '<p>'.esc_html__('Failed deliveries are retried automatically, with growing intervals. Every hour the connector also checks whether any orders were missed.', 'kb-data-connector-for-woocommerce').'</p>';

        if ($counts['waiting'] + $counts['failed'] > 0) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field(SyncStatusNotices::RETRY_NONCE_ACTION);
            echo '<input type="hidden" name="action" value="minox_wc_retry_sync">';
            echo '<p><button type="submit" class="button">'.esc_html__('Retry now', 'kb-data-connector-for-woocommerce').'</button></p>';
            echo '</form>';
        }

        echo '</div>';
    }

    private static function renderPaymentMethodSync(): void
    {
        echo '<div class="minox-wc-field"><label>'.esc_html__('Payment methods', 'kb-data-connector-for-woocommerce').'</label>';

        $result = isset($_GET['payment_methods']) ? sanitize_key((string) $_GET['payment_methods']) : '';

        if ($result === 'synced') {
            $count = isset($_GET['count']) ? absint($_GET['count']) : 0;
            echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(
                /* translators: %d: number of payment methods */
                _n('%d payment method sent.', '%d payment methods sent.', $count, 'kb-data-connector-for-woocommerce'),
                $count,
            )).'</p></div>';
        } elseif ($result === 'failed') {
            echo '<div class="notice notice-error inline"><p>'
                .esc_html__('The payment methods could not be sent. Check the connection and try again.', 'kb-data-connector-for-woocommerce')
                .'</p></div>';
        } elseif (($error = PaymentMethodSyncService::lastError()) !== null) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($error).'</p></div>';
        }

        echo '<p>'.esc_html__('Payment methods are sent automatically when they change. Use this button to send them right away, for example after adding a new payment method.', 'kb-data-connector-for-woocommerce').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(self::PAYMENT_METHODS_NONCE_ACTION);
        echo '<input type="hidden" name="action" value="minox_wc_sync_payment_methods">';
        echo '<p><button type="submit" class="button">'.esc_html__('Send payment methods now', 'kb-data-connector-for-woocommerce').'</button></p>';
        echo '</form></div>';
    }

    private static function renderImportStatus(): void
    {
        ImportJobService::pollSoon();

        echo '<div class="minox-wc-field"><label>'.esc_html__('Import orders', 'kb-data-connector-for-woocommerce').'</label>';

        $state = ImportJobService::currentState();
        $last = get_option(ImportJobService::LAST_RESULT_OPTION);

        if ($state !== null) {
            echo '<div class="notice notice-info inline"><p>'.esc_html(sprintf(
                /* translators: 1: start date, 2: orders processed, 3: refunds processed, 4: failed */
                __('Import from %1$s in progress: %2$d orders and %3$d refunds processed, %4$d failed.', 'kb-data-connector-for-woocommerce'),
                $state['from'],
                $state['orders_sent'],
                $state['refunds_sent'],
                $state['orders_failed'],
            )).'</p></div>';
        } elseif (is_array($last) && isset($last['message'])) {
            echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(
                /* translators: 1: result message, 2: orders processed, 3: refunds processed, 4: failed */
                __('%1$s %2$d orders and %3$d refunds processed, %4$d failed.', 'kb-data-connector-for-woocommerce'),
                (string) $last['message'],
                (int) ($last['orders_sent'] ?? 0),
                (int) ($last['refunds_sent'] ?? 0),
                (int) ($last['orders_failed'] ?? 0),
            )).'</p></div>';
        }

        echo '<p>'.esc_html__('Older orders are imported on request in the KB Data portal (Webshop connections → Import orders). The shop then processes the import in the background; failed orders get an order note.', 'kb-data-connector-for-woocommerce').'</p>';
        echo '</div>';
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_'.self::SLUG) {
            return;
        }

        wp_enqueue_style('minox-wc-admin', plugins_url('assets/admin.css', \MinoxWooCommerce\MINOX_WC_PLUGIN_FILE), [], \MinoxWooCommerce\MINOX_WC_VERSION);
    }

    private static function renderHeader(): void
    {
        echo '<div class="minox-wc-header">';

        echo '<img src="'.esc_url(plugins_url('assets/logo.svg', \MinoxWooCommerce\MINOX_WC_PLUGIN_FILE)).'" width="40" height="40" alt="">';

        echo '<div>';
        echo '<h1><span class="minox-wc-wordmark-main">'.esc_html__('Minox Connector', 'kb-data-connector-for-woocommerce').'</span>';
        echo '<span class="minox-wc-wordmark-sub">'.esc_html__('KB Data Solutions', 'kb-data-connector-for-woocommerce').'</span></h1>';
        echo '</div></div>';
    }

    private static function renderStepIndicator(int $step): void
    {
        $labels = [
            1 => __('Connect account', 'kb-data-connector-for-woocommerce'),
            2 => __('Settings', 'kb-data-connector-for-woocommerce'),
            3 => __('Done', 'kb-data-connector-for-woocommerce'),
        ];

        echo '<div class="minox-wc-steps">';

        foreach ($labels as $number => $label) {
            $class = match (true) {
                $number < $step => 'minox-wc-step is-done',
                $number === $step => 'minox-wc-step is-active',
                default => 'minox-wc-step',
            };
            echo '<div class="'.esc_attr($class).'">'.esc_html($number.'. '.$label).'</div>';
        }

        echo '</div>';
    }

    private static function renderStep1(): void
    {
        $integration = Integration::instance();
        $connectionError = null;

        if ($integration->isConfigured()) {
            try {
                $integration->makeClient()?->debtors()->find('minox-koppeling-connection-test');
            } catch (NotFoundException) {
            } catch (SdkException $exception) {
                $connectionError = $exception->getMessage();
            } catch (Throwable) {
                $connectionError = __('Unknown error while testing the connection.', 'kb-data-connector-for-woocommerce');
            }
        }

        if (!$integration->isConfigured()) {
            self::renderIntro();
        }

        echo '<h2>'.esc_html__('Connect your Minox account', 'kb-data-connector-for-woocommerce').'</h2>';
        echo '<p>'.esc_html__('You received these details from KB Data Solutions when your connection was created.', 'kb-data-connector-for-woocommerce').'</p>';

        if ($connectionError !== null) {
            echo '<div class="notice notice-error"><p>'.esc_html($connectionError).'</p></div>';
        } elseif ($integration->isConfigured()) {
            echo '<div class="notice notice-success"><p>'.esc_html__('Connection works.', 'kb-data-connector-for-woocommerce').'</p></div>';
        }

        self::formOpen(1);
        self::textField('client_id', __('Client ID', 'kb-data-connector-for-woocommerce'), $integration->get_option('client_id'));
        self::textField('client_secret', __('Client Secret', 'kb-data-connector-for-woocommerce'), $integration->get_option('client_secret'), 'password');
        self::submitButton(__('Save and test', 'kb-data-connector-for-woocommerce'));
        self::formClose();
    }

    private static function renderIntro(): void
    {
        echo '<div class="minox-wc-intro">';
        echo '<p class="minox-wc-intro-title">'.esc_html__('What does this plugin do?', 'kb-data-connector-for-woocommerce').'</p>';
        echo '<p>'.esc_html__('KB Data Solutions builds connections between web shops and accounting software. This plugin books every order from this shop automatically and correctly in your Minox administration.', 'kb-data-connector-for-woocommerce').'</p>';

        echo '<ul class="minox-wc-benefits">';
        foreach ([
            __('Automatic booking per order, no manual retyping', 'kb-data-connector-for-woocommerce'),
            __('OSS and intra-community VAT recognised and applied automatically', 'kb-data-connector-for-woocommerce'),
            __('Invoice attached to the booking automatically', 'kb-data-connector-for-woocommerce'),
        ] as $benefit) {
            echo '<li>'.esc_html($benefit).'</li>';
        }
        echo '</ul>';

        echo '<p>'.esc_html__('No Client ID and Secret yet?', 'kb-data-connector-for-woocommerce').' ';
        echo '<a href="'.esc_url('https://www.kbdatasolutions.nl/woocommerce-koppeling').'" target="_blank" rel="noopener">'
            .esc_html__('See how the connector works and request access', 'kb-data-connector-for-woocommerce').'</a>.</p>';
        echo '</div>';
    }

    private static function renderStep2(): void
    {
        $integration = Integration::instance();

        echo '<h2>'.esc_html__('When should the invoice be sent?', 'kb-data-connector-for-woocommerce').'</h2>';

        self::formOpen(2);
        echo '<p><label><input type="radio" name="sync_trigger_status" value="processing" '
            .checked($integration->syncTriggerStatus(), 'processing', false).'> '
            .esc_html__('Processing (payment received), recommended', 'kb-data-connector-for-woocommerce').'</label></p>';
        echo '<p><label><input type="radio" name="sync_trigger_status" value="completed" '
            .checked($integration->syncTriggerStatus(), 'completed', false).'> '
            .esc_html__('Completed (shipped/picked up)', 'kb-data-connector-for-woocommerce').'</label></p>';

        if (!InvoicePdfResolver::isAvailable()) {
            self::renderPdfPluginMissingNotice();
        } else {
            echo '<div class="notice notice-success"><p>'.esc_html__('Invoice PDF plugin detected, PDFs are attached automatically.', 'kb-data-connector-for-woocommerce').'</p></div>';
            self::renderCreditNotePluginMissingNotice();
        }

        self::renderSyncRefundsField($integration);

        echo TaxRoundingNotice::render();
        echo VatNumberSourceSettings::render($integration->vatNumberMetaKey(), 'vat_number_meta_key');

        self::submitButton(__('Next', 'kb-data-connector-for-woocommerce'));
        self::formClose();
    }

    private static function renderStep3(): void
    {
        echo '<div class="minox-wc-success-banner">';
        echo '<div class="minox-wc-check">✓</div>';
        echo '<h2>'.esc_html__('Done!', 'kb-data-connector-for-woocommerce').'</h2>';
        echo '<p>'.esc_html__('Minox Connector is set up. You can change everything later via WooCommerce → Minox Connector.', 'kb-data-connector-for-woocommerce').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=integration&section=minox_koppeling')).'">'
            .esc_html__('Go to settings', 'kb-data-connector-for-woocommerce').'</a></p>';
        echo '</div>';
    }

    private static function formOpen(int $step): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="minox_wc_wizard_step">';
        echo '<input type="hidden" name="step" value="'.esc_attr((string) $step).'">';
    }

    private static function formClose(): void
    {
        echo '</form>';
    }

    private static function textField(string $name, string $label, string $value, string $type = 'text'): void
    {
        echo '<div class="minox-wc-field"><label>'.esc_html($label).'</label>';
        echo '<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" class="regular-text"></div>';
    }

    private static function maskedCredentialField(string $name, string $label, string $value, bool $revealTail): void
    {
        $toggleId = 'minox-wc-toggle-'.$name;

        echo '<div class="minox-wc-field minox-wc-credential-field">';
        echo '<label>'.esc_html($label).'</label>';
        echo '<p class="minox-wc-credential-masked">'.esc_html(CredentialCipher::mask($value, $revealTail)).'</p>';
        echo '<input type="checkbox" id="'.esc_attr($toggleId).'" class="minox-wc-credential-toggle">';
        echo '<label for="'.esc_attr($toggleId).'" class="minox-wc-credential-toggle-label">'
            .esc_html__('Change', 'kb-data-connector-for-woocommerce').'</label>';
        echo '<input type="text" name="'.esc_attr($name).'" value="" class="regular-text minox-wc-credential-input" '
            .'placeholder="'.esc_attr__('Leave empty to keep the current value', 'kb-data-connector-for-woocommerce').'" '
            .'autocomplete="off">';
        echo '</div>';
    }

    private static function submitButton(string $label): void
    {
        echo '<p><button type="submit" class="button button-primary">'.esc_html($label).'</button></p>';
    }

    private static function renderSyncRefundsField(Integration $integration): void
    {
        echo '<div class="minox-wc-field"><label>'.esc_html__('Refunds', 'kb-data-connector-for-woocommerce').'</label>';
        echo '<input type="hidden" name="sync_refunds" value="no">';
        echo '<p><label><input type="checkbox" name="sync_refunds" value="yes" '
            .checked($integration->syncRefunds(), true, false).'> '
            .esc_html__('Book refunds as credit notes in Minox', 'kb-data-connector-for-woocommerce').'</label></p>';
        echo '</div>';
    }

    private static function postedSyncRefunds(): string
    {
        return ($_POST['sync_refunds'] ?? 'yes') === 'no' ? 'no' : 'yes';
    }

    private static function postedVatNumberMetaKey(): string
    {
        $value = $_POST['vat_number_meta_key'] ?? '';

        try {
            return VatNumberResolver::validateMetaKey(is_string($value) ? wp_unslash($value) : $value) ?? '';
        } catch (InvalidArgumentException $exception) {
            wp_die(esc_html($exception->getMessage()));
        }
    }

    private static function renderCreditNotePluginMissingNotice(): void
    {
        if (InvoicePdfResolver::isCreditNoteAvailable()) {
            return;
        }

        printf(
            '<div class="notice notice-warning inline minox-wc-pdf-notice">'
                .'<p>%s</p>'
                .'<p class="minox-wc-pdf-notice-footnote">%s</p>'
                .'</div>',
            sprintf(
                /* translators: %s: link to the PDF Invoices & Packing Slips Professional extension */
                esc_html__('Credit notes for refunds are only attached as PDF with %s. Without that extension refunds are still booked in Minox, just without an attached credit note.', 'kb-data-connector-for-woocommerce'),
                '<a href="'.esc_url(InvoicePdfResolver::PRO_PLUGIN_URL).'" target="_blank" rel="noopener">PDF Invoices &amp; Packing Slips Professional</a>',
            ),
            sprintf(
                /* translators: %s: contact link */
                esc_html__('Using another plugin for invoices or credit notes? %s and we will see whether we can support it.', 'kb-data-connector-for-woocommerce'),
                '<a href="'.esc_url('mailto:info@kbdatasolutions.nl').'">'
                    .esc_html__('Let us know', 'kb-data-connector-for-woocommerce').'</a>',
            ),
        );
    }

    private static function renderPdfPluginMissingNotice(): void
    {
        printf(
            '<div class="notice notice-warning inline minox-wc-pdf-notice">'
                .'<p>%s</p>'
                .'<p class="minox-wc-pdf-notice-footnote">%s</p>'
                .'</div>',
            sprintf(
                /* translators: %s: link to the PDF Invoices & Packing Slips plugin */
                esc_html__('No invoice PDF plugin was found on this shop. Install %s to attach a PDF invoice to the booking in Minox automatically. Without such a plugin the booking is still valid, just without an attached document.', 'kb-data-connector-for-woocommerce'),
                '<a href="'.esc_url('https://wordpress.org/plugins/'.InvoicePdfResolver::RECOMMENDED_PLUGIN_SLUG.'/').'" target="_blank" rel="noopener">PDF Invoices &amp; Packing Slips for WooCommerce</a>',
            ),
            sprintf(
                /* translators: %s: contact link */
                esc_html__('Already using another invoice plugin that we fail to recognise? %s and we are happy to take a look.', 'kb-data-connector-for-woocommerce'),
                '<a href="'.esc_url('mailto:info@kbdatasolutions.nl').'">'
                    .esc_html__('Get in touch with us', 'kb-data-connector-for-woocommerce').'</a>',
            ),
        );
    }
}
