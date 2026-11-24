<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Tests\Unit\Sync;

use InvalidArgumentException;
use MinoxWooCommerce\Sync\VatNumberResolver;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WC_Order;

final class VatNumberResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[DataProvider('commonKeys')]
    public function test_recognizes_a_common_order_meta_key(string $key): void
    {
        self::assertSame('DE123456789', (new VatNumberResolver())->resolve($this->order([$key => ' de 123.456-789 '])));
    }

    /** @return array<string, array{string}> */
    public static function commonKeys(): array
    {
        return array_combine(VatNumberResolver::COMMON_META_KEYS, array_map(static fn (string $key): array => [$key], VatNumberResolver::COMMON_META_KEYS));
    }

    public function test_normalizes_equal_values_from_multiple_sources_without_a_conflict(): void
    {
        self::assertSame('DE123456789', (new VatNumberResolver())->resolve($this->order([
            '_vat_number' => 'de 123.456.789', '_billing_vat_number' => 'DE123456789', 'billing_vat_number' => ' DE123-456-789 ',
        ])));
    }

    public function test_missing_and_blank_sources_do_not_invent_a_vat_number(): void
    {
        self::assertNull((new VatNumberResolver())->resolve($this->order(['_vat_number' => '   '])));
    }

    public function test_conflicting_sources_fail_without_disclosing_either_value(): void
    {
        try {
            (new VatNumberResolver())->resolve($this->order(['_vat_number' => 'DE123456789', '_billing_vat_number' => 'BE0123456789']));
            self::fail('Conflicting VAT numbers must block mapping.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('different values', $exception->getMessage());
            self::assertStringNotContainsString('DE123456789', $exception->getMessage());
            self::assertStringNotContainsString('BE0123456789', $exception->getMessage());
        }
    }

    public function test_explicit_field_is_authoritative_even_if_automatic_sources_conflict(): void
    {
        self::assertSame('FR12345678901', (new VatNumberResolver('_custom_tax_id'))->resolve($this->order([
            '_vat_number' => 'DE123456789', '_billing_vat_number' => 'BE0123456789', '_custom_tax_id' => 'fr 12345678901',
        ])));
    }

    public function test_missing_explicit_field_never_falls_back_to_automatic_sources(): void
    {
        self::assertNull((new VatNumberResolver('_custom_tax_id'))->resolve($this->order(['_vat_number' => 'DE123456789'])));
    }

    public function test_non_text_meta_is_rejected_without_printing_its_contents(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contains no text');
        (new VatNumberResolver())->resolve($this->order(['_vat_number' => ['private-value']]));
    }

    #[DataProvider('unsafeKeys')]
    public function test_rejects_unsafe_or_unbounded_override_keys(mixed $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        VatNumberResolver::validateMetaKey($key);
    }

    /** @return list<array{mixed}> */
    public static function unsafeKeys(): array
    {
        return [[['field']], ['vat field'], ['vat" onfocus="alert(1)'], ['<script>'], ['meta.key'], [str_repeat('a', 65)]];
    }

    public function test_empty_override_uses_automatic_detection(): void
    {
        self::assertNull(VatNumberResolver::validateMetaKey('  '));
        self::assertSame('_custom_tax_id', VatNumberResolver::validateMetaKey(' _custom_tax_id '));
    }

    public function test_detection_reads_only_known_key_presence_without_reading_values(): void
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('meta_exists')->andReturnUsing(static fn (string $key): bool => $key === '_billing_vat_number');
        $order->shouldNotReceive('get_meta');
        self::assertSame(['_billing_vat_number'], (new VatNumberResolver())->detectedMetaKeys([$order]));
    }

    /** @param array<string, mixed> $meta */
    private function order(array $meta): WC_Order
    {
        $order = Mockery::mock(WC_Order::class);
        $order->shouldReceive('get_meta')->andReturnUsing(static fn (string $key): mixed => $meta[$key] ?? '');

        return $order;
    }
}
