<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Fakes;

use KBDataSolutions\Sdk\Http\Transport;
use KBDataSolutions\Sdk\Http\TransportRequest;
use KBDataSolutions\Sdk\Http\TransportResponse;

final class FakeTransport implements Transport
{
    /** @var list<TransportResponse> */
    private array $queue = [];

    /** @var list<TransportRequest> */
    private array $sent = [];

    public function queueResponse(TransportResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->sent[] = $request;

        return array_shift($this->queue) ?? new TransportResponse(200, [], '{"data":{}}');
    }

    /** @return list<TransportRequest> */
    public function sentRequests(): array
    {
        return $this->sent;
    }

    public function lastRequest(): ?TransportRequest
    {
        return $this->sent[array_key_last($this->sent)] ?? null;
    }
}
