<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Setup;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MinoxWooCommerce\Setup\TaxRoundingNotice;
use Mockery;
use PHPUnit\Framework\TestCase;

final class TaxRoundingNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('esc_html__')->alias(static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->alias(static fn (string $path): string => 'https://shop.test/wp-admin/'.$path);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_warns_with_a_link_to_the_tax_settings_when_tax_is_rounded_at_subtotal(): void
    {
        Functions\expect('get_option')->with('woocommerce_tax_round_at_subtotal', 'no')->andReturn('yes');

        $html = TaxRoundingNotice::render();

        self::assertStringContainsString('notice-warning', $html);
        self::assertStringContainsString('one cent', $html);
        self::assertStringContainsString('https://shop.test/wp-admin/admin.php?page=wc-settings&tab=tax', $html);
    }

    public function test_stays_silent_when_tax_is_rounded_per_line(): void
    {
        Functions\expect('get_option')->with('woocommerce_tax_round_at_subtotal', 'no')->andReturn('no');

        self::assertSame('', TaxRoundingNotice::render());
    }
}
