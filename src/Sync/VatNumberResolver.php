<?php

declare(strict_types=1);

namespace MinoxWooCommerce\Sync;

use InvalidArgumentException;
use RuntimeException;
use WC_Order;

final class VatNumberResolver
{
    public const array COMMON_META_KEYS = [
        '_vat_number',
        '_billing_vat_number',
        'billing_vat_number',
        '_billing_eu_vat_number',
        'billing_eu_vat_number',
    ];

    private readonly ?string $metaKey;

    public function __construct(?string $metaKey = null)
    {
        $this->metaKey = self::validateMetaKey($metaKey);
    }

    public static function validateMetaKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(__('Enter one valid VAT field name, not a VAT number or other data.', 'kb-data-connector-for-woocommerce'));
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D', $value) !== 1) {
            throw new InvalidArgumentException(__('The VAT field name may only contain letters, digits, underscores and hyphens (64 characters at most).', 'kb-data-connector-for-woocommerce'));
        }

        return $value;
    }

    public function resolve(WC_Order $order): ?string
    {
        $keys = $this->metaKey === null ? self::COMMON_META_KEYS : [$this->metaKey];
        $numbers = [];

        foreach ($keys as $key) {
            $value = $order->get_meta($key);

            if ($value === '' || $value === null) {
                continue;
            }

            if (!is_string($value)) {
                throw new RuntimeException(__('The linked VAT number field contains no text. Check the field mapping in the Minox Connector settings.', 'kb-data-connector-for-woocommerce'));
            }

            $number = preg_replace('/[\s.\-]+/u', '', trim($value));

            if ($number === null || ($number === '' && trim($value) !== '')) {
                throw new RuntimeException(__('The VAT number field contains no usable text. Check the field mapping in the Minox Connector settings.', 'kb-data-connector-for-woocommerce'));
            }

            if ($number !== '') {
                $numbers[strtoupper($number)] = true;
            }
        }

        if (count($numbers) > 1) {
            throw new RuntimeException(__('Several VAT number sources contain different values. Nothing was sent. Choose the right VAT field name in the Minox Connector settings or get in touch.', 'kb-data-connector-for-woocommerce'));
        }

        return $numbers === [] ? null : (string) array_key_first($numbers);
    }

    /**
     * @param iterable<WC_Order> $orders
     * @return list<string>
     */
    public function detectedMetaKeys(iterable $orders): array
    {
        $found = [];

        foreach ($orders as $order) {
            foreach (self::COMMON_META_KEYS as $key) {
                if ($order->meta_exists($key)) {
                    $found[$key] = true;
                }
            }
        }

        return array_keys($found);
    }
}
