<?php
/**
 * Admin UI for Eclicsys integration
 */

class WC_Eclicsys_Admin {

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_tracking_metabox']);
        add_filter('manage_edit-shop_order_columns', [$this, 'add_tracking_column'], 20);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_tracking_column'], 20, 2);

        add_action('wp_ajax_eclicsys_force_create_order', [$this, 'ajax_force_create']);
        add_action('wp_ajax_eclicsys_get_label', [$this, 'ajax_get_label']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook): void {
        $screen = get_current_screen();
        if ($screen && ($screen->id === 'shop_order' || $screen->id === 'woocommerce_page_wc-orders')) {
            wp_enqueue_script(
                'eclicsys-admin',
                WC_ECLICSYS_URL . 'assets/js/admin.js',
                ['jquery'],
                WC_ECLICSYS_VERSION,
                true
            );

            wp_localize_script('eclicsys-admin', 'eclicsysAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('eclicsys-admin'),
                'strings' => [
                    'sending' => __('Sending...', 'wc-eclicsys-shipping'),
                    'error'   => __('Error: ', 'wc-eclicsys-shipping'),
                ],
            ]);
        }
    }

    public function add_tracking_metabox(): void {
        // HPOS compatibility: detect which screen to use
        $screen = 'shop_order';

        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            try {
                $controller = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class);
                if ($controller->custom_orders_table_usage_is_enabled()) {
                    $screen = wc_get_page_screen_id('shop-order');
                }
            } catch (Exception $e) {
                // Fallback to classic screen
            }
        }

        add_meta_box(
            'eclicsys_tracking',
            __('Eclicsys Shipment', 'wc-eclicsys-shipping'),
            [$this, 'render_metabox'],
            $screen,
            'side',
            'high'
        );
    }

    public function render_metabox($order_or_post): void {
        $order = ($order_or_post instanceof WP_Post) 
            ? wc_get_order($order_or_post->ID) 
            : $order_or_post;

        if (!$order) {
            return;
        }

        $tracking = $order->get_meta('_eclicsys_tracking_code');

        if (empty($tracking)) {
            printf(
                '<p>%s</p>',
                esc_html__('No shipment created yet. It will be created automatically when order status changes to Processing.', 'wc-eclicsys-shipping')
            );
            printf(
                '<button type="button" class="button button-primary eclicsys-force-create" data-order-id="%d">%s</button>',
                esc_attr($order->get_id()),
                esc_html__('Create Shipment Now', 'wc-eclicsys-shipping')
            );

            // Debug button (only in WP_DEBUG mode)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                printf(
                    '<button type="button" class="button eclicsys-debug-payload" data-order-id="%d" style="margin-top: 8px;">%s</button>',
                    esc_attr($order->get_id()),
                    esc_html__('Debug Payload', 'wc-eclicsys-shipping')
                );
                echo '<div id="eclicsys-debug-output" style="margin-top: 10px; display: none; background: #f0f0f0; padding: 10px; max-height: 300px; overflow: auto;"></div>';
            }

            return;
        }

        printf(
            '<p><strong>%s</strong><br><code>%s</code></p>',
            esc_html__('Tracking Code:', 'wc-eclicsys-shipping'),
            esc_html($tracking)
        );

        $status = $order->get_meta('_eclicsys_status');
        if ($status) {
            printf(
                '<p><strong>%s</strong><br>%s</p>',
                esc_html__('Status:', 'wc-eclicsys-shipping'),
                esc_html($status)
            );
        }

        printf(
            '<a href="%s" target="_blank" class="button">%s</a>',
            esc_url(admin_url('admin-ajax.php?action=eclicsys_get_label&tracking=' . urlencode($tracking))),
            esc_html__('Download Label', 'wc-eclicsys-shipping')
        );
    }

    public function add_tracking_column(array $columns): array {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_status') {
                $new_columns['eclicsys_tracking'] = __('Eclicsys', 'wc-eclicsys-shipping');
            }
        }

        return $new_columns;
    }

    public function render_tracking_column(string $column, $order_id): void {
        if ($column !== 'eclicsys_tracking') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $tracking = $order->get_meta('_eclicsys_tracking_code');

        if ($tracking) {
            printf('<code>%s</code>', esc_html($tracking));
        } else {
            echo '—';
        }
    }

    public function ajax_force_create(): void {
        check_ajax_referer('eclicsys-admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized', 'wc-eclicsys-shipping'));
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(__('Order not found', 'wc-eclicsys-shipping'));
        }

        $handler = new WC_Eclicsys_Order_Handler();
        $handler->maybe_create_shipment($order_id, $order);

        wp_send_json_success();
    }

    public function ajax_get_label(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'wc-eclicsys-shipping'), 403);
        }

        $tracking = sanitize_text_field($_GET['tracking'] ?? '');
        if (empty($tracking)) {
            wp_die(esc_html__('Tracking code required', 'wc-eclicsys-shipping'), 400);
        }

        $settings = WC_Eclicsys_Settings::get_instance();

        if (!$settings->is_configured()) {
            wp_die(esc_html__('API not configured', 'wc-eclicsys-shipping'), 500);
        }

        try {
            $client = new WC_Eclicsys_API_Client(
                $settings->get_api_key(),
                $settings->get_api_secret(),
                $settings->get_api_url()
            );

            $pdf = $client->get_label($tracking);

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="label-' . sanitize_file_name($tracking) . '.pdf"');
            echo $pdf;
            exit;

        } catch (Exception $e) {
            wp_die(esc_html($e->getMessage()), 500);
        }
    }
}