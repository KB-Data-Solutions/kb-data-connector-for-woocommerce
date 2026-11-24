<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/Stubs/woocommerce-shims.php';

if (!\defined('AUTH_KEY')) {
    \define('AUTH_KEY', 'test-auth-key-not-for-production-use');
}

if (!\defined('AUTH_SALT')) {
    \define('AUTH_SALT', 'test-auth-salt-not-for-production-use');
}
