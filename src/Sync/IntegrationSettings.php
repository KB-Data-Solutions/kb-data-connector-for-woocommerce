<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use KBDataSolutions\Sdk\Client;

interface IntegrationSettings
{
    public function isConfigured(): bool;

    public function syncTriggerStatus(): string;

    public function attachPdfInvoice(): bool;

    public function syncRefunds(): bool;

    public function vatNumberMetaKey(): ?string;

    public function makeClient(): ?Client;
}
