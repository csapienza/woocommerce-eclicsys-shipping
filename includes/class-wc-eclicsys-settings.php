<?php
/**
 * Settings Provider - Centralizes access to Eclicsys configuration
 * 
 * Reads from the FIRST active instance of the shipping method found in any zone.
 * This simplifies the architecture by using a single set of credentials
 * across all shipping zones.
 */

class WC_Eclicsys_Settings {

    private static ?self $instance = null;
    private ?array $settings = null;
    private ?int $instance_id = null;
    private bool $loaded = false;

    private function __construct() {}

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Find the first active instance and load its settings.
     * Uses WooCommerce's native API to find instances across all zones.
     */
    private function load_settings(): void {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->settings = array();

        if (!function_exists('WC') || !WC()->shipping()) {
            return;
        }

        // Method 1: Check all shipping zones for active Eclicsys instances
        $zones = WC_Shipping_Zones::get_zones();

        foreach ($zones as $zone_data) {
            $zone = new WC_Shipping_Zone($zone_data['zone_id']);
            $methods = $zone->get_shipping_methods();

            foreach ($methods as $method) {
                if ($method->id === 'eclicsys_logistics' && $method->is_enabled()) {
                    $this->instance_id = $method->get_instance_id();
                    $this->settings = $method->instance_settings;
                    return;
                }
            }
        }

        // Method 2: Check default zone (zone 0 - "Rest of the World")
        $default_zone = new WC_Shipping_Zone(0);
        $methods = $default_zone->get_shipping_methods();

        foreach ($methods as $method) {
            if ($method->id === 'eclicsys_logistics' && $method->is_enabled()) {
                $this->instance_id = $method->get_instance_id();
                $this->settings = $method->instance_settings;
                return;
            }
        }

        // Method 3: Fallback - try to load from database options directly
        // This handles edge cases where the method exists but isn't in a zone yet
        if (empty($this->settings)) {
            global $wpdb;
            $option_name = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} 
                     WHERE option_name LIKE %s 
                     LIMIT 1",
                    $wpdb->esc_like('woocommerce_eclicsys_logistics_') . '%'
                )
            );

            if ($option_name) {
                $this->settings = get_option($option_name, array());
                // Extract instance_id from option name: woocommerce_eclicsys_logistics_{id}_settings
                if (preg_match('/woocommerce_eclicsys_logistics_(\d+)_settings/', $option_name, $matches)) {
                    $this->instance_id = (int) $matches[1];
                }
            }
        }
    }

    /**
     * Check if API credentials are configured.
     */
    public function is_configured(): bool {
        $this->load_settings();
        return !empty($this->settings['api_key']) && !empty($this->settings['api_secret']);
    }

    public function get_api_key(): string {
        $this->load_settings();
        return trim($this->settings['api_key'] ?? '');
    }

    public function get_api_secret(): string {
        $this->load_settings();
        return trim($this->settings['api_secret'] ?? '');
    }

    public function get_api_url(): string {
        $this->load_settings();

        $is_sandbox = ($this->settings['sandbox'] ?? 'yes') === 'yes';

        if ($is_sandbox) {
            return 'https://dev.eclicsys.com/api';
        }

        return rtrim($this->settings['production_url'] ?? 'https://api.eclicsys.com/api', '/');
    }

    public function get_handling_fee(): float {
        $this->load_settings();
        return floatval($this->settings['handling_fee'] ?? 0);
    }

    public function get_free_shipping_min(): float {
        $this->load_settings();
        return floatval($this->settings['free_shipping_min'] ?? 0);
    }

    public function is_debug(): bool {
        $this->load_settings();
        return ($this->settings['debug'] ?? 'no') === 'yes';
    }

    public function get_instance_id(): ?int {
        $this->load_settings();
        return $this->instance_id;
    }

    /**
     * Get all raw settings (useful for debugging).
     */
    public function get_all_settings(): array {
        $this->load_settings();
        return $this->settings ?? array();
    }

    /**
     * Force reload settings (useful after saving).
     */
    public function reload(): void {
        $this->loaded = false;
        $this->settings = null;
        $this->instance_id = null;
        $this->load_settings();
    }
}