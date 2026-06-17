<?php
/**
 * Builds order payload for Eclicsys API from WC_Order
 * 
 * Fields with empty values are OMITTED from the payload
 * to prevent API validation errors.
 * 
 * EMAIL IS REQUIRED - will fallback to billing email, then admin email.
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

        // Ensure shipping address has email (required by API)
        // Fallback chain: shipping email -> billing email -> order billing email -> admin email
        if (empty($shipping_address['email'])) {
            $shipping_address['email'] = $billing_address['email'] 
                ?? $order->get_billing_email() 
                ?? get_option('admin_email');
        }

        // Ensure billing address has email
        if (empty($billing_address['email'])) {
            $billing_address['email'] = $order->get_billing_email() 
                ?? get_option('admin_email');
        }

        // Extract street number from address_1
        $shipping_street_number = $this->extract_street_number($shipping_address['address_1'] ?? '');
        $billing_street_number  = $this->extract_street_number($billing_address['address_1'] ?? '');

        // Build main contact (from billing) - email is REQUIRED
        $contact = $this->build_contact($billing_address, true);

        // Build shipping address with minimal contact (fullname + email REQUIRED)
        $shipping = array_merge(
            $this->build_address($shipping_address, 'shipping', $shipping_street_number),
            array('contact' => $this->build_contact($shipping_address, false))
        );

        // Build billing address (no contact needed)
        $billing = $this->build_address($billing_address, 'billing', $billing_street_number);

        $payload = array(
            'contact'   => $contact,
            'addresses' => array(
                'shipping' => $shipping,
                'billing'  => $billing,
            ),
            'products'  => $this->build_products($order),
            'shipping'  => array('service' => $service_code),
        );

        // Add order reference if available
        $order_reference = $order->get_order_number() ?: $order->get_id();
        if ($order_reference) {
            $payload['order_reference'] = (string) $order_reference;
        }

        return $payload;
    }

    /**
     * Build contact array from address data.
     * Omits empty fields to prevent API validation errors.
     * EMAIL IS ALWAYS INCLUDED (required field).
     *
     * @param array $address        WooCommerce address array.
     * @param bool  $include_phone  Whether to include phone/mobile/dni fields.
     * @return array Contact for Eclicsys API.
     */
    private function build_contact(array $address, bool $include_phone = true): array {
        $contact = array();

        // Fullname is required
        $fullname = trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? ''));
        if (!empty($fullname)) {
            $contact['fullname'] = $fullname;
        } else {
            // Fallback if no name available
            $contact['fullname'] = 'Customer';
        }

        // EMAIL IS REQUIRED - always include, with fallback
        $email = $address['email'] ?? '';
        if (!empty($email)) {
            $contact['email'] = $email;
        } else {
            // Ultimate fallback to admin email (should never happen due to pre-processing)
            $contact['email'] = get_option('admin_email');
        }

        // Only include phone/mobile/dni for main contact, not shipping sub-contact
        if ($include_phone) {
            $phone = $address['phone'] ?? '';
            if (!empty($phone)) {
                $contact['phone'] = $phone;
            }

            $mobile = $address['phone'] ?? ''; // Same as phone in WC
            if (!empty($mobile)) {
                $contact['mobile'] = $mobile;
            }

            // DNI is optional - only include if explicitly set
            $dni = $address['dni'] ?? '';
            if (!empty($dni)) {
                $contact['dni'] = $dni;
            }
        }

        return $contact;
    }

    /**
     * Build address array for Eclicsys API.
     * Omits empty fields to prevent API validation errors.
     *
     * @param array  $address        WooCommerce address.
     * @param string $type           'shipping' or 'billing'.
     * @param string $street_number  Extracted street number.
     * @return array Address payload.
     */
    private function build_address(array $address, string $type, string $street_number): array {
        $country = $address['country'] ?? 'AR';
        $state_code = $address['state'] ?? '';

        $result = array(
            'category_code' => $type,
            'iso_code'      => $country,
        );

        $street = $this->extract_street_name($address['address_1'] ?? '');
        if (!empty($street)) {
            $result['street'] = $street;
        }

        if (!empty($street_number)) {
            $result['street_number'] = $street_number;
        }

        $zip_code = $address['postcode'] ?? '';
        if (!empty($zip_code)) {
            $result['zip_code'] = $zip_code;
        }

        $city = $address['city'] ?? '';
        if (!empty($city)) {
            $result['city'] = $city;
        }

        // For shipping, locality is empty - omit it entirely
        // For billing, locality can be the state or city
        if ($type === 'billing') {
            $locality = $address['state'] ?? $address['city'] ?? '';
            if (!empty($locality)) {
                $result['locality'] = $locality;
            }
        }

        $province = $this->get_state_name($country, $state_code);
        if (!empty($province)) {
            $result['province'] = $province;
        }

        return $result;
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