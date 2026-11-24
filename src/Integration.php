<?php

declare(strict_types=1);

namespace MinoxWooCommerce;

use KBDataSolutions\Sdk\Client;
use MinoxWooCommerce\Security\CredentialCipher;
use MinoxWooCommerce\Setup\WizardPage;
use MinoxWooCommerce\Sync\IntegrationSettings;
use MinoxWooCommerce\Sync\VatNumberResolver;
use WC_Integration;

final class Integration extends WC_Integration implements IntegrationSettings
{
    public const string PRODUCTION_BASE_URI = 'https://app.kbdatasolutions.nl';

    public const string PLATFORM = 'woocommerce';

    private const array ENCRYPTED_FIELDS = ['client_id', 'client_secret'];

    public static function baseUri(): string
    {
        return \defined('KBDATA_KOPPELING_BASE_URI')
            ? (string) \constant('KBDATA_KOPPELING_BASE_URI')
            : self::PRODUCTION_BASE_URI;
    }

    public function __construct()
    {
        $this->id = 'minox_koppeling';
        $this->method_title = __('Minox Koppeling', 'kb-data-koppeling-voor-woocommerce');
        $this->method_description = __(
            'Synchroniseert klanten en facturen automatisch naar Minox.',
            'kb-data-koppeling-voor-woocommerce',
        );

        $this->init_form_fields();
        $this->init_settings();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function encryptSettings(array $settings): array
    {
        $stored = get_option($this->get_option_key(), []);
        $stored = \is_array($stored) ? $stored : [];

        foreach (self::ENCRYPTED_FIELDS as $field) {
            $posted = isset($settings[$field]) && \is_string($settings[$field]) ? trim($settings[$field]) : '';

            $settings[$field] = $posted !== ''
                ? CredentialCipher::encrypt($posted)
                : ($stored[$field] ?? '');
        }

        return $settings;
    }

    public function init_settings(): void
    {
        parent::init_settings();

        $needsMigration = false;

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (!isset($this->settings[$field]) || !\is_string($this->settings[$field]) || $this->settings[$field] === '') {
                continue;
            }

            if (!CredentialCipher::isEncrypted($this->settings[$field])) {
                $needsMigration = true;
            }

            $this->settings[$field] = CredentialCipher::decrypt($this->settings[$field]);
        }

        if ($needsMigration) {
            update_option($this->get_option_key(), $this->encryptSettings($this->settings));
        }
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'client_id' => ['type' => 'text', 'default' => ''],
            'client_secret' => ['type' => 'text', 'default' => ''],
            'sync_trigger_status' => ['type' => 'select', 'default' => 'processing'],
            'attach_pdf_invoice' => ['type' => 'checkbox', 'default' => 'yes'],

            'sync_refunds' => ['type' => 'checkbox', 'default' => 'yes'],
            'vat_number_meta_key' => ['type' => 'text', 'default' => ''],
        ];
    }

    public function admin_options(): void
    {
        $GLOBALS['hide_save_button'] = true;

        echo '<h2>'.esc_html($this->get_method_title()).'</h2>';
        echo wp_kses_post(wpautop($this->get_method_description()));
        echo '<p>'.esc_html__('De instellingen van deze koppeling beheer je op een eigen pagina.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(WizardPage::settingsUrl()).'">'
            .esc_html__('Naar de instellingen van Minox Koppeling', 'kb-data-koppeling-voor-woocommerce').'</a></p>';
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->get_option('client_id')) !== ''
            && trim((string) $this->get_option('client_secret')) !== '';
    }

    public function syncTriggerStatus(): string
    {
        return (string) $this->get_option('sync_trigger_status', 'processing');
    }

    public function attachPdfInvoice(): bool
    {
        return $this->get_option('attach_pdf_invoice', 'yes') === 'yes';
    }

    public function syncRefunds(): bool
    {
        return $this->get_option('sync_refunds', 'yes') === 'yes';
    }

    public function vatNumberMetaKey(): ?string
    {
        return VatNumberResolver::validateMetaKey($this->get_option('vat_number_meta_key', ''));
    }

    public function makeClient(): ?Client
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return Client::create(
            clientId: (string) $this->get_option('client_id'),
            clientSecret: (string) $this->get_option('client_secret'),
            baseUri: self::baseUri(),
            platform: self::PLATFORM,
        );
    }

    public static function instance(): self
    {
        /** @var array<\WC_Integration> $integrations */
        $integrations = WC()->integrations->get_integrations();

        foreach ($integrations as $integration) {
            if ($integration instanceof self) {
                return $integration;
            }
        }

        return new self();
    }
}
