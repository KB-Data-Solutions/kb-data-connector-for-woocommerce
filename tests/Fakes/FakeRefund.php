<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Fakes;

use WC_Order_Refund;

final class FakeRefund extends WC_Order_Refund
{
    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct(private readonly int $id, private readonly int $parentId)
    {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_parent_id(): int
    {
        return $this->parentId;
    }

    public function get_meta(string $key): string
    {
        return (string) ($this->meta[$key] ?? '');
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data(string $key): void
    {
        unset($this->meta[$key]);
    }
}
