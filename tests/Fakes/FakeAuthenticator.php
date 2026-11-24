<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Fakes;

use KBDataSolutions\Sdk\Authentication\AccessToken;
use KBDataSolutions\Sdk\Authentication\Authenticator;
use DateTimeImmutable;

final class FakeAuthenticator implements Authenticator
{
    public function accessToken(): AccessToken
    {
        return new AccessToken('fake-token', new DateTimeImmutable('+1 hour'), []);
    }

    public function forceRefresh(): AccessToken
    {
        return $this->accessToken();
    }
}
