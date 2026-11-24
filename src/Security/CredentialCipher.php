<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Security;

use RuntimeException;

final class CredentialCipher
{
    private const string PREFIX = 'sodium:v1:';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::key());

        return self::PREFIX.base64_encode($nonce.$ciphertext);
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public static function mask(string $value, bool $revealTail): string
    {
        if ($value === '') {
            return __('(not set yet)', 'kb-data-connector-for-woocommerce');
        }

        if (!$revealTail) {
            return __('Set (hidden)', 'kb-data-connector-for-woocommerce');
        }

        $tail = substr($value, -4);
        $maskedLength = max(0, strlen($value) - strlen($tail));

        return str_repeat('•', $maskedLength).$tail;
    }

    public static function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $decoded = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::key());

        return $plaintext === false ? '' : $plaintext;
    }

    private static function key(): string
    {
        $material = (\defined('AUTH_KEY') ? AUTH_KEY : '').(\defined('AUTH_SALT') ? AUTH_SALT : '');

        if ($material === '') {
            throw new RuntimeException(
                'AUTH_KEY and AUTH_SALT must be configured in wp-config.php to encrypt Minox credentials at rest.',
            );
        }

        return sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
