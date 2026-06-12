<?php
/**
 * Builds order payload for Eclicsys API from WC_Order
 * 
 * Expected API structure (DNI is optional, not required):
 * {
 *   "contact": {
 *     "fullname": "...",
 *     "email": "...",
 *     "dni": "",
 *     "phone": "...",
 *     "mobile": "..."
 *   },
 *   "addresses": {
 *     "shipping": {
 *       "category_code": "shipping",
 *       "iso_code": "AR",
 *       "street": "...",
 *       "street_number": "...",
 *       "zip_code": "...",
 *       "city": "...",
 *       "locality": "",
 *       "province": "...",
 *       "contact": {
 *         "fullname": "...",
 *         "email": "...",
 *         "dni": "",
 *         "phone": "...",
 *         "mobile": "..."
 *       }
 *     },
 *     "billing": {
 *       "category_code": "billing",
 *       "iso_code": "AR",
 *       "street": "...",
 *       "street_number": "...",
 *       "zip_code": "...",
 *       "city": "...",
 *       "locality": "...",
 *       "province": "..."
 *     }
 *   },
 *   "products": [...],
 *   "shipping": {
 *     "service": "nextday"
 *   }
 * }
 */

class WC_Eclicsys_Order_Builder {

    /**
     * Build the complete order payload for Eclicsys API.
     *
     * @param WC_Order $order         WooCommerce order.
     * @param string   $service_code  Shipping service code (nextday, express, standard).
     * @return array Payload ready for API.
     */
    public function build(WC_Order $order, string $service_code = 'standard'): array {
        $shipping_address = $order->get_address('shipping');
        $billing_address  = $order->get_address('billing');

        // If shipping address is empty, fallback to billing
        if (empty($shipping_address['address_1'])) {
            $shipping_address = $billing_address;
        }

        // Extract street number from address_1 (e.g., "Osvaldo Cruz 2683" -> "2683")
        $shipping_street_number = $this->extract_street_number($shipping_address['address_1'] ?? '');
        $billing_street_number  = $this->extract_street_number($billing_address['address_1'] ?? '');

        // Build main contact (from billing)
        $contact = $this->build_contact($billing_address);

        // Build shipping address with contact
        $shipping = array_merge(
            $this->build_address($shipping_address, 'shipping', $shipping_street_number),
            array('contact' => $this->build_contact($shipping_address))
        );

        // Build billing address (no contact needed)
        $billing = $this->build_address($billing_address, 'billing', $billing_street_number);

        return array(
            'contact'   => $contact,
            'addresses' => array(
                'shipping' => $shipping,
                'billing'  => $billing,
            ),
            'products'  => $this->build_products($order),
            'shipping'  => array('service' => $service_code),
        );
    }

    /**
     * Build contact array from address data.
     * DNI is optional and left empty.
     *
     * @param array $address WooCommerce address array.
     * @return array Contact for Eclicsys API.
     */
    private function build_contact(array $address): array {
        return array(
            'fullname' => trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')),
            'email'    => $address['email'] ?? '',
            'dni'      => '',
            'phone'    => $address['phone'] ?? '',
            'mobile'   => $address['phone'] ?? '',
        );
    }

    /**
     * Build address array for Eclicsys API.
     *
     * @param array  $address        WooCommerce address.
     * @param string $type           'shipping' or 'billing'.
     * @param string $street_number  Extracted street number.
     * @return array Address payload.
     */
    private function build_address(array $address, string $type, string $street_number): array {
        $country = $address['country'] ?? 'AR';
        $state_code = $address['state'] ?? '';

        // For shipping, locality is empty string
        // For billing, locality can be the state or city
        $locality = '';
        if ($type === 'billing') {
            $locality = $address['state'] ?? $address['city'] ?? '';
        }

        return array(
            'category_code' => $type,
            'iso_code'      => $country,
            'street'        => $this->extract_street_name($address['address_1'] ?? ''),
            'street_number' => $street_number,
            'zip_code'      => $address['postcode'] ?? '',
            'city'          => $address['city'] ?? '',
            'locality'      => $locality,
            'province'      => $this->get_state_name($country, $state_code),
        );
    }

    /**
     * Extract street name without number.
     * E.g., "Osvaldo Cruz 2683" -> "Osvaldo Cruz"
     *
     * @param string $address Full address line.
     * @return string Street name only.
     */
    private function extract_street_name(string $address): string {
        if (empty($address)) {
            return '';
        }

        // Remove trailing numbers
        $name = preg_replace('/\s+\d+\s*.*$/', '', $address);
        return trim($name) ?: $address;
    }

    /**
     * Extract street number from address.
     * E.g., "Osvaldo Cruz 2683" -> "2683"
     *
     * @param string $address Full address line.
     * @return string Street number or empty string.
     */
    private function extract_street_number(string $address): string {
        if (empty($address)) {
            return '';
        }

        // Match last number in the string
        if (preg_match('/(\d+)\s*.*$/', $address, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get full state name from code.
     *
     * @param string $country Country code.
     * @param string $code    State code.
     * @return string Full state name or code if not found.
     */
    private function get_state_name(string $country, string $code): string {
        $states = WC()->countries->get_states($country);
        return $states[$code] ?? $code;
    }

    /**
     * Build products array from order items.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array Products for Eclicsys API.
     */
    private function build_products(WC_Order $order): array {
        $products = array();
        $currency = $order->get_currency();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $products[] = array(
                'sku'           => $product->get_sku() ?: 'WC-' . $product->get_id(),
                'name'          => $item->get_name(),
                'quantity'      => $item->get_quantity(),
                'weight'        => (float) $product->get_weight(),
                'length'        => (float) $product->get_length(),
                'width'         => (float) $product->get_width(),
                'height'        => (float) $product->get_height(),
                'currency_code' => $currency,
                'price'         => (float) $product->get_price(),
            );
        }

        return $products;
    }
}