<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MinoxWooCommerce\Integration;
use MinoxWooCommerce\Security\CredentialCipher;
use Mockery;
use PHPUnit\Framework\TestCase;

final class UnreadableCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!\defined('AUTH_KEY')) {
            \define('AUTH_KEY', 'test-auth-key');
        }

        if (!\defined('AUTH_SALT')) {
            \define('AUTH_SALT', 'test-auth-salt');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_credentials_encrypted_with_other_security_keys_are_reported_and_never_rewritten(): void
    {
        $foreign = 'sodium:v1:'.base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32));
        Functions\when('get_option')->justReturn(['client_id' => $foreign, 'client_secret' => $foreign]);
        Functions\expect('update_option')->never();

        $integration = new Integration();

        self::assertTrue($integration->hasUnreadableCredentials());
        self::assertFalse($integration->isConfigured());
    }

    public function test_readable_credentials_are_not_reported(): void
    {
        Functions\when('get_option')->justReturn([
            'client_id' => CredentialCipher::encrypt('client-id-1234'),
            'client_secret' => CredentialCipher::encrypt('client-secret'),
        ]);

        $integration = new Integration();

        self::assertFalse($integration->hasUnreadableCredentials());
        self::assertTrue($integration->isConfigured());
        self::assertSame('client-secret', $integration->get_option('client_secret'));
    }
}
