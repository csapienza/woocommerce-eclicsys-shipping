<?php
/**
 * Builds order payload for Eclicsys API from WC_Order
 */

class WC_Eclicsys_Order_Builder {

    public function build(WC_Order $order, string $service_code = 'standard'): array {
        $shipping = $this->get_address($order, 'shipping');
        $billing  = $this->get_address($order, 'billing');

        // Fallback: use billing if shipping is empty
        if (empty($shipping['street'])) {
            $shipping = $billing;
        }

        return [
            'order_reference' => (string) $order->get_order_number(),
            'contact'         => $this->build_contact($billing),
            'addresses'       => [
                'shipping' => array_merge($shipping, ['contact' => $this->build_contact($shipping)]),
                'billing'  => $billing,
            ],
            'products'        => $this->build_products($order),
            'shipping'        => ['service' => $service_code],
        ];
    }

    private function build_contact(array $address): array {
        return [
            'fullname' => trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')),
            'email'    => $address['email'] ?? '',
            'phone'    => $address['phone'] ?? '',
            'mobile'   => $address['phone'] ?? '',
        ];
    }

    private function get_address(WC_Order $order, string $type): array {
        $raw = $order->get_address($type);
        $country = $raw['country'] ?? 'AR';
        $state_code = $raw['state'] ?? '';

        return [
            'category_code' => $type,
            'iso_code'      => $country,
            'street'        => $raw['address_1'] ?? '',
            'street_number' => '',
            'zip_code'      => $raw['postcode'] ?? '',
            'city'          => $raw['city'] ?? '',
            'locality'      => $raw['city'] ?? '',
            'province'      => $this->get_state_name($country, $state_code),
        ];
    }

    private function get_state_name(string $country, string $code): string {
        $states = WC()->countries->get_states($country);
        return $states[$code] ?? $code;
    }

    private function build_products(WC_Order $order): array {
        $products = [];
        $currency = $order->get_currency();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $products[] = [
                'sku'           => $product->get_sku() ?: 'WC-' . $product->get_id(),
                'name'          => $item->get_name(),
                'quantity'      => $item->get_quantity(),
                'weight'        => (float) $product->get_weight(),
                'length'        => (float) $product->get_length(),
                'width'         => (float) $product->get_width(),
                'height'        => (float) $product->get_height(),
                'currency_code' => $currency,
                'price'         => (float) $product->get_price(),
            ];
        }

        return $products;
    }
}