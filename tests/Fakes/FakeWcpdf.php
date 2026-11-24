<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Fakes;

final class FakeWcpdf
{
    /** @var (callable(string, object, bool): (object|false))|null */
    public static $getDocument = null;

    /** @var (callable(object, string, string): (string|false))|null */
    public static $getDocumentFile = null;

    public static function reset(): void
    {
        self::$getDocument = null;
        self::$getDocumentFile = null;
    }
}
