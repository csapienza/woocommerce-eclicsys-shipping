<?php
/**
 * Handles automatic order creation in Eclicsys
 */

class WC_Eclicsys_Order_Handler {

    public function __construct() {
        add_action('woocommerce_order_status_processing', [$this, 'maybe_create_shipment'], 10, 2);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_create_shipment'], 10, 2);
        
        // CORRECTION: Only add Store API hooks if function exists (compatibility)
        if (function_exists('wc_get_order')) {
            add_action('woocommerce_store_api_checkout_order_processed', [$this, 'handle_store_api_order'], 10, 1);
            add_action('woocommerce_checkout_order_created', [$this, 'handle_checkout_order_created'], 10, 1);
            add_action('woocommerce_new_order', [$this, 'handle_new_order'], 10, 2);
        }

        add_action('wp_ajax_eclicsys_debug_payload', [$this, 'ajax_debug_payload']);
    }

    /**
     * Log message using WooCommerce logger
     */
    private function log(string $message, array $context = []): void {
        $settings = WC_Eclicsys_Settings::get_instance();
        if (!$settings->is_debug()) {
            return;
        }

        $log_message = '[Eclicsys Order] ' . $message;
        if (!empty($context)) {
            $log_message .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($log_message, ['source' => 'eclicsys-order']);
        } else {
            error_log($log_message);
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

    public function handle_store_api_order(WC_Order $order): void {
        $this->log('Store API order processed', ['order_id' => $order->get_id(), 'status' => $order->get_status()]);
        $this->process_order($order);
    }

    public function handle_checkout_order_created(WC_Order $order): void {
        $this->log('Checkout order created', ['order_id' => $order->get_id(), 'status' => $order->get_status()]);
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            $this->process_order($order);
        }
    }

    public function handle_new_order(int $order_id, WC_Order $order): void {
        $this->log('New order', ['order_id' => $order_id, 'status' => $order->get_status()]);
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            $this->process_order($order);
        }
    }

    public function maybe_create_shipment(int $order_id, WC_Order $order): void {
        $this->log('Status hook fired', ['order_id' => $order_id, 'status' => $order->get_status()]);
        $this->process_order($order);
    }

    private function process_order(WC_Order $order): void {
        $order_id = $order->get_id();

        // CORRECTION: Check if already has tracking
        if ($order->get_meta('_eclicsys_tracking_code')) {
            $this->log('Already has tracking, skipping', ['order_id' => $order_id]);
            return;
        }

        // CORRECTION: Check if already syncing (prevent duplicates)
        if ($order->get_meta('_eclicsys_syncing')) {
            $this->log('Already syncing, skipping', ['order_id' => $order_id]);
            return;
        }

        $service_code = $this->get_service_code($order);
        if ($service_code === null) {
            $this->log('Not Eclicsys shipping', ['order_id' => $order_id]);
            return;
        }

        $settings = WC_Eclicsys_Settings::get_instance();
        if (!$settings->is_configured()) {
            $order->add_order_note(__('Eclicsys: API not configured', 'wc-eclicsys-shipping'));
            $this->log('API not configured', ['order_id' => $order_id]);
            return;
        }

        // Mark as syncing
        $order->update_meta_data('_eclicsys_syncing', '1');
        $order->save();

        try {
            $builder = new WC_Eclicsys_Order_Builder();
            $data = $builder->build($order, $service_code);

            $this->log('Sending to API', ['order_id' => $order_id, 'payload' => $data]);

            $client = new WC_Eclicsys_API_Client(
                $settings->get_api_key(),
                $settings->get_api_secret(),
                $settings->get_api_url()
            );

            $response = $client->create_shipping_order($data);

            $this->log('API response', ['order_id' => $order_id, 'response' => $response]);

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

            $this->log('SUCCESS', ['order_id' => $order_id, 'tracking' => $tracking_code]);

        } catch (Exception $e) {
            $error_msg = $e->getMessage();
            $order->add_order_note(sprintf(
                __('Eclicsys error: %s', 'wc-eclicsys-shipping'),
                $error_msg
            ));
            $this->log('ERROR', ['order_id' => $order_id, 'error' => $error_msg]);
        } finally {
            // CORRECTION: Always clean up syncing flag
            $order->delete_meta_data('_eclicsys_syncing');
            $order->save();
        }
    }

    /**
     * Extract service code from order shipping methods
     * CORRECTION: Improved meta data extraction
     */
    private function get_service_code(WC_Order $order): ?string {
        foreach ($order->get_shipping_methods() as $shipping) {
            $method_id = $shipping->get_method_id();

            if (strpos($method_id, 'eclicsys_logistics') === false) {
                continue;
            }

            // Try meta_data first (stored as array of objects)
            $meta_data = $shipping->get_meta_data();
            foreach ($meta_data as $meta) {
                $meta_key = $meta->key ?? '';
                $meta_value = $meta->value ?? '';
                if ($meta_key === 'service_code' && !empty($meta_value)) {
                    return $meta_value;
                }
            }

            // Try direct meta access
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