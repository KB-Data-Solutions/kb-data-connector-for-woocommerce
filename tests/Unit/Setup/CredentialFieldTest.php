<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Setup;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MinoxWooCommerce\Setup\CredentialField;
use Mockery;
use PHPUnit\Framework\TestCase;

final class CredentialFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        Functions\when('esc_html')->alias($escape);
        Functions\when('esc_attr')->alias($escape);
        Functions\when('esc_html__')->alias($escape);
        Functions\when('esc_attr__')->alias($escape);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_stored_secret_never_reaches_the_page(): void
    {
        $html = CredentialField::render('client_secret', 'Client Secret', 'super-secret-value-1234', false, 'password');

        self::assertStringNotContainsString('super-secret-value-1234', $html);
        self::assertStringNotContainsString('1234', $html);
        self::assertStringContainsString('Set (hidden)', $html);
        self::assertStringContainsString('name="client_secret" value=""', $html);
        self::assertStringContainsString('type="password"', $html);
    }

    public function test_a_stored_client_id_only_shows_its_last_four_characters(): void
    {
        $html = CredentialField::render('client_id', 'Client ID', 'a1b2c3d4-e5f6-f2d1', true);

        self::assertStringNotContainsString('a1b2c3d4', $html);
        self::assertStringContainsString('f2d1', $html);
        self::assertStringContainsString('name="client_id" value=""', $html);
    }

    public function test_an_empty_credential_renders_a_plain_empty_field(): void
    {
        $html = CredentialField::render('client_id', 'Client ID', '', true);

        self::assertStringNotContainsString('minox-wc-credential-masked', $html);
        self::assertStringContainsString('name="client_id" value=""', $html);
    }
}
