<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Security;

use MinoxWooCommerce\Security\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
{
    public function test_encrypt_then_decrypt_round_trips_to_the_original_value(): void
    {
        $plaintext = 'minox-client-id-abc123xyz789';

        $ciphertext = CredentialCipher::encrypt($plaintext);

        $this->assertNotSame($plaintext, $ciphertext);
        $this->assertSame($plaintext, CredentialCipher::decrypt($ciphertext));
    }

    public function test_encrypt_output_is_not_deterministic(): void
    {
        $plaintext = 'same-secret-value';

        $first = CredentialCipher::encrypt($plaintext);
        $second = CredentialCipher::encrypt($plaintext);

        $this->assertNotSame($first, $second);
        $this->assertSame($plaintext, CredentialCipher::decrypt($first));
        $this->assertSame($plaintext, CredentialCipher::decrypt($second));
    }

    public function test_decrypt_returns_pre_encryption_plaintext_values_unchanged(): void
    {
        $this->assertSame('legacy-plaintext-client-id', CredentialCipher::decrypt('legacy-plaintext-client-id'));
    }

    public function test_decrypt_of_empty_string_returns_empty_string(): void
    {
        $this->assertSame('', CredentialCipher::decrypt(''));
    }

    public function test_encrypt_of_empty_string_returns_empty_string(): void
    {
        $this->assertSame('', CredentialCipher::encrypt(''));
    }

    public function test_decrypt_of_corrupted_ciphertext_returns_empty_string_rather_than_throwing(): void
    {
        $this->assertSame('', CredentialCipher::decrypt('sodium:v1:not-valid-base64-ciphertext'));
    }

    public function test_mask_with_reveal_tail_shows_only_the_last_4_characters(): void
    {
        $masked = CredentialCipher::mask('01a1b279-4c89-70f2-a03b-2880d587d407', true);

        $this->assertStringEndsWith('d407', $masked);
        $this->assertStringNotContainsString('01a1b279', $masked);
    }

    public function test_mask_without_reveal_tail_shows_nothing_of_the_value(): void
    {
        $masked = CredentialCipher::mask('super-secret-value-dont-show-any-of-this', false);

        $this->assertStringNotContainsString('super-secret', $masked);
        $this->assertStringNotContainsString('this', $masked);
        $this->assertSame('Ingesteld (verborgen)', $masked);
    }

    public function test_mask_of_empty_value_shows_not_yet_configured_regardless_of_reveal_tail(): void
    {
        $this->assertSame('(nog niet ingesteld)', CredentialCipher::mask('', true));
        $this->assertSame('(nog niet ingesteld)', CredentialCipher::mask('', false));
    }
}
