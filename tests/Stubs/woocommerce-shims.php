<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!class_exists('WC_Order', false)) {
    class WC_Order
    {
        public function get_id(): int
        {
            return 0;
        }

        public function get_order_number(): string
        {
            return '';
        }

        public function get_customer_id(): int
        {
            return 0;
        }

        public function get_payment_method(): string
        {
            return '';
        }

        public function get_transaction_id(): string
        {
            return '';
        }

        public function get_date_paid(): ?WC_DateTime
        {
            return null;
        }

        public function is_paid(): bool
        {
            return false;
        }

        public function get_billing_company(): string
        {
            return '';
        }

        public function get_billing_first_name(): string
        {
            return '';
        }

        public function get_billing_last_name(): string
        {
            return '';
        }

        public function get_billing_email(): string
        {
            return '';
        }

        public function get_billing_address_1(): string
        {
            return '';
        }

        public function get_billing_address_2(): string
        {
            return '';
        }

        public function get_billing_postcode(): string
        {
            return '';
        }

        public function get_billing_city(): string
        {
            return '';
        }

        public function get_billing_country(): string
        {
            return '';
        }

        public function get_total(): string
        {
            return '0';
        }

        public function get_total_tax(): string
        {
            return '0';
        }

        /** @return array<int, WC_Order_Item_Product> */
        public function get_items(): array
        {
            return [];
        }

        public function get_date_created(): ?WC_DateTime
        {
            return null;
        }

        public function get_meta(string $key): mixed
        {
            return '';
        }

        public function meta_exists(string $key): bool
        {
            return false;
        }

        public function update_meta_data(string $key, mixed $value): void {}

        public function save_meta_data(): void {}

        public function add_order_note(string $note): void {}

        public function delete_meta_data(string $key): void {}

        public function get_status(): string
        {
            return '';
        }

        public function get_edit_order_url(): string
        {
            return '';
        }

        /** @return array<int, WC_Order_Refund> */
        public function get_refunds(): array
        {
            return [];
        }
    }
}

if (!class_exists('WC_DateTime', false)) {
    class WC_DateTime extends DateTime {}
}

if (!class_exists('WC_Order_Refund', false)) {
    class WC_Order_Refund
    {
        public function get_id(): int
        {
            return 0;
        }

        public function get_date_created(): ?WC_DateTime
        {
            return null;
        }

        public function get_total(): string
        {
            return '0';
        }

        public function get_total_tax(): string
        {
            return '0';
        }

        /** @return array<int, WC_Order_Item_Product> */
        public function get_items(): array
        {
            return [];
        }

        public function get_reason(): string
        {
            return '';
        }

        public function get_meta(string $key): string
        {
            return '';
        }

        public function update_meta_data(string $key, mixed $value): void {}

        public function save_meta_data(): void {}

        public function delete_meta_data(string $key): void {}

        public function get_parent_id(): int
        {
            return 0;
        }
    }
}

if (!class_exists('WC_Order_Item_Product', false)) {
    class WC_Order_Item_Product
    {
        public function get_id(): int
        {
            return 0;
        }

        public function get_name(): string
        {
            return '';
        }

        public function get_total(): string
        {
            return '0';
        }

        public function get_total_tax(): string
        {
            return '0';
        }

        public function get_quantity(): int
        {
            return 1;
        }

        public function get_product(): WC_Product|false
        {
            return false;
        }

        /** @return array<string, array<int, string>> */
        public function get_taxes(): array
        {
            return [];
        }
    }
}

if (!class_exists('WC_Product', false)) {
    class WC_Product
    {
        public function get_sku(): string
        {
            return '';
        }
    }
}

if (!class_exists('WC_Tax', false)) {
    class WC_Tax
    {
        /** @var array<int, string> rate_id => "x.xxxx%" */
        public static array $ratePercentages = [];

        /** @var list<array<string, mixed>> every ->_insert_tax_rate() call's $data */
        public static array $insertedRates = [];

        /** @var list<array{0: int, 1: array<string, mixed>}> every ->_update_tax_rate() call's [$id */
        public static array $updatedRates = [];

        public static function get_rate_percent(int $rateId): string
        {
            return self::$ratePercentages[$rateId] ?? '0.0000%';
        }

        /** @param array<string, mixed> $data */
        public static function _insert_tax_rate(array $data): int
        {
            self::$insertedRates[] = $data;

            return count(self::$insertedRates);
        }

        /** @param array<string, mixed> $data */
        public static function _update_tax_rate(int $rateId, array $data): void
        {
            self::$updatedRates[] = [$rateId, $data];
        }

        /** @return list<string> */
        public static function get_tax_classes(): array
        {
            return [];
        }
    }
}

if (!class_exists('WC_Integration', false)) {
    abstract class WC_Integration
    {
        public string $id = '';

        public string $method_title = '';

        public string $method_description = '';

        /** @var array<string, mixed> */
        public array $settings = [];

        /** @var array<string, mixed> */
        public array $form_fields = [];

        public function get_option_key(): string
        {
            return 'woocommerce_'.$this->id.'_settings';
        }

        public function init_settings(): void
        {
            $settings = get_option($this->get_option_key(), []);
            $this->settings = is_array($settings) ? $settings : [];
        }

        public function get_option(string $key, mixed $empty_value = null): mixed
        {
            return $this->settings[$key] ?? $empty_value;
        }
    }
}

foreach (['WC_Order_Item_Shipping', 'WC_Order_Item_Fee'] as $chargeClass) {
    if (!class_exists($chargeClass, false)) {
        eval('class '.$chargeClass.' {
            public function get_id(): int { return 0; }
            public function get_name(): string { return ""; }
            public function get_total(): string { return "0"; }
            public function get_total_tax(): string { return "0"; }
            public function get_taxes(): array { return []; }
        }');
    }
}
