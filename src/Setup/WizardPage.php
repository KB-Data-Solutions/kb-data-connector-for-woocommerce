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

    private const string BRAND_TURQUOISE = '#00D4AA';

    private const string BRAND_DARK = '#3A3A4A';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuItem']);
        add_action('admin_post_minox_wc_wizard_step', [self::class, 'handleSubmit']);
        add_action('admin_post_minox_wc_settings_save', [self::class, 'handleSettingsSave']);
        add_action('admin_post_minox_wc_sync_payment_methods', [self::class, 'handlePaymentMethodSync']);
    }

    public static function addMenuItem(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Minox Koppeling: Setup', 'kb-data-koppeling-voor-woocommerce'),
            __('Minox Koppeling', 'kb-data-koppeling-voor-woocommerce'),
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
            wp_die(esc_html__('Geen toegang.', 'kb-data-koppeling-voor-woocommerce'));
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
            wp_die(esc_html__('Geen toegang.', 'kb-data-koppeling-voor-woocommerce'));
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
            wp_die(esc_html__('Geen toegang.', 'kb-data-koppeling-voor-woocommerce'));
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
        self::renderStyles();
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
                .esc_html__('Instellingen opgeslagen.', 'kb-data-koppeling-voor-woocommerce').'</p></div>';
        }

        echo '<div class="minox-wc-card">';
        echo '<h2>'.esc_html__('Instellingen', 'kb-data-koppeling-voor-woocommerce').'</h2>';
        echo '<p>'.esc_html__('De koppeling met Minox is actief. Hieronder kun je de instellingen aanpassen.', 'kb-data-koppeling-voor-woocommerce').'</p>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(self::SETTINGS_NONCE_ACTION);
        echo '<input type="hidden" name="action" value="minox_wc_settings_save">';

        self::maskedCredentialField('client_id', __('Client ID', 'kb-data-koppeling-voor-woocommerce'), $integration->get_option('client_id'), true);
        self::maskedCredentialField('client_secret', __('Client Secret', 'kb-data-koppeling-voor-woocommerce'), $integration->get_option('client_secret'), false);

        echo '<div class="minox-wc-field"><label>'.esc_html__('Factuur versturen bij', 'kb-data-koppeling-voor-woocommerce').'</label>';
        echo '<p><label><input type="radio" name="sync_trigger_status" value="processing" '
            .checked($integration->syncTriggerStatus(), 'processing', false).'> '
            .esc_html__('In behandeling (betaling ontvangen), aanbevolen', 'kb-data-koppeling-voor-woocommerce').'</label></p>';
        echo '<p><label><input type="radio" name="sync_trigger_status" value="completed" '
            .checked($integration->syncTriggerStatus(), 'completed', false).'> '
            .esc_html__('Afgerond (verzonden/opgehaald)', 'kb-data-koppeling-voor-woocommerce').'</label></p>';
        echo '</div>';

        echo '<div class="minox-wc-field"><label>'.esc_html__('Factuur-PDF meesturen', 'kb-data-koppeling-voor-woocommerce').'</label>';

        if (InvoicePdfResolver::isAvailable()) {
            echo '<p><label><input type="checkbox" name="attach_pdf_invoice" value="yes" '
                .checked($integration->attachPdfInvoice(), true, false).'> '
                .esc_html__('Stuur de factuur-PDF mee als bijlage bij de boeking in Minox', 'kb-data-koppeling-voor-woocommerce').'</label></p>';

            if ($integration->attachPdfInvoice() && $integration->syncRefunds()) {
                self::renderCreditNotePluginMissingNotice();
            }
        } else {
            echo '<input type="hidden" name="attach_pdf_invoice" value="no">';
            self::renderPdfPluginMissingNotice();
        }

        echo '</div>';

        self::renderSyncRefundsField($integration);

        echo VatNumberSourceSettings::render($integration->vatNumberMetaKey(), 'vat_number_meta_key');

        self::submitButton(__('Instellingen opslaan', 'kb-data-koppeling-voor-woocommerce'));
        echo '</form>';

        self::renderSyncHealth();
        self::renderPaymentMethodSync();
        self::renderImportStatus();

        echo '<p><a href="'.esc_url(self::url(1)).'">'
            .esc_html__('Verbinding opnieuw instellen (wizard)', 'kb-data-koppeling-voor-woocommerce').'</a></p>';
        echo '</div>';
    }

    private static function renderSyncHealth(): void
    {
        $queue = new OrderSyncQueue(Integration::instance());
        $counts = $queue->counts();
        $authError = ConnectionHealth::authError();
        $lastSuccess = ConnectionHealth::lastSuccessAt();

        echo '<div class="minox-wc-field"><label>'.esc_html__('Verbinding en verzending', 'kb-data-koppeling-voor-woocommerce').'</label>';

        if (isset($_GET['sync_retry'])) {
            echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(
                _n('%d order of terugbetaling opnieuw ingepland.', '%d orders of terugbetalingen opnieuw ingepland.', absint($_GET['sync_retry']), 'kb-data-koppeling-voor-woocommerce'),
                absint($_GET['sync_retry']),
            )).'</p></div>';
        }

        if ($authError !== null) {
            echo '<div class="notice notice-error inline"><p>'.esc_html(sprintf(
                __('KB Data weigert de verbinding: %s. Controleer de client ID en het secret hierboven (en of ze bij deze omgeving horen). Orders blijven bewaard en worden verstuurd zodra de verbinding werkt.', 'kb-data-koppeling-voor-woocommerce'),
                $authError,
            )).'</p></div>';
        }

        echo '<p>'.esc_html($lastSuccess !== null
            ? sprintf(
                __('Laatste geslaagde verbinding met KB Data: %s.', 'kb-data-koppeling-voor-woocommerce'),
                wp_date('d-m-Y H:i', $lastSuccess),
            )
            : __('Nog geen geslaagde verbinding met KB Data geregistreerd.', 'kb-data-koppeling-voor-woocommerce')).'</p>';

        echo '<p>'.esc_html(sprintf(
            __('Wachtend op verzending: %1$d. Aandacht nodig: %2$d.', 'kb-data-koppeling-voor-woocommerce'),
            $counts['waiting'],
            $counts['failed'],
        )).'</p>';

        $problems = $queue->problemOrders();
        if ($problems !== []) {
            echo '<ul>';
            foreach ($problems as $order) {
                echo '<li><a href="'.esc_url($order->get_edit_order_url()).'">'.esc_html('#'.$order->get_order_number()).'</a> — '
                    .esc_html($order->get_meta(OrderSyncQueue::META_STATUS) === OrderSyncQueue::STATUS_FAILED
                        ? __('aandacht nodig', 'kb-data-koppeling-voor-woocommerce')
                        : __('wordt opnieuw geprobeerd', 'kb-data-koppeling-voor-woocommerce'))
                    .': '.esc_html((string) $order->get_meta(OrderSyncQueue::META_ERROR)).'</li>';
            }
            echo '</ul>';
        }

        echo '<p>'.esc_html__('Mislukte verzendingen worden automatisch opnieuw geprobeerd, met steeds langere tussenpozen. Elk uur controleert de koppeling bovendien of er orders zijn gemist.', 'kb-data-koppeling-voor-woocommerce').'</p>';

        if ($counts['waiting'] + $counts['failed'] > 0) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field(SyncStatusNotices::RETRY_NONCE_ACTION);
            echo '<input type="hidden" name="action" value="minox_wc_retry_sync">';
            echo '<p><button type="submit" class="button">'.esc_html__('Nu opnieuw proberen', 'kb-data-koppeling-voor-woocommerce').'</button></p>';
            echo '</form>';
        }

        echo '</div>';
    }

    private static function renderPaymentMethodSync(): void
    {
        echo '<div class="minox-wc-field"><label>'.esc_html__('Betaalmethoden', 'kb-data-koppeling-voor-woocommerce').'</label>';

        $result = isset($_GET['payment_methods']) ? sanitize_key((string) $_GET['payment_methods']) : '';

        if ($result === 'synced') {
            $count = isset($_GET['count']) ? absint($_GET['count']) : 0;
            echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(
                _n('%d betaalmethode doorgestuurd.', '%d betaalmethoden doorgestuurd.', $count, 'kb-data-koppeling-voor-woocommerce'),
                $count,
            )).'</p></div>';
        } elseif ($result === 'failed') {
            echo '<div class="notice notice-error inline"><p>'
                .esc_html__('Betaalmethoden konden niet worden doorgestuurd. Controleer de verbinding en probeer het opnieuw.', 'kb-data-koppeling-voor-woocommerce')
                .'</p></div>';
        } elseif (($error = PaymentMethodSyncService::lastError()) !== null) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($error).'</p></div>';
        }

        echo '<p>'.esc_html__('Betaalmethoden worden automatisch doorgestuurd als ze wijzigen. Met deze knop stuur je ze direct door, bijvoorbeeld na het toevoegen van een nieuwe betaalmethode.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field(self::PAYMENT_METHODS_NONCE_ACTION);
        echo '<input type="hidden" name="action" value="minox_wc_sync_payment_methods">';
        echo '<p><button type="submit" class="button">'.esc_html__('Betaalmethoden nu doorsturen', 'kb-data-koppeling-voor-woocommerce').'</button></p>';
        echo '</form></div>';
    }

    private static function renderImportStatus(): void
    {
        ImportJobService::pollSoon();

        echo '<div class="minox-wc-field"><label>'.esc_html__('Orders importeren', 'kb-data-koppeling-voor-woocommerce').'</label>';

        $state = ImportJobService::currentState();
        $last = get_option(ImportJobService::LAST_RESULT_OPTION);

        if ($state !== null) {
            echo '<div class="notice notice-info inline"><p>'.esc_html(sprintf(
                __('Import vanaf %1$s is bezig: %2$d orders en %3$d terugbetalingen verwerkt, %4$d mislukt.', 'kb-data-koppeling-voor-woocommerce'),
                $state['from'],
                $state['orders_sent'],
                $state['refunds_sent'],
                $state['orders_failed'],
            )).'</p></div>';
        } elseif (is_array($last) && isset($last['message'])) {
            echo '<div class="notice notice-success inline"><p>'.esc_html(sprintf(
                __('%1$s %2$d orders en %3$d terugbetalingen verwerkt, %4$d mislukt.', 'kb-data-koppeling-voor-woocommerce'),
                (string) $last['message'],
                (int) ($last['orders_sent'] ?? 0),
                (int) ($last['refunds_sent'] ?? 0),
                (int) ($last['orders_failed'] ?? 0),
            )).'</p></div>';
        }

        echo '<p>'.esc_html__('Oudere orders importeren vraagt u aan in het KB Data-portaal (Webshop Koppelingen → Orders importeren). De webshop verwerkt de import daarna automatisch op de achtergrond; mislukte orders krijgen een ordernotitie.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        echo '</div>';
    }

    private static function renderStyles(): void
    {
        $turq = self::BRAND_TURQUOISE;
        $dark = self::BRAND_DARK;
        echo <<<HTML
            <style>
                .minox-wc-wizard { max-width: 760px; margin-top: 20px; }
                .minox-wc-header { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
                .minox-wc-header h1 { margin: 0; font-size: 20px; line-height: 1.3; color: {$dark}; }
                .minox-wc-header .minox-wc-wordmark-main { font-weight: 800; }
                .minox-wc-header .minox-wc-wordmark-sub {
                    display: block; font-size: 10px; font-weight: 700; letter-spacing: .14em;
                    text-transform: uppercase; color: {$turq}; margin-top: 1px;
                }
                .minox-wc-steps { display: flex; gap: 8px; margin-bottom: 20px; }
                .minox-wc-step {
                    flex: 1; text-align: center; padding: 10px 8px; border-radius: 6px;
                    background: #f0f0f1; color: #646970; font-size: 12px; font-weight: 600;
                    border: 1px solid #dcdcde;
                }
                .minox-wc-step.is-active { background: {$turq}; color: {$dark}; border-color: {$turq}; }
                .minox-wc-step.is-done { background: #e6faf5; color: #00806a; border-color: #99ecd9; }
                .minox-wc-card {
                    background: #fff; border: 1px solid #dcdcde; border-radius: 8px;
                    padding: 28px 32px; box-shadow: 0 1px 2px rgba(0,0,0,0.04);
                }
                .minox-wc-card h2 { margin-top: 0; font-size: 16px; color: {$dark}; }
                .minox-wc-intro {
                    background: #f2fcf9; border: 1px solid #c3f3e6; border-radius: 6px;
                    padding: 18px 20px; margin-bottom: 24px;
                }
                .minox-wc-intro p:first-child { margin-top: 0; }
                .minox-wc-intro p:last-child { margin-bottom: 0; }
                .minox-wc-intro-title { font-size: 13px; font-weight: 700; color: #00806a; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 8px; }
                .minox-wc-benefits { list-style: none; margin: 14px 0; padding: 0; }
                .minox-wc-benefits li { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 8px; font-size: 13px; }
                .minox-wc-benefits li::before { content: "✓"; color: #00806a; font-weight: 700; flex-shrink: 0; }
                .minox-wc-field { margin-bottom: 16px; }
                .minox-wc-field label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; color: {$dark}; }
                .minox-wc-field input[type=text], .minox-wc-field input[type=password] {
                    width: 100%; max-width: 420px;
                }
                .minox-wc-credential-field .minox-wc-credential-masked {
                    font-family: monospace; font-size: 14px; margin: 0 0 4px; color: {$dark};
                }
                .minox-wc-credential-field .minox-wc-credential-toggle {
                    position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none;
                }
                .minox-wc-credential-field .minox-wc-credential-toggle-label {
                    display: inline-block; font-weight: 400; color: #2271b1; cursor: pointer;
                    text-decoration: underline; font-size: 13px; margin: 0;
                }
                .minox-wc-credential-field .minox-wc-credential-input {
                    display: none; margin-top: 8px;
                }
                .minox-wc-credential-field .minox-wc-credential-toggle:checked ~ .minox-wc-credential-input {
                    display: block;
                }
                .minox-wc-credential-field .minox-wc-credential-toggle:focus ~ .minox-wc-credential-toggle-label {
                    outline: 2px solid {$turq}; outline-offset: 2px;
                }
                .minox-wc-pdf-notice-footnote {
                    margin-top: 8px !important; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.08);
                    font-size: 12px; color: #646970;
                }
                .minox-wc-card .button-primary {
                    background: {$turq}; border-color: {$turq}; color: {$dark}; font-weight: 600; text-shadow: none; box-shadow: none;
                }
                .minox-wc-card .button-primary:hover,
                .minox-wc-card .button-primary:focus {
                    background: #00bb95; border-color: #00bb95; color: {$dark};
                }
                .minox-wc-success-banner {
                    text-align: center; padding: 8px 0 4px;
                }
                .minox-wc-success-banner .minox-wc-check {
                    display: inline-flex; align-items: center; justify-content: center;
                    width: 56px; height: 56px; border-radius: 50%;
                    background: #e6faf5; color: #00806a; font-size: 28px; margin-bottom: 12px;
                }
            </style>
            HTML;
    }

    private static function renderHeader(): void
    {
        $turq = self::BRAND_TURQUOISE;
        $dark = self::BRAND_DARK;

        echo '<div class="minox-wc-header">';

        echo <<<SVG
            <svg width="40" height="40" viewBox="0 0 50 50" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="3.8" fill="{$dark}" />
                <circle cx="25" cy="12" r="3.8" fill="{$dark}" opacity="0.18" />
                <circle cx="38" cy="12" r="3.8" fill="{$dark}" />
                <circle cx="12" cy="25" r="3.8" fill="{$dark}" />
                <circle cx="25" cy="25" r="3.8" fill="{$turq}" />
                <circle cx="38" cy="25" r="3.8" fill="{$dark}" opacity="0.18" />
                <circle cx="12" cy="38" r="3.8" fill="{$dark}" opacity="0.18" />
                <circle cx="25" cy="38" r="3.8" fill="{$dark}" opacity="0.18" />
                <circle cx="38" cy="38" r="3.8" fill="{$dark}" />
                <line x1="12" y1="12" x2="12" y2="25" stroke="{$dark}" stroke-width="1.2" opacity="0.28" />
                <line x1="12" y1="25" x2="25" y2="25" stroke="{$turq}" stroke-width="1.2" opacity="0.55" />
                <line x1="38" y1="12" x2="38" y2="38" stroke="{$dark}" stroke-width="1.2" opacity="0.22" />
                <line x1="25" y1="25" x2="38" y2="38" stroke="{$dark}" stroke-width="1.2" opacity="0.22" />
            </svg>
            SVG;

        echo '<div>';
        echo '<h1><span class="minox-wc-wordmark-main">'.esc_html__('Minox Koppeling', 'kb-data-koppeling-voor-woocommerce').'</span>';
        echo '<span class="minox-wc-wordmark-sub">'.esc_html__('KB Data Solutions', 'kb-data-koppeling-voor-woocommerce').'</span></h1>';
        echo '</div></div>';
    }

    private static function renderStepIndicator(int $step): void
    {
        $labels = [
            1 => __('Account koppelen', 'kb-data-koppeling-voor-woocommerce'),
            2 => __('Instellingen', 'kb-data-koppeling-voor-woocommerce'),
            3 => __('Klaar', 'kb-data-koppeling-voor-woocommerce'),
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
                $connectionError = __('Onbekende fout bij het testen van de verbinding.', 'kb-data-koppeling-voor-woocommerce');
            }
        }

        if (!$integration->isConfigured()) {
            self::renderIntro();
        }

        echo '<h2>'.esc_html__('Koppel je Minox-account', 'kb-data-koppeling-voor-woocommerce').'</h2>';
        echo '<p>'.esc_html__('Deze gegevens heb je ontvangen van KB Data Solutions bij het aanmaken van je koppeling.', 'kb-data-koppeling-voor-woocommerce').'</p>';

        if ($connectionError !== null) {
            echo '<div class="notice notice-error"><p>'.esc_html($connectionError).'</p></div>';
        } elseif ($integration->isConfigured()) {
            echo '<div class="notice notice-success"><p>'.esc_html__('Verbinding werkt.', 'kb-data-koppeling-voor-woocommerce').'</p></div>';
        }

        self::formOpen(1);
        self::textField('client_id', __('Client ID', 'kb-data-koppeling-voor-woocommerce'), $integration->get_option('client_id'));
        self::textField('client_secret', __('Client Secret', 'kb-data-koppeling-voor-woocommerce'), $integration->get_option('client_secret'), 'password');
        self::submitButton(__('Opslaan en testen', 'kb-data-koppeling-voor-woocommerce'));
        self::formClose();
    }

    private static function renderIntro(): void
    {
        echo '<div class="minox-wc-intro">';
        echo '<p class="minox-wc-intro-title">'.esc_html__('Wat doet deze plugin?', 'kb-data-koppeling-voor-woocommerce').'</p>';
        echo '<p>'.esc_html__('KB Data Solutions bouwt koppelingen tussen webshops en boekhoudpakketten. Deze plugin boekt elke bestelling uit deze webshop automatisch en correct in uw Minox-administratie.', 'kb-data-koppeling-voor-woocommerce').'</p>';

        echo '<ul class="minox-wc-benefits">';
        foreach ([
            __('Automatische boeking per bestelling, geen handmatig overtypen', 'kb-data-koppeling-voor-woocommerce'),
            __('OSS- en ICP-btw automatisch herkend en correct toegepast', 'kb-data-koppeling-voor-woocommerce'),
            __('Factuur automatisch meegeboekt als bijlage', 'kb-data-koppeling-voor-woocommerce'),
        ] as $benefit) {
            echo '<li>'.esc_html($benefit).'</li>';
        }
        echo '</ul>';

        echo '<p>'.esc_html__('Heeft u nog geen Client ID en Secret?', 'kb-data-koppeling-voor-woocommerce').' ';
        echo '<a href="'.esc_url('https://www.kbdatasolutions.nl/woocommerce-koppeling').'" target="_blank" rel="noopener">'
            .esc_html__('Bekijk hoe de koppeling werkt en vraag toegang aan', 'kb-data-koppeling-voor-woocommerce').'</a>.</p>';
        echo '</div>';
    }

    private static function renderStep2(): void
    {
        $integration = Integration::instance();

        echo '<h2>'.esc_html__('Wanneer moet de factuur verstuurd worden?', 'kb-data-koppeling-voor-woocommerce').'</h2>';

        self::formOpen(2);
        echo '<p><label><input type="radio" name="sync_trigger_status" value="processing" '
            .checked($integration->syncTriggerStatus(), 'processing', false).'> '
            .esc_html__('In behandeling (betaling ontvangen), aanbevolen', 'kb-data-koppeling-voor-woocommerce').'</label></p>';
        echo '<p><label><input type="radio" name="sync_trigger_status" value="completed" '
            .checked($integration->syncTriggerStatus(), 'completed', false).'> '
            .esc_html__('Afgerond (verzonden/opgehaald)', 'kb-data-koppeling-voor-woocommerce').'</label></p>';

        if (!InvoicePdfResolver::isAvailable()) {
            self::renderPdfPluginMissingNotice();
        } else {
            echo '<div class="notice notice-success"><p>'.esc_html__('Factuur-PDF-plugin gedetecteerd, PDF\'s worden automatisch meegestuurd.', 'kb-data-koppeling-voor-woocommerce').'</p></div>';
            self::renderCreditNotePluginMissingNotice();
        }

        self::renderSyncRefundsField($integration);

        echo VatNumberSourceSettings::render($integration->vatNumberMetaKey(), 'vat_number_meta_key');

        self::submitButton(__('Volgende', 'kb-data-koppeling-voor-woocommerce'));
        self::formClose();
    }

    private static function renderStep3(): void
    {
        echo '<div class="minox-wc-success-banner">';
        echo '<div class="minox-wc-check">✓</div>';
        echo '<h2>'.esc_html__('Klaar!', 'kb-data-koppeling-voor-woocommerce').'</h2>';
        echo '<p>'.esc_html__('Minox Koppeling is ingesteld. Je kunt alles later nog aanpassen via WooCommerce → Minox Koppeling.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=integration&section=minox_koppeling')).'">'
            .esc_html__('Naar de instellingen', 'kb-data-koppeling-voor-woocommerce').'</a></p>';
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
            .esc_html__('Wijzigen', 'kb-data-koppeling-voor-woocommerce').'</label>';
        echo '<input type="text" name="'.esc_attr($name).'" value="" class="regular-text minox-wc-credential-input" '
            .'placeholder="'.esc_attr__('Laat leeg om de huidige waarde te behouden', 'kb-data-koppeling-voor-woocommerce').'" '
            .'autocomplete="off">';
        echo '</div>';
    }

    private static function submitButton(string $label): void
    {
        echo '<p><button type="submit" class="button button-primary">'.esc_html($label).'</button></p>';
    }

    private static function renderSyncRefundsField(Integration $integration): void
    {
        echo '<div class="minox-wc-field"><label>'.esc_html__('Terugbetalingen', 'kb-data-koppeling-voor-woocommerce').'</label>';
        echo '<input type="hidden" name="sync_refunds" value="no">';
        echo '<p><label><input type="checkbox" name="sync_refunds" value="yes" '
            .checked($integration->syncRefunds(), true, false).'> '
            .esc_html__('Boek terugbetalingen als creditfactuur in Minox', 'kb-data-koppeling-voor-woocommerce').'</label></p>';
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
                esc_html__('Creditfacturen bij terugbetalingen worden alleen als PDF meegestuurd met %s. Zonder die uitbreiding worden terugbetalingen gewoon geboekt in Minox, alleen zonder bijgevoegde creditfactuur.', 'kb-data-koppeling-voor-woocommerce'),
                '<a href="'.esc_url(InvoicePdfResolver::PRO_PLUGIN_URL).'" target="_blank" rel="noopener">PDF Invoices &amp; Packing Slips Professional</a>',
            ),
            sprintf(
                esc_html__('Gebruikt u een andere plugin voor (credit)facturen? %s, dan kijken we of we die kunnen ondersteunen.', 'kb-data-koppeling-voor-woocommerce'),
                '<a href="'.esc_url('mailto:info@kbdatasolutions.nl').'">'
                    .esc_html__('Laat het ons weten', 'kb-data-koppeling-voor-woocommerce').'</a>',
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
                esc_html__('We hebben op deze webshop nog geen facturen-PDF-plugin gevonden. Installeer %s om automatisch een PDF-factuur mee te sturen naar de boeking in Minox. Zonder zo\'n plugin blijft de boeking gewoon geldig, alleen zonder bijgevoegd document.', 'kb-data-koppeling-voor-woocommerce'),
                '<a href="'.esc_url('https://wordpress.org/plugins/'.InvoicePdfResolver::RECOMMENDED_PLUGIN_SLUG.'/').'" target="_blank" rel="noopener">PDF Invoices &amp; Packing Slips for WooCommerce</a>',
            ),
            sprintf(
                esc_html__('Gebruikt u al een andere facturen-plugin en denkt u dat we die ten onrechte niet herkennen? %s, dan kijken we graag met u mee.', 'kb-data-koppeling-voor-woocommerce'),
                '<a href="'.esc_url('mailto:info@kbdatasolutions.nl').'">'
                    .esc_html__('Neem dan gerust contact met ons op', 'kb-data-koppeling-voor-woocommerce').'</a>',
            ),
        );
    }
}
