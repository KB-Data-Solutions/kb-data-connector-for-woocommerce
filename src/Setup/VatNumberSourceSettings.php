<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Setup;

use MinoxWooCommerce\Sync\VatNumberResolver;
use Throwable;
use WC_Order;

final class VatNumberSourceSettings
{
    public static function render(?string $metaKey, string $fieldName): string
    {
        $detected = $metaKey === null ? self::detectedSources() : [];
        $html = '<details class="minox-wc-field"><summary>'
            .esc_html__('Optional: B2B sales within the EU (intra-community)', 'kb-data-connector-for-woocommerce').'</summary>';
        $html .= '<p>'.esc_html__('Selling to consumers only? No action needed. For B2B sales within the EU the VAT number must be available as order data. A recognised field is no proof that the number is valid.', 'kb-data-connector-for-woocommerce').'</p>';

        if ($metaKey !== null) {
            $html .= '<p><strong>'.esc_html__('Explicit VAT field name set', 'kb-data-connector-for-woocommerce').'</strong> '
                .esc_html__('Only this field is used. If an order lacks it, no other field is chosen.', 'kb-data-connector-for-woocommerce').'</p>';
        } elseif ($detected !== []) {
            $html .= '<p><strong>'.esc_html__('VAT number source recognised in recent orders', 'kb-data-connector-for-woocommerce').'</strong> '
                .esc_html__('Known order fields are read automatically. Different non-empty VAT numbers block sending until the right source is chosen.', 'kb-data-connector-for-woocommerce').'</p>';
        } else {
            $html .= '<p><strong>'.esc_html__('No VAT number source recognised', 'kb-data-connector-for-woocommerce').'</strong> '
                .esc_html__('No known field was found in up to twenty recent orders. This does not mean a VAT plugin is missing.', 'kb-data-connector-for-woocommerce').'</p>';
            $html .= '<p>'.esc_html__('For B2B sales within the EU, use a validated VAT number field, for example with', 'kb-data-connector-for-woocommerce').' '
                .'<a href="'.esc_url('https://woocommerce.com/document/eu-vat-number/').'" target="_blank" rel="noopener noreferrer">'
                .esc_html__('WooCommerce EU VAT Number', 'kb-data-connector-for-woocommerce').'</a>. '
                .esc_html__('Nothing is installed automatically.', 'kb-data-connector-for-woocommerce').'</p>';
        }

        $html .= '<p>'.esc_html__('Already using another plugin? No need to replace it; we help connect the right order field.', 'kb-data-connector-for-woocommerce').'</p>';
        $html .= '<p><label for="'.esc_attr($fieldName).'">'.esc_html__('Custom VAT field name (optional)', 'kb-data-connector-for-woocommerce').'</label><br>';
        $html .= '<input type="text" class="regular-text" id="'.esc_attr($fieldName).'" name="'.esc_attr($fieldName).'" value="'.esc_attr($metaKey ?? '').'" maxlength="64" autocomplete="off">';
        $html .= '<br>'.esc_html__('Leave empty for automatic detection. Only enter the technical order field name, never a VAT number or key. The connector does not change amounts or VAT rates in WooCommerce.', 'kb-data-connector-for-woocommerce').'</p>';
        $html .= '<p><a href="'.esc_url('mailto:info@kbdatasolutions.nl').'">'
            .esc_html__('Need help? Get in touch', 'kb-data-connector-for-woocommerce').'</a></p></details>';

        return $html;
    }

    /** @return list<string> */
    private static function detectedSources(): array
    {
        try {
            $orders = wc_get_orders(['limit' => 20, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects']);

            if (!is_array($orders)) {
                return [];
            }

            return (new VatNumberResolver())->detectedMetaKeys(array_filter($orders, static fn (mixed $order): bool => $order instanceof WC_Order));
        } catch (Throwable) {
            return [];
        }
    }
}
