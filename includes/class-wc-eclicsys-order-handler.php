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

        // Alternative: hook on order creation
        add_action('woocommerce_checkout_order_created', [$this, 'handle_checkout_order_created'], 10, 1);

        // Fallback: new order hook (fires for all order creation methods)
        add_action('woocommerce_new_order', [$this, 'handle_new_order'], 10, 2);

        // Debug: log all order status transitions
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('woocommerce_order_status_changed', [$this, 'debug_status_change'], 10, 4);
        }

        // Debug AJAX: preview payload without sending
        add_action('wp_ajax_eclicsys_debug_payload', [$this, 'ajax_debug_payload']);
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
     * AJAX: Debug payload without sending to API
     */
    public function ajax_debug_payload(): void {
        check_ajax_referer('eclicsys-admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized', 'wc-eclicsys-shipping'));
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(__('Order not found', 'wc-eclicsys-shipping'));
        }

        $service_code = $this->get_service_code($order);

        $builder = new WC_Eclicsys_Order_Builder();
        $payload = $builder->build($order, $service_code ?? 'standard');

        wp_send_json_success([
            'payload' => $payload,
            'service_code' => $service_code,
            'shipping_methods' => array_map(function($m) {
                return [
                    'id' => $m->get_method_id(),
                    'instance_id' => $m->get_instance_id(),
                    'meta' => $m->get_meta_data(),
                ];
            }, $order->get_shipping_methods()),
        ]);
    }

    /**
     * Handle Store API checkout (block-based checkout)
     */
    public function handle_store_api_order(WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] Store API order processed: ' . $order->get_id() . ' | Status: ' . $order->get_status());
        }

        $this->process_order($order);
    }

    /**
     * Handle classic checkout order creation
     */
    public function handle_checkout_order_created(WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] Checkout order created: ' . $order->get_id() . ' | Status: ' . $order->get_status());
        }

        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            $this->process_order($order);
        }
    }

    /**
     * Handle new order (fires for all order creation methods)
     */
    public function handle_new_order(int $order_id, WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] New order: ' . $order_id . ' | Status: ' . $order->get_status());
        }

        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            $this->process_order($order);
        }
    }

    /**
     * Main entry point for processing an order via status hooks
     */
    public function maybe_create_shipment(int $order_id, WC_Order $order): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Eclicsys] Status hook fired for order: ' . $order_id . ' | Status: ' . $order->get_status());
        }

        $this->process_order($order);
    }

    /**
     * Process order: create shipment in Eclicsys
     */
    private function process_order(WC_Order $order): void {
        $order_id = $order->get_id();

        if ($order->get_meta('_eclicsys_tracking_code')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Eclicsys] Order ' . $order_id . ' already has tracking, skipping');
            }
            return;
        }

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

            $meta_data = $shipping->get_meta_data();
            foreach ($meta_data as $meta) {
                if ($meta->key === 'service_code') {
                    return $meta->value;
                }
            }

            $service_code = $shipping->get_meta('service_code');
            if ($service_code) {
                return $service_code;
            }

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