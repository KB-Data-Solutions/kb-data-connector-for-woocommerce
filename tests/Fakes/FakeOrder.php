<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Fakes;

use WC_Order;
use WC_Order_Refund;

final class FakeOrder extends WC_Order
{
    /** @var array<string, mixed> */
    public array $meta = [];

    /** @var list<string> */
    public array $notes = [];

    /** @param list<WC_Order_Refund> $refunds */
    public function __construct(private readonly int $id, public string $status = 'processing', private readonly array $refunds = [])
    {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function get_meta(string $key): mixed
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data(string $key): void
    {
        unset($this->meta[$key]);
    }

    public function add_order_note(string $note): void
    {
        $this->notes[] = $note;
    }

    /** @return array<int, WC_Order_Refund> */
    public function get_refunds(): array
    {
        return $this->refunds;
    }
}
