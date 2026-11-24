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
            .esc_html__('Optioneel: zakelijke EU-verkopen (ICP)', 'kb-data-koppeling-voor-woocommerce').'</summary>';
        $html .= '<p>'.esc_html__('Verkoopt u alleen aan consumenten? Hiervoor is geen actie nodig. Voor zakelijke EU-verkopen moet het btw-nummer als ordergegeven beschikbaar zijn. Een herkend veld is geen bewijs dat het nummer geldig is.', 'kb-data-koppeling-voor-woocommerce').'</p>';

        if ($metaKey !== null) {
            $html .= '<p><strong>'.esc_html__('Expliciete btw-veldnaam ingesteld', 'kb-data-koppeling-voor-woocommerce').'</strong> '
                .esc_html__('Alleen dit veld wordt gebruikt. Ontbreekt het bij een bestelling, dan wordt geen ander veld gekozen.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        } elseif ($detected !== []) {
            $html .= '<p><strong>'.esc_html__('Btw-nummerbron herkend in recente bestellingen', 'kb-data-koppeling-voor-woocommerce').'</strong> '
                .esc_html__('Bekende ordervelden worden automatisch gelezen. Verschillende niet-lege btw-nummers blokkeren de verzending totdat de juiste bron is gekozen.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        } else {
            $html .= '<p><strong>'.esc_html__('Geen btw-nummerbron herkend', 'kb-data-koppeling-voor-woocommerce').'</strong> '
                .esc_html__('In maximaal twintig recente bestellingen is geen bekend veld gevonden. Dit betekent niet dat een btw-plugin ontbreekt.', 'kb-data-koppeling-voor-woocommerce').'</p>';
            $html .= '<p>'.esc_html__('Gebruik voor zakelijke EU-verkopen een gevalideerd btw-nummerveld, bijvoorbeeld met', 'kb-data-koppeling-voor-woocommerce').' '
                .'<a href="'.esc_url('https://woocommerce.com/document/eu-vat-number/').'" target="_blank" rel="noopener noreferrer">'
                .esc_html__('WooCommerce EU VAT Number', 'kb-data-koppeling-voor-woocommerce').'</a>. '
                .esc_html__('Er wordt niets automatisch geïnstalleerd.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        }

        $html .= '<p>'.esc_html__('Gebruikt u al een andere plugin? U hoeft die niet te vervangen; we helpen het juiste orderveld te koppelen.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        $html .= '<p><label for="'.esc_attr($fieldName).'">'.esc_html__('Afwijkende btw-veldnaam (optioneel)', 'kb-data-koppeling-voor-woocommerce').'</label><br>';
        $html .= '<input type="text" class="regular-text" id="'.esc_attr($fieldName).'" name="'.esc_attr($fieldName).'" value="'.esc_attr($metaKey ?? '').'" maxlength="64" autocomplete="off">';
        $html .= '<br>'.esc_html__('Laat leeg voor automatische herkenning. Vul alleen de technische orderveldnaam in, nooit een btw-nummer of sleutel. De koppeling wijzigt geen bedragen of btw-tarieven in WooCommerce.', 'kb-data-koppeling-voor-woocommerce').'</p>';
        $html .= '<p><a href="'.esc_url('mailto:info@kbdatasolutions.nl').'">'
            .esc_html__('Hulp nodig? Neem contact op', 'kb-data-koppeling-voor-woocommerce').'</a></p></details>';

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
