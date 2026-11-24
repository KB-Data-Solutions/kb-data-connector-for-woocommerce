<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit;

use MinoxWooCommerce\Integration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BaseUriTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function uris(): iterable
    {
        yield 'https' => ['https://staging.kbdatasolutions.nl', true];
        yield 'https with port' => ['https://staging.kbdatasolutions.nl:8443', true];
        yield 'plain http' => ['http://staging.kbdatasolutions.nl', false];
        yield 'http localhost' => ['http://localhost:8000', true];
        yield 'http loopback' => ['http://127.0.0.1', true];
        yield 'http docker host' => ['http://host.docker.internal', true];
        yield 'http dev subdomain' => ['http://kbdata.localhost', true];
        yield 'look-alike host' => ['http://localhost.attacker.example', false];
        yield 'credentials in url' => ['https://user:pass@staging.kbdatasolutions.nl', false];
        yield 'no scheme' => ['staging.kbdatasolutions.nl', false];
        yield 'other scheme' => ['ftp://staging.kbdatasolutions.nl', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('uris')]
    public function test_only_https_or_local_http_is_accepted(string $uri, bool $safe): void
    {
        self::assertSame($safe, Integration::isSafeBaseUri($uri));
    }
}
