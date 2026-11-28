<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Setup;

use MinoxWooCommerce\Security\CredentialCipher;

final class CredentialField
{
    public static function render(string $name, string $label, string $storedValue, bool $revealTail, string $inputType = 'text'): string
    {
        if ($storedValue === '') {
            return '<div class="minox-wc-field"><label for="'.esc_attr($name).'">'.esc_html($label).'</label>'
                .'<input type="'.esc_attr($inputType).'" id="'.esc_attr($name).'" name="'.esc_attr($name).'" value="" class="regular-text" autocomplete="off"></div>';
        }

        $toggleId = 'minox-wc-toggle-'.$name;

        return '<div class="minox-wc-field minox-wc-credential-field">'
            .'<label>'.esc_html($label).'</label>'
            .'<p class="minox-wc-credential-masked">'.esc_html(CredentialCipher::mask($storedValue, $revealTail)).'</p>'
            .'<input type="checkbox" id="'.esc_attr($toggleId).'" class="minox-wc-credential-toggle">'
            .'<label for="'.esc_attr($toggleId).'" class="minox-wc-credential-toggle-label">'
            .esc_html__('Change', 'kb-data-connector-for-woocommerce').'</label>'
            .'<input type="'.esc_attr($inputType).'" name="'.esc_attr($name).'" value="" class="regular-text minox-wc-credential-input" '
            .'placeholder="'.esc_attr__('Leave empty to keep the current value', 'kb-data-connector-for-woocommerce').'" '
            .'autocomplete="off">'
            .'</div>';
    }
}
