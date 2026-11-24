<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

final class ConnectionHealth
{
    public const string OPTION = 'minox_wc_connection_health';

    public static function recordSuccess(): void
    {
        $health = self::read();

        if ($health['auth_error'] === null && $health['last_success_at'] !== null && $health['last_success_at'] > time() - 300) {
            return;
        }

        update_option(self::OPTION, ['last_success_at' => time(), 'auth_error' => null, 'auth_error_at' => null], false);
    }

    public static function recordAuthFailure(string $message): void
    {
        $health = self::read();
        $health['auth_error'] = $message;
        $health['auth_error_at'] ??= time();

        update_option(self::OPTION, $health, false);
    }

    public static function authError(): ?string
    {
        return self::read()['auth_error'];
    }

    public static function lastSuccessAt(): ?int
    {
        return self::read()['last_success_at'];
    }

    public static function clearAuthError(): void
    {
        $health = self::read();
        $health['auth_error'] = null;
        $health['auth_error_at'] = null;

        update_option(self::OPTION, $health, false);
    }

    /** @return array{last_success_at: ?int, auth_error: ?string, auth_error_at: ?int} */
    private static function read(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'last_success_at' => isset($stored['last_success_at']) && is_int($stored['last_success_at']) ? $stored['last_success_at'] : null,
            'auth_error' => isset($stored['auth_error']) && is_string($stored['auth_error']) ? $stored['auth_error'] : null,
            'auth_error_at' => isset($stored['auth_error_at']) && is_int($stored['auth_error_at']) ? $stored['auth_error_at'] : null,
        ];
    }
}
