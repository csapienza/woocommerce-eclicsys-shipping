<?php
/**
 * Plugin Name: WooCommerce Eclicsys Shipping
 * Plugin URI:  https://eclicsys.com
 * Description: Real-time shipping rates and order integration with Eclicsys
 * Version:     2.0.2
 * Author:      Eclicsys
 * Author URI:  https://eclicsys.com
 * License:     GPL-2.0+
 * Text Domain: wc-eclicsys-shipping
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('WC_ECLICSYS_VERSION', '2.0.2');
define('WC_ECLICSYS_FILE', __FILE__);
define('WC_ECLICSYS_PATH', plugin_dir_path(__FILE__));
define('WC_ECLICSYS_URL', plugin_dir_url(__FILE__));

// HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// Clean up old token cache on upgrade
add_action('plugins_loaded', function () {
    $current_version = get_option('wc_eclicsys_version', '0.0.0');
    
    if (version_compare($current_version, '2.0.2', '<')) {
        // Delete old global token transient that caused cross-environment contamination
        delete_transient('eclicsys_api_token');
        update_option('wc_eclicsys_version', WC_ECLICSYS_VERSION);
    }
});

// Bootstrap
add_action('plugins_loaded', function () {
    if (!class_exists('WC_Shipping_Method')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('WooCommerce Eclicsys Shipping requires WooCommerce to be installed and active.', 'wc-eclicsys-shipping') .
                '</p></div>';
        });
        return;
    }

    // Load classes in dependency order
    require_once WC_ECLICSYS_PATH . 'includes/class-wc-eclicsys-settings.php';
    require_once WC_ECLICSYS_PATH . 'includes/class-wc-eclicsys-api-client.php';
    require_once WC_ECLICSYS_PATH . 'includes/class-wc-eclicsys-order-builder.php';
    require_once WC_ECLICSYS_PATH . 'includes/class-wc-eclicsys-shipping-method.php';
    require_once WC_ECLICSYS_PATH . 'includes/class-wc-eclicsys-order-handler.php';
    require_once WC_ECLICSYS_PATH . 'includes/class-wc-eclicsys-admin.php';
});

// Register shipping method
add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['eclicsys_logistics'] = 'WC_Eclicsys_Shipping_Method';
    return $methods;
});

// Initialize order handler and admin UI
add_action('woocommerce_init', function () {
    $settings = WC_Eclicsys_Settings::get_instance();

    if ($settings->is_configured()) {
        new WC_Eclicsys_Order_Handler();
        new WC_Eclicsys_Admin();
    }
}, 20);

// Reload settings after any WooCommerce shipping option is saved
add_action('woocommerce_update_options_shipping', function () {
    WC_Eclicsys_Settings::get_instance()->reload();
});