<?php

declare(strict_types=1);

namespace MinoxWooCommerce;

use KBDataSolutions\Sdk\Client;
use MinoxWooCommerce\Security\CredentialCipher;
use MinoxWooCommerce\Setup\WizardPage;
use MinoxWooCommerce\Sync\IntegrationSettings;
use MinoxWooCommerce\Sync\VatNumberResolver;
use RuntimeException;
use WC_Integration;

final class Integration extends WC_Integration implements IntegrationSettings
{
    public const string PRODUCTION_BASE_URI = 'https://app.kbdatasolutions.nl';

    public const string PLATFORM = 'woocommerce';

    private const array ENCRYPTED_FIELDS = ['client_id', 'client_secret'];

    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]', 'host.docker.internal'];

    private bool $unreadableCredentials = false;

    public static function baseUri(): ?string
    {
        if (!\defined('KBDATA_KOPPELING_BASE_URI')) {
            return self::PRODUCTION_BASE_URI;
        }

        $override = (string) \constant('KBDATA_KOPPELING_BASE_URI');

        return self::isSafeBaseUri($override) ? rtrim($override, '/') : null;
    }

    public static function hasUnsafeBaseUriOverride(): bool
    {
        return \defined('KBDATA_KOPPELING_BASE_URI') && self::baseUri() === null;
    }

    public static function isSafeBaseUri(string $uri): bool
    {
        $parts = parse_url($uri);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && (in_array($host, self::LOCAL_HOSTS, true) || str_ends_with($host, '.localhost'));
    }

    public function __construct()
    {
        $this->id = 'minox_koppeling';
        $this->method_title = __('Minox Connector', 'kb-data-connector-for-woocommerce');
        $this->method_description = __(
            'Sends customers and invoices to Minox automatically.',
            'kb-data-connector-for-woocommerce',
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
                continue;
            }

            try {
                $decrypted = CredentialCipher::decrypt($this->settings[$field]);
            } catch (RuntimeException) {
                $decrypted = '';
            }

            if ($decrypted === '') {
                $this->unreadableCredentials = true;
            }

            $this->settings[$field] = $decrypted;
        }

        if ($needsMigration && !$this->unreadableCredentials) {
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
        echo '<p>'.esc_html__('The settings of this connector are managed on their own page.', 'kb-data-connector-for-woocommerce').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(WizardPage::settingsUrl()).'">'
            .esc_html__('Go to the Minox Connector settings', 'kb-data-connector-for-woocommerce').'</a></p>';
    }

    public function hasUnreadableCredentials(): bool
    {
        return $this->unreadableCredentials;
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
        $baseUri = self::baseUri();

        if (!$this->isConfigured() || $baseUri === null) {
            return null;
        }

        return Client::create(
            clientId: (string) $this->get_option('client_id'),
            clientSecret: (string) $this->get_option('client_secret'),
            baseUri: $baseUri,
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
