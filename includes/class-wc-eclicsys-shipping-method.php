<?php
/**
 * Eclicsys Shipping Method
 */

class WC_Eclicsys_Shipping_Method extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {
        $this->id = 'eclicsys_logistics';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Eclicsys Logistics', 'wc-eclicsys-shipping');
        $this->method_description = __('Real-time shipping rates from Eclicsys', 'wc-eclicsys-shipping');
        $this->supports = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->init();
    }

    public function init(): void {
        $this->init_instance_settings();
        $this->init_form_fields();
        $this->title = $this->get_option('title', $this->method_title);
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields(): void {
        $this->instance_form_fields = array(
            'title' => array(
                'title'       => __('Title', 'wc-eclicsys-shipping'),
                'type'        => 'text',
                'description' => __('Title shown to customer at checkout', 'wc-eclicsys-shipping'),
                'default'     => __('Eclicsys Shipping', 'wc-eclicsys-shipping'),
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'wc-eclicsys-shipping'),
                'type'        => 'text',
                'description' => __('Integration ID from Eclicsys', 'wc-eclicsys-shipping'),
                'desc_tip'    => true,
            ),
            'api_secret' => array(
                'title'       => __('API Secret', 'wc-eclicsys-shipping'),
                'type'        => 'password',
                'description' => __('Integration secret from Eclicsys', 'wc-eclicsys-shipping'),
                'desc_tip'    => true,
            ),
            'sandbox' => array(
                'title'       => __('Sandbox Mode', 'wc-eclicsys-shipping'),
                'type'        => 'checkbox',
                'label'       => __('Use development environment', 'wc-eclicsys-shipping'),
                'default'     => 'yes',
                'description' => __('Checked = dev environment. Unchecked = production', 'wc-eclicsys-shipping'),
                'desc_tip'    => true,
            ),
            'production_url' => array(
                'title'       => __('Production URL', 'wc-eclicsys-shipping'),
                'type'        => 'text',
                'default'     => 'https://api.eclicsys.com/api',
                'placeholder' => 'https://api.eclicsys.com/api',
                'description' => __('Only used when Sandbox is unchecked', 'wc-eclicsys-shipping'),
                'desc_tip'    => true,
            ),
            'handling_fee' => array(
                'title'       => __('Handling Fee', 'wc-eclicsys-shipping'),
                'type'        => 'number',
                'description' => __('Fixed fee added to shipping cost', 'wc-eclicsys-shipping'),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'free_shipping_min' => array(
                'title'       => __('Free Shipping Minimum', 'wc-eclicsys-shipping'),
                'type'        => 'number',
                'description' => __('Cart subtotal required for free shipping', 'wc-eclicsys-shipping'),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug Mode', 'wc-eclicsys-shipping'),
                'type'        => 'checkbox',
                'label'       => __('Enable debug logging', 'wc-eclicsys-shipping'),
                'default'     => 'no',
                'description' => __('Logs API requests to WooCommerce logs (WooCommerce > Status > Logs)', 'wc-eclicsys-shipping'),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Log message using WooCommerce logger
     */
    private function log(string $message, array $context = []): void {
        if ($this->get_option('debug') !== 'yes') {
            return;
        }

        $log_message = '[Eclicsys Shipping] ' . $message;
        if (!empty($context)) {
            $log_message .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($log_message, ['source' => 'eclicsys-shipping']);
        } else {
            error_log($log_message);
        }
    }

    public function calculate_shipping($package = array()): void {
        $this->log('calculate_shipping started');

        if (!$this->is_enabled()) {
            $this->log('Method disabled, returning');
            return;
        }

        $settings = WC_Eclicsys_Settings::get_instance();

        if (!$settings->is_configured()) {
            $this->log('API not configured', [
                'api_key' => $settings->get_api_key() ? 'set' : 'empty',
                'api_secret' => $settings->get_api_secret() ? 'set' : 'empty',
            ]);
            return;
        }

        $postcode = $package['destination']['postcode'] ?? '';
        $this->log('Destination', ['postcode' => $postcode, 'country' => $package['destination']['country'] ?? 'N/A']);

        if (empty($postcode)) {
            $this->log('No postcode, returning');
            return;
        }

        // CORRECTION: Safe cart total check - WC()->cart might not be available in all contexts
        $cart_total = 0;
        if (function_exists('WC') && WC()->cart) {
            $cart_total = WC()->cart->get_subtotal();
        } elseif (!empty($package['cart_subtotal'])) {
            $cart_total = $package['cart_subtotal'];
        }

        $free_min = $settings->get_free_shipping_min();

        $this->log('Free shipping check', ['cart_total' => $cart_total, 'free_min' => $free_min]);

        if ($free_min > 0 && $cart_total >= $free_min) {
            $this->add_rate(array(
                'id'    => $this->get_rate_id('free'),
                'label' => $this->title . ' (' . __('Free', 'wc-eclicsys-shipping') . ')',
                'cost'  => 0,
                'meta_data' => array(
                    'service_code' => 'standard',
                    'service_name' => 'Free Shipping',
                ),
            ));
            $this->log('Added free shipping rate');
            return;
        }

        try {
            $items = $this->build_package_items($package);
            $this->log('Items built', ['count' => count($items), 'items' => $items]);

            $client = new WC_Eclicsys_API_Client(
                $settings->get_api_key(),
                $settings->get_api_secret(),
                $settings->get_api_url()
            );

            $this->log('Requesting rates from API', ['url' => $settings->get_api_url(), 'postcode' => $postcode]);
            $response = $client->get_shipping_rates($postcode, $items);

            $this->log('API response received', ['response' => $response]);

            // CORRECTION: Handle both response structures
            $tariffs = $this->normalize_tariffs($response);
            $handling_fee = $settings->get_handling_fee();

            $this->log('Tariffs normalized', ['count' => count($tariffs), 'handling_fee' => $handling_fee]);

            if (empty($tariffs)) {
                $this->log('No tariffs returned from API');
                return;
            }

            foreach ($tariffs as $tariff) {
                $cost = $tariff['cost'] + $handling_fee;
                if ($cost < 0) {
                    $this->log('Skipping tariff with negative cost', ['tariff' => $tariff]);
                    continue;
                }

                $this->add_rate(array(
                    'id'        => $this->get_rate_id($tariff['code']),
                    'label'     => $tariff['label'],
                    'cost'      => $cost,
                    'meta_data' => array(
                        'service_code' => $tariff['code'],
                        'service_name' => $tariff['name'],
                    ),
                ));

                $this->log('Rate added', [
                    'code' => $tariff['code'],
                    'label' => $tariff['label'],
                    'cost' => $cost,
                    'base_cost' => $tariff['cost'],
                    'handling_fee' => $handling_fee,
                ]);
            }

        } catch (Exception $e) {
            $this->log('ERROR in calculate_shipping', ['error' => $e->getMessage()]);
            // Optionally add a fallback rate or just let WooCommerce show "no shipping available"
        }
    }

    private function build_package_items(array $package): array {
        $items = array();

        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            if (!$product) {
                continue;
            }

            $items[] = array(
                'sku'      => $product->get_sku() ?: 'WC-' . $product->get_id(),
                'name'     => $product->get_name(),
                'quantity' => $item['quantity'],
                'weight'   => (float) $product->get_weight(),
                'length'   => (float) $product->get_length(),
                'width'    => (float) $product->get_width(),
                'height'   => (float) $product->get_height(),
                'price'    => (float) $product->get_price(),
            );
        }

        return $items;
    }

    /**
     * Normalize tariffs from API response
     * Handles both "rates" and "tariffs" keys
     */
    private function normalize_tariffs(array $response): array {
        // CORRECTION: Check multiple possible response keys
        $raw = $response['rates'] ?? $response['tariffs'] ?? array();

        if (isset($response['cost']) && empty($raw)) {
            $raw = array($response);
        }

        $tariffs = array();

        foreach ($raw as $t) {
            $tariffs[] = array(
                'code'  => $t['service_code'] ?? 'standard',
                'name'  => $t['service_name'] ?? $this->title,
                'label' => $t['service_name'] ?? $this->title,
                'cost'  => (float) ($t['tariff_amount'] ?? $t['cost'] ?? 0),
            );
        }

        return $tariffs;
    }
}