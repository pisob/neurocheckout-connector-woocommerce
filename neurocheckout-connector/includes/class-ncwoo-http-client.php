<?php

if (!defined('ABSPATH')) {
    exit;
}

final class NCWooHttpClient
{
    private const GZIP_THRESHOLD = 1024;

    private NCWooConfig $config;

    public function __construct(NCWooConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function send_cart_event(array $payload, array $options = []): array
    {
        return $this->send_with_candidates('/api/v1/events/cart', $payload, $options);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function send_order_event(array $payload, array $options = []): array
    {
        return $this->send_with_candidates('/api/v1/events/order', $payload, $options);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function send_support_case_event(array $payload, array $options = []): array
    {
        return $this->send_with_candidates('/api/v1/events/support', $payload, $options);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function send_telemetry_event(array $payload, array $options = []): array
    {
        return $this->send_with_candidates('/api/v1/events/telemetry', $payload, $options);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function send_customer_journey_event(array $payload, array $options = []): array
    {
        return $this->send_with_candidates('/api/v1/events/customer-journey', $payload, $options);
    }

    /**
     * @return array<string,mixed>
     */
    public function health(array $payload = []): array
    {
        $endpoint = rtrim($this->config->get_string(NCWooConfig::OPTION_API_ENDPOINT), '/');
        $apiKey = $this->config->get_api_key();

        if ($endpoint === '' || $apiKey === '') {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'API configuration missing',
            ];
        }

        $headers = [
            'Accept' => 'application/json',
            'X-API-Key' => $apiKey,
        ];

        $candidates = [
            $endpoint . '/health',
            $endpoint . '/',
        ];

        $lastError = '';
        $lastStatus = 0;
        $lastBody = '';

        foreach ($candidates as $url) {
            $response = wp_remote_get(
                $url,
                [
                    'timeout' => 10,
                    'headers' => $headers,
                ]
            );

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);

            if ($status >= 200 && $status < 300) {
                return [
                    'success' => true,
                    'status' => $status,
                    'body' => $body,
                ];
            }

            $lastStatus = $status;
            $lastBody = $body;
            $lastError = 'API check failed';
        }

        return $this->error_response($lastStatus, $lastError !== '' ? $lastError : 'API check failed', $lastBody);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function send_with_candidates(string $path, array $payload, array $options): array
    {
        $endpoint = rtrim($this->config->get_string(NCWooConfig::OPTION_API_ENDPOINT), '/');
        $candidates = $this->resolve_api_key_candidates();

        if ($endpoint === '' || empty($candidates)) {
            return $this->error_response(0, 'API configuration missing');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->error_response(0, 'Invalid API endpoint');
        }

        $last = $this->error_response(0, 'API request not sent');
        $count = count($candidates);

        foreach ($candidates as $index => $candidate) {
            $result = $this->send_signed_request(
                $endpoint . $path,
                (string) ($candidate['key'] ?? ''),
                $payload,
                $options
            );

            if (!empty($result['success'])) {
                if (($candidate['name'] ?? '') === 'pending') {
                    $this->promote_pending_key((string) $candidate['key']);
                }
                return $result;
            }

            $last = $result;
            $status = (int) ($result['status'] ?? 0);
            $isAuthRetryable = in_array($status, [401, 403], true);
            if ($index < ($count - 1) && $isAuthRetryable) {
                continue;
            }

            return $last;
        }

        return $last;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function send_signed_request(string $url, string $apiKey, array $payload, array $options): array
    {
        if ($apiKey === '') {
            return $this->error_response(0, 'API key missing');
        }

        $jsonPayload = wp_json_encode($payload);
        if (!is_string($jsonPayload) || $jsonPayload === '') {
            return $this->error_response(0, 'Invalid payload encoding');
        }

        $bodyToSend = $jsonPayload;
        $useGzip = false;
        if (strlen($jsonPayload) > self::GZIP_THRESHOLD && function_exists('gzencode')) {
            $encodedBody = @gzencode($jsonPayload, 6);
            if (is_string($encodedBody) && $encodedBody !== '') {
                $bodyToSend = $encodedBody;
                $useGzip = true;
            }
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = $timestamp . '.' . $nonce . '.' . $bodyToSend;
        $signature = hash_hmac('sha256', $message, $apiKey);

        $headers = [
            'Content-Type' => 'application/json',
            'X-API-Key' => $apiKey,
            'X-Neuro-Timestamp' => $timestamp,
            'X-Neuro-Nonce' => $nonce,
            'X-Neuro-Signature' => $signature,
            'X-Neuro-Version' => '7',
            'Idempotency-Key' => hash('sha256', (string) ($payload['event_id'] ?? $jsonPayload)),
        ];

        if ($useGzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        if (!empty($options['is_cron_test'])) {
            $headers['X-Neuro-Cron-Test'] = '1';
            $headers['X-Neuro-Test-Mode'] = '1';
        }

        if (!empty($options['is_api_test'])) {
            $headers['X-Neuro-Api-Test'] = '1';
            $headers['X-Neuro-Test-Mode'] = '1';
        }

        $response = wp_remote_post(
            $url,
            [
                'timeout' => (int) (!empty($options['order_timeout']) ? 2 : 8),
                'headers' => $headers,
                'body' => $bodyToSend,
            ]
        );

        if (is_wp_error($response)) {
            return $this->error_response(0, $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            return [
                'success' => true,
                'status' => $status,
                'body' => $body,
            ];
        }

        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? (string) ($decoded['error'] ?? ('http_' . $status)) : ('http_' . $status);

        return $this->error_response($status, $error, $body);
    }

    /**
     * @return array<int,array{name:string,key:string}>
     */
    private function resolve_api_key_candidates(): array
    {
        $candidates = [];
        $seen = [];

        $current = $this->config->get_api_key();
        if ($current !== '') {
            $candidates[] = ['name' => 'current', 'key' => $current];
            $seen[$current] = true;
        }

        $pending = $this->config->get_pending_api_key();
        if ($pending !== '' && !isset($seen[$pending])) {
            $candidates[] = ['name' => 'pending', 'key' => $pending];
            $seen[$pending] = true;
        }

        $previous = $this->config->get_valid_previous_api_key();
        if ($previous !== '' && !isset($seen[$previous])) {
            $candidates[] = ['name' => 'previous', 'key' => $previous];
        }

        return $candidates;
    }

    private function promote_pending_key(string $pendingApiKey): void
    {
        $pendingApiKey = trim($pendingApiKey);
        if ($pendingApiKey === '') {
            return;
        }

        $current = $this->config->get_api_key();
        if ($current !== '' && !hash_equals($current, $pendingApiKey)) {
            $this->config->set(NCWooConfig::OPTION_API_KEY_PREV, $current);
            $this->config->set(NCWooConfig::OPTION_API_KEY_PREV_UNTIL, time() + 900);
        }

        $this->config->set(NCWooConfig::OPTION_API_KEY, $pendingApiKey);
        $this->config->set(NCWooConfig::OPTION_API_KEY_NEXT, '');
        $this->config->set(NCWooConfig::OPTION_API_KEY_ROTATION_ID, '');
    }

    /**
     * @return array<string,mixed>
     */
    private function error_response(int $status, string $error, string $body = ''): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error' => $error,
            'body' => $body,
        ];
    }
}
