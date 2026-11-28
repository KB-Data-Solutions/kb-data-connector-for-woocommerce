<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Support;

use Throwable;

final class ConnectorLog
{
    public const string SOURCE = 'kb-data-connector-for-woocommerce';

    /** @param array<string, scalar> $context */
    public static function warning(string $event, Throwable $exception, array $context = []): void
    {
        if (!\function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->warning(
            $event.': '.$exception->getMessage().' ('.$exception::class.')',
            ['source' => self::SOURCE] + $context,
        );
    }
}
