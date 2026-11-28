<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Setup;

final class TaxRoundingNotice
{
    public static function roundsAtSubtotal(): bool
    {
        return get_option('woocommerce_tax_round_at_subtotal', 'no') === 'yes';
    }

    public static function render(): string
    {
        if (!self::roundsAtSubtotal()) {
            return '';
        }

        return '<div class="notice notice-warning inline minox-wc-tax-rounding-notice"><p>'
            .esc_html__('WooCommerce rounds VAT at subtotal level instead of per line. Minox calculates the VAT of OSS invoices per invoice line, so OSS invoices can differ by one cent. We recommend switching off "Round tax at subtotal level, instead of rounding per line".', 'kb-data-connector-for-woocommerce')
            .' <a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=tax')).'">'
            .esc_html__('Open the tax settings', 'kb-data-connector-for-woocommerce').'</a></p></div>';
    }
}
