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
     * Get cached auth token or request new one
     */
    private function get_token(): string {
        // Check instance cache first
        if ($this->token && $this->token_expires > (time() + 60)) {
            return $this->token;
        }

        // Check WordPress transient cache
        $cached = get_transient('eclicsys_api_token');
        if ($cached && is_array($cached) && $cached['expires'] > time()) {
            $this->token = $cached['token'];
            $this->token_expires = $cached['expires'];
            return $this->token;
        }

        $response = wp_remote_post($this->api_url . '/integrations/authenticate', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'integration_id'     => $this->api_key,
                'integration_secret' => $this->api_secret,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Auth failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200 || empty($body['token'])) {
            $error = $body['message'] ?? 'Unknown error';
            throw new Exception('Auth failed: ' . $error);
        }

        $this->token = $body['token'];
        $expires_in = (int) ($body['expires_in'] ?? 3600);
        $this->token_expires = time() + $expires_in;

        // Cache in transient for 5 minutes less than expiry
        set_transient('eclicsys_api_token', [
            'token'   => $this->token,
            'expires' => $this->token_expires,
        ], max($expires_in - 300, 60));

        return $this->token;
    }

    /**
     * Generic API request handler
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
            $response = wp_remote_get($url, $args);
        } else {
            $args['body'] = json_encode($data);
            $response = wp_remote_post($url, $args);
        }

        if (is_wp_error($response)) {
            throw new Exception('Request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $error = $body['message'] ?? 'HTTP ' . $code;
            throw new Exception('API error: ' . $error);
        }

        return $body ?? [];
    }

    public function get_shipping_rates(string $zip_code, array $items): array {
        return $this->request('/integrations/tariffs', [
            'zip_code' => $zip_code,
            'items'    => $items,
        ]);
    }

    public function create_shipping_order(array $order_data): array {
        return $this->request('/integrations/order', $order_data);
    }

    public function get_label(string $tracking_code): string {
        $response = wp_remote_get($this->api_url . '/integrations/order/tracking', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->get_token(),
                'Accept'        => 'application/pdf',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Label request failed: ' . $response->get_error_message());
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            throw new Exception('Label not available');
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Extract tracking information from API response.
     * The response structure is:
     * {
     *   "order": {
     *     "id": 102,
     *     "shipping": [{"tracking_code": "TRACK00102XQAJWH"}]
     *   }
     * }
     */
    public static function extract_tracking(array $response): ?string {
        if (!empty($response['order']['shipping']) && is_array($response['order']['shipping'])) {
            $first_shipment = $response['order']['shipping'][0];
            if (!empty($first_shipment['tracking_code'])) {
                return $first_shipment['tracking_code'];
            }
        }
        return null;
    }

    /**
     * Extract order ID from API response.
     */
    public static function extract_order_id(array $response): ?int {
        return $response['order']['id'] ?? null;
    }

    /**
     * Extract status from API response.
     */
    public static function extract_status(array $response): string {
        return $response['order']['status_internal_process'] ?? 'processing';
    }
}