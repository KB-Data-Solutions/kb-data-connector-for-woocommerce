<?php

/**
 * Plugin Name:       Minox Koppeling voor WooCommerce
 * Plugin URI:        https://www.kbdatasolutions.nl/
 * Description:       Boekt elke WooCommerce-bestelling automatisch en correct in uw Minox-administratie, inclusief btw-classificatie en factuur. Gebouwd door KB Data Solutions (kbdatasolutions.nl). Vereist een actief KB Data Solutions-account.
 * x-release-please-start-version
 * Version:           0.9.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * WC requires at least: 8.0
 * Author:            KB Data Solutions
 * Author URI:        https://www.kbdatasolutions.nl/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kb-data-koppeling-voor-woocommerce
 * Requires Plugins:  woocommerce
 */

declare(strict_types=1);

namespace MinoxWooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

const MINOX_WC_VERSION = '0.9.0';
// x-release-please-end
const MINOX_WC_PLUGIN_FILE = __FILE__;
const MINOX_WC_PLUGIN_DIR = __DIR__;

$minoxWcAutoloader = file_exists(__DIR__.'/vendor-prefixed/autoload.php')
    ? __DIR__.'/vendor-prefixed/autoload.php'
    : __DIR__.'/vendor/autoload.php';

if (!file_exists($minoxWcAutoloader)) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Minox Koppeling voor WooCommerce kan niet starten: de Composer-afhankelijkheden zijn niet geïnstalleerd. Dit is een ontwikkelversie, draai "composer install" in de pluginmap.',
            'kb-data-koppeling-voor-woocommerce',
        );
        echo '</p></div>';
    });

    return;
}

require_once $minoxWcAutoloader;

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists(\WooCommerce::class)) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Minox Koppeling voor WooCommerce vereist dat WooCommerce actief is.',
                'kb-data-koppeling-voor-woocommerce',
            );
            echo '</p></div>';
        });

        return;
    }

    Plugin::boot();
}, 20);

register_activation_hook(__FILE__, [Plugin::class, 'onActivation']);
