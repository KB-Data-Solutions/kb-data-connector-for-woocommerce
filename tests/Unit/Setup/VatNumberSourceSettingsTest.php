<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Setup;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MinoxWooCommerce\Setup\VatNumberSourceSettings;
use Mockery;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class VatNumberSourceSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        Functions\when('esc_html')->alias($escape);
        Functions\when('esc_attr')->alias($escape);
        Functions\when('esc_html__')->alias($escape);
        Functions\when('esc_url')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_unknown_source_is_optional_calm_and_offers_recommendation_and_contact(): void
    {
        Functions\expect('wc_get_orders')->once()->with(['limit' => 20, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects'])->andReturn([]);
        $html = VatNumberSourceSettings::render(null, 'vat_number_meta_key');
        self::assertStringContainsString('<details', $html);
        self::assertStringNotContainsString('<details open', $html);
        self::assertStringContainsString('Geen btw-nummerbron herkend', $html);
        self::assertStringContainsString('alleen aan consumenten', $html);
        self::assertStringContainsString('https://woocommerce.com/document/eu-vat-number/', $html);
        self::assertStringContainsString('mailto:info@kbdatasolutions.nl', $html);
        self::assertStringContainsString('Hulp nodig? Neem contact op', $html);
        self::assertStringContainsString('niet te vervangen', $html);
        self::assertStringNotContainsString('notice-warning', $html);
    }

    public function test_detected_source_does_not_claim_a_plugin_or_a_validated_number(): void
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('meta_exists')->andReturnUsing(static fn (string $key): bool => $key === '_vat_number');
        $order->shouldNotReceive('get_meta');
        Functions\expect('wc_get_orders')->once()->andReturn([$order]);
        $html = VatNumberSourceSettings::render(null, 'vat_number_meta_key');
        self::assertStringContainsString('Btw-nummerbron herkend in recente bestellingen', $html);
        self::assertStringContainsString('geen bewijs', $html);
        self::assertStringNotContainsString('WooCommerce EU VAT Number', $html);
    }

    public function test_override_is_shown_without_scanning_other_order_sources(): void
    {
        Functions\expect('wc_get_orders')->never();
        $html = VatNumberSourceSettings::render('_custom_tax_id', 'vat_number_meta_key');
        self::assertStringContainsString('Expliciete btw-veldnaam ingesteld', $html);
        self::assertStringContainsString('value="_custom_tax_id"', $html);
        self::assertStringContainsString('geen ander veld', $html);
    }

    public function test_dynamic_field_attributes_and_text_are_escaped(): void
    {
        Functions\expect('wc_get_orders')->once()->andReturn([]);
        $html = VatNumberSourceSettings::render(null, 'field"><script>alert(1)</script>');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('field&quot;&gt;&lt;script&gt;', $html);
    }
}
