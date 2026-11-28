<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MinoxWooCommerce\Support\ConnectorLog;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectorLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_writes_the_failure_to_the_woocommerce_log_under_the_connector_source(): void
    {
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->with(
            'Payment confirmation not sent: Missing string field status. (RuntimeException)',
            ['source' => ConnectorLog::SOURCE, 'order_id' => 42],
        );
        Functions\when('wc_get_logger')->justReturn($logger);

        ConnectorLog::warning('Payment confirmation not sent', new RuntimeException('Missing string field status.'), ['order_id' => 42]);

        self::addToAssertionCount(1);
    }
}
