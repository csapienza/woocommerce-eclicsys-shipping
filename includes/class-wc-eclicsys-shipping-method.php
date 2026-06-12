<?php
/**
 * Eclicsys Shipping Method
 * 
 * Uses WooCommerce instance settings for zone-based configuration.
 * Settings are saved automatically by WC_Settings_API::process_admin_options()
 */

class WC_Eclicsys_Shipping_Method extends WC_Shipping_Method {

    /**
     * Constructor.
     *
     * @param int $instance_id Instance ID for zone-based methods.
     */
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

    /**
     * Initialize the shipping method.
     * Called by constructor. Sets up form fields and loads saved settings.
     */
    public function init(): void {
        // Load saved instance settings from database
        $this->init_instance_settings();

        // Define form fields for the settings modal
        $this->init_form_fields();

        // Set the title from saved option
        $this->title = $this->get_option('title', $this->method_title);

        // Hook to save settings when admin updates them
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Define instance form fields.
     * These appear in the shipping zone modal when editing an instance.
     */
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
                'description' => __('Logs API requests to WooCommerce logs', 'wc-eclicsys-shipping'),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Calculate shipping rates for a package.
     *
     * @param array $package Package of items from cart.
     */
    public function calculate_shipping($package = array()): void {
        // Check if method is enabled
        if (!$this->is_enabled()) {
            return;
        }

        $settings = WC_Eclicsys_Settings::get_instance();

        if (!$settings->is_configured()) {
            return;
        }

        $postcode = $package['destination']['postcode'] ?? '';

        if (empty($postcode)) {
            return;
        }

        // Free shipping check
        $cart_total = WC()->cart ? WC()->cart->get_subtotal() : 0;
        $free_min = $settings->get_free_shipping_min();

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
            return;
        }

        try {
            $items = $this->build_package_items($package);
            $client = new WC_Eclicsys_API_Client(
                $settings->get_api_key(),
                $settings->get_api_secret(),
                $settings->get_api_url()
            );

            $response = $client->get_shipping_rates($postcode, $items);
            $tariffs = $this->normalize_tariffs($response);
            $handling_fee = $settings->get_handling_fee();

            foreach ($tariffs as $tariff) {
                $cost = $tariff['cost'] + $handling_fee;
                if ($cost <= 0) {
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
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Eclicsys shipping error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Build items array from package for API request.
     *
     * @param array $package WooCommerce shipping package.
     * @return array Items for Eclicsys API.
     */
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
     * Normalize tariffs from API response.
     *
     * @param array $response API response.
     * @return array Normalized tariffs.
     */
    private function normalize_tariffs(array $response): array {
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