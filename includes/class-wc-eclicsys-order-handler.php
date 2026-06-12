<?php
/**
 * Handles automatic order creation in Eclicsys
 */

class WC_Eclicsys_Order_Handler {

    public function __construct() {
        // Classic checkout hooks
        add_action('woocommerce_order_status_processing', [$this, 'maybe_create_shipment'], 10, 2);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_create_shipment'], 10, 2);

        // Store API (block checkout) - correct hook name
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'handle_store_api_order'], 10, 1);

        // Alternative: hook on order creation (works for both classic and block)
        add_action('woocommerce_checkout_order_created', [$this, 'handle_checkout_order_created'], 10, 1);

        // Fallback: new order hook (fires for all order creation methods)
        add_action('woocommerce_new_order', [$this, 'handle_new_order'], 10, 2);

        // Debug: log all order status transitions
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('woocommerce_order_status_changed', [$this, 'debug_status_change'], 10, 4);
        }
    }

    /**
     * Debug helper: log all status changes
     */
    public function debug_status_change(int $order_id, string $from, string $to, WC_Order $order): void {
        $has_eclicsys = false;
        foreach ($order->get_shipping_methods() as $method) {
            if (strpos($method->get_method_id(), 'eclicsys_logistics') !== false) {
                $has_eclicsys = true;
                break;
            }
        }

        if ($has_eclicsys) {
            error_log(sprintf(
                '[Eclicsys Debug] Order %d status: %s -> %s | Has tracking: %s',
                $order_id,
                $from,
                $to,
                $order->get_meta('_eclicsys_tracking_code') ? 'YES' : 'NO'
            ));
        }
    }

    /**
     * Handle Store API checkout (block-based checkout)
     * Fires when order is processed through the REST API / Store API
     * 
     * @param WC_Order $order The order object.
     */
    public function handle_store_api_order(WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] Store API order processed: ' . $order->get_id() . ' | Status: ' . $order->get_status());
        }

        $this->process_order($order);
    }

    /**
     * Handle classic checkout order creation
     * 
     * @param WC_Order $order The order object.
     */
    public function handle_checkout_order_created(WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] Checkout order created: ' . $order->get_id() . ' | Status: ' . $order->get_status());
        }

        // For classic checkout, process immediately if status is already processing/completed
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            $this->process_order($order);
        }
    }

    /**
     * Handle new order (fires for all order creation methods)
     * 
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object.
     */
    public function handle_new_order(int $order_id, WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] New order: ' . $order_id . ' | Status: ' . $order->get_status());
        }

        // Only process if status is already processing or completed
        // (for orders created via admin or other methods that skip pending)
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            $this->process_order($order);
        }
    }

    /**
     * Main entry point for processing an order via status hooks
     * 
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object.
     */
    public function maybe_create_shipment(int $order_id, WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] Status hook fired for order: ' . $order_id . ' | Status: ' . $order->get_status());
        }

        $this->process_order($order);
    }

    /**
     * Process order: create shipment in Eclicsys
     * Central method that handles all entry points.
     * 
     * @param WC_Order $order The order to process.
     */
    private function process_order(WC_Order $order): void {
        $order_id = $order->get_id();

        // Prevent duplicate processing
        if ($order->get_meta('_eclicsys_tracking_code')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' already has tracking, skipping');
            }
            return;
        }

        // Prevent race conditions
        if ($order->get_meta('_eclicsys_syncing')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' is already syncing, skipping');
            }
            return;
        }

        $service_code = $this->get_service_code($order);
        if ($service_code === null) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' does not use Eclicsys shipping');
            }
            return;
        }

        $settings = WC_Eclicsys_Settings::get_instance();
        if (!$settings->is_configured()) {
            $order->add_order_note(__('Eclicsys: API not configured', 'wc-eclicsys-shipping'));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' - API not configured');
            }
            return;
        }

        $order->update_meta_data('_eclicsys_syncing', '1');
        $order->save();

        try {
            $builder = new WC_Eclicsys_Order_Builder();
            $data = $builder->build($order, $service_code);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' - Sending to API: ' . json_encode($data));
            }

            $client = new WC_Eclicsys_API_Client(
                $settings->get_api_key(),
                $settings->get_api_secret(),
                $settings->get_api_url()
            );

            $response = $client->create_shipping_order($data);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' - API response: ' . json_encode($response));
            }

            // Extract tracking using the helper methods
            $tracking_code = WC_Eclicsys_API_Client::extract_tracking($response);
            $eclicsys_order_id = WC_Eclicsys_API_Client::extract_order_id($response);
            $status = WC_Eclicsys_API_Client::extract_status($response);

            if (empty($tracking_code)) {
                throw new Exception('No tracking code received in response');
            }

            $order->update_meta_data('_eclicsys_tracking_code', $tracking_code);
            $order->update_meta_data('_eclicsys_order_id', $eclicsys_order_id);
            $order->update_meta_data('_eclicsys_status', $status);
            $order->add_order_note(sprintf(
                /* translators: %1$s: tracking code, %2$s: status */
                __('Eclicsys shipment created. Tracking: %1$s | Status: %2$s', 'wc-eclicsys-shipping'),
                $tracking_code,
                $status
            ));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' - SUCCESS: Tracking=' . $tracking_code);
            }

        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('Eclicsys error: %s', 'wc-eclicsys-shipping'),
                $error_msg
            ));
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' - ERROR: ' . $error_msg);
            }
        } finally {
            $order->delete_meta_data('_eclicsys_syncing');
            $order->save();
        }
    }

    /**
     * Extract service code from shipping method used in order
     * 
     * @param WC_Order $order The order to check.
     * @return string|null Service code or null if not Eclicsys.
     */
    private function get_service_code(WC_Order $order): ?string {
        foreach ($order->get_shipping_methods() as $shipping) {
            $method_id = $shipping->get_method_id();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Checking shipping method: ' . $method_id);
            }

            if (strpos($method_id, 'eclicsys_logistics') === false) {
                continue;
            }

            // Try to get service_code from meta_data
            $meta_data = $shipping->get_meta_data();
            foreach ($meta_data as $meta) {
                if ($meta->key === 'service_code') {
                    return $meta->value;
                }
            }

            // Try legacy meta
            $service_code = $shipping->get_meta('service_code');
            if ($service_code) {
                return $service_code;
            }

            // Fallback: infer from rate ID
            $rate_id = $shipping->get_instance_id() 
                ? $method_id . ':' . $shipping->get_instance_id() 
                : $method_id;

            if (strpos($rate_id, 'nextday') !== false) {
                return 'nextday';
            }
            if (strpos($rate_id, 'express') !== false) {
                return 'express';
            }

            return 'standard';
        }

        return null;
    }
}