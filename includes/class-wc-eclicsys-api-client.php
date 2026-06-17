<?php
/**
 * API Client for Eclicsys
 */

class WC_Eclicsys_API_Client {

    private string $api_url;
    private string $api_key;
    private string $api_secret;
    private ?string $token = null;
    private int $token_expires = 0;

    public function __construct(string $api_key, string $api_secret, string $api_url) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->api_url = rtrim($api_url, '/');
    }

    /**
     * Log message using WooCommerce logger or error_log
     */
    private function log(string $message, array $context = []): void {
        $settings = WC_Eclicsys_Settings::get_instance();

        if (!$settings->is_debug()) {
            return;
        }

        $log_message = '[Eclicsys API] ' . $message;
        if (!empty($context)) {
            $log_message .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($log_message, ['source' => 'eclicsys-api']);
        } else {
            error_log($log_message);
        }
    }

    /**
     * Generate a unique cache key based on credentials and URL.
     * This ensures tokens are not shared between different environments.
     */
    private function get_token_cache_key(): string {
        return 'eclicsys_api_token_' . md5($this->api_key . $this->api_secret . $this->api_url);
    }

    /**
     * Get cached auth token or request new one.
     * Tokens are cached per environment (key + secret + URL) to prevent
     * cross-contamination between sandbox and production.
     */
    private function get_token(): string {
        $cache_key = $this->get_token_cache_key();

        // Check instance cache first
        if ($this->token && $this->token_expires > (time() + 60)) {
            $this->log('Reusing cached token', ['expires' => date('Y-m-d H:i:s', $this->token_expires)]);
            return $this->token;
        }

        // Check WordPress transient cache - now keyed by environment
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached) && $cached['expires'] > time()) {
            $this->token = $cached['token'];
            $this->token_expires = $cached['expires'];
            $this->log('Reusing token from transient', [
                'cache_key' => $cache_key,
                'expires' => date('Y-m-d H:i:s', $this->token_expires),
            ]);
            return $this->token;
        }

        $this->log('Requesting new auth token', [
            'url' => $this->api_url . '/integrations/authenticate',
            'cache_key' => $cache_key,
        ]);

        $response = wp_remote_post($this->api_url . '/integrations/authenticate', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'integration_id'     => $this->api_key,
                'integration_secret' => $this->api_secret,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            $this->log('Auth failed', ['error' => $response->get_error_message()]);
            throw new Exception('Auth failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        $this->log('Auth response', ['code' => $code, 'has_token' => !empty($body['token'])]);

        if ($code !== 200 || empty($body['token'])) {
            $error = $body['message'] ?? 'Unknown error';
            $this->log('Auth error', ['code' => $code, 'error' => $error]);
            throw new Exception('Auth failed: ' . $error);
        }

        $this->token = $body['token'];
        $expires_in = (int) ($body['expires_in'] ?? 3600);
        $this->token_expires = time() + $expires_in;

        // Cache in transient with environment-specific key
        set_transient($cache_key, [
            'token'   => $this->token,
            'expires' => $this->token_expires,
        ], max($expires_in - 300, 60));

        $this->log('Token obtained', ['expires_in' => $expires_in, 'cache_key' => $cache_key]);

        return $this->token;
    }

    /**
     * Generic API request handler with detailed logging
     */
    private function request(string $endpoint, array $data = [], string $method = 'POST'): array {
        $token = $this->get_token();
        $url = $this->api_url . $endpoint;

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ];

        if ($method === 'GET') {
            if (!empty($data)) {
                $url = add_query_arg($data, $url);
            }
            $this->log('GET ' . $endpoint, ['url' => $url]);
            $response = wp_remote_get($url, $args);
        } else {
            $args['body'] = json_encode($data);
            $this->log('POST ' . $endpoint, ['url' => $url, 'payload' => $data]);
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) {
            $this->log('Request failed', ['endpoint' => $endpoint, 'error' => $response->get_error_message()]);
            throw new Exception('Request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        $this->log('Response ' . $endpoint, ['code' => $code, 'body' => $body ?? $body_raw]);

        if ($code >= 400) {
            $error = '';
            if (is_array($body) && !empty($body['message'])) {
                $error = $body['message'];
            } elseif (is_array($body) && !empty($body['error'])) {
                $error = $body['error'];
            } else {
                $error = strip_tags($body_raw);
                $error = substr($error, 0, 500);
            }
            
            $this->log('API error details', ['code' => $code, 'error' => $error, 'raw' => substr($body_raw, 0, 1000)]);
            throw new Exception('API error: HTTP ' . $code . ' - ' . $error);
        }

        return $body ?? [];
    }

    public function get_shipping_rates(string $zip_code, array $items): array {
        $this->log('Getting shipping rates', ['zip_code' => $zip_code, 'items_count' => count($items)]);
        return $this->request('/integrations/tariffs', [
            'zip_code' => $zip_code,
            'items'    => $items,
        ]);
    }

    public function create_shipping_order(array $order_data): array {
        $this->log('Creating shipping order', ['order_reference' => $order_data['order_reference'] ?? 'N/A']);
        return $this->request('/integrations/order', $order_data);
    }

    /**
     * Get shipping label PDF by tracking code
     */
    public function get_label(string $tracking_code): string {
        $this->log('Getting label', ['tracking' => $tracking_code]);

        $token = $this->get_token();
        
        $url = add_query_arg([
            'tracking_code' => $tracking_code,
        ], $this->api_url . '/integrations/order/tracking');

        $this->log('Label request URL', ['url' => $url]);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/pdf',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->log('Label request failed', ['error' => $response->get_error_message()]);
            throw new Exception('Label request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $this->log('Label response', ['code' => $code]);

        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            throw new Exception('Label not available (HTTP ' . $code . '): ' . $body);
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Extract tracking from API response
     */
    public static function extract_tracking(array $response): ?string {
        if (!empty($response['order']['shipping']) && is_array($response['order']['shipping'])) {
            $first = $response['order']['shipping'][0];
            if (!empty($first['tracking_code'])) {
                return $first['tracking_code'];
            }
        }
        return null;
    }

    public static function extract_order_id(array $response): ?int {
        return $response['order']['id'] ?? null;
    }

    public static function extract_status(array $response): string {
        return $response['order']['status_internal_process'] ?? 'processing';
    }
}