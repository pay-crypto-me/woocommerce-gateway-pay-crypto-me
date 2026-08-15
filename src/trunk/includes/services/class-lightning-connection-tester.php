<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningConnectionTester
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Backs the admin "Test connection" buttons for both Lightning node types.
 * Each public method runs the full AJAX request lifecycle itself (permission
 * check, nonce, HTTP call, logging, wp_send_json_*) and never returns.
 */
class LightningConnectionTester
{
    public function __construct(
        private HttpClientContract  $http,
        private \WC_Payment_Gateway $gateway,
    ) {}

    public function test_btcpay_connection(): void
    {
        $this->ensure_permission();
        check_ajax_referer('paycrypto_btcpay_test', 'security');

        $url = isset($_POST['btcpay_url']) ? esc_url_raw(wp_unslash($_POST['btcpay_url'])) : '';
        $api = isset($_POST['btcpay_api_key']) ? sanitize_text_field(wp_unslash($_POST['btcpay_api_key'])) : '';
        $store = isset($_POST['btcpay_store_id']) ? sanitize_text_field(wp_unslash($_POST['btcpay_store_id'])) : '';

        if (empty($url)) {
            wp_send_json_error(array('message' => sprintf('%s is required for test.', __('BTCPay Server URL', 'paycrypto-me-for-woocommerce'))));
        }

        // Build endpoint to check: prefer store endpoint if provided, else list stores.
        $endpoint = rtrim($url, '/') . '/api/v1/stores';
        if ($store !== '') {
            $endpoint = rtrim($url, '/') . '/api/v1/stores/' . rawurlencode($store);
        }

        $headers = array('Accept' => 'application/json', 'Content-Type' => 'application/json');
        if ($api !== '') {
            $headers['Authorization'] = 'token ' . $api;
        }

        $response = $this->http->get($endpoint, array('timeout' => 15, 'headers' => $headers));

        $this->respond_from_http_result($response, 'BTCPay connection test failed');
    }

    public function test_lnd_connection(): void
    {
        $this->ensure_permission();
        check_ajax_referer('paycrypto_lnd_test', 'security');

        $url = isset($_POST['lnd_rest_url']) ? esc_url_raw(wp_unslash($_POST['lnd_rest_url'])) : '';
        $macaroon = isset($_POST['lnd_macaroon_hex']) ? sanitize_text_field(wp_unslash($_POST['lnd_macaroon_hex'])) : '';
        $certificate = isset($_POST['lnd_certificate']) ? wp_kses_post(wp_unslash($_POST['lnd_certificate'])) : '';
        $verify_ssl = isset($_POST['lnd_verify_ssl']) ? sanitize_text_field(wp_unslash($_POST['lnd_verify_ssl'])) : 'yes';

        if (empty($url)) {
            wp_send_json_error(array('message' => sprintf('%s is required for test.', __('lnd REST URL', 'paycrypto-me-for-woocommerce'))));
        }

        $endpoint = rtrim($url, '/') . '/v1/getinfo';

        $args = array(
            'timeout' => 15,
            'headers' => array('Accept' => 'application/json'),
        );

        // Handle SSL verification: use certificate if provided, otherwise use verify_ssl flag
        $temp_cert = '';
        $cert_write_failed = false;
        if (!empty($certificate)) {
            $temp_cert = tempnam(sys_get_temp_dir(), 'lnd_cert_');
            if ($temp_cert && file_put_contents($temp_cert, $certificate)) {
                $args['sslcertificates'] = $temp_cert;
            } else {
                // Same silent fall-through as LndRestInvoiceService::request_with_cert(): without
                // this the request ran with WordPress's default verification and the admin had no
                // way to know their certificate was never used. Here the test can simply stop and
                // say so, since testing with the wrong TLS setup proves nothing.
                $cert_write_failed = true;
            }
        } else {
            $args['sslverify'] = ($verify_ssl === 'yes');
        }

        if ($cert_write_failed) {
            if ($temp_cert && file_exists($temp_cert)) {
                wp_delete_file($temp_cert);
            }

            wp_send_json_error(array('message' => 'The TLS certificate could not be written to a temporary file, so the connection was not tested. Check the PHP temporary directory permissions on this host.'));
        }

        if (!empty($macaroon)) {
            $args['headers']['Grpc-Metadata-macaroon'] = $macaroon;
        }

        try {
            $response = $this->http->get($endpoint, $args);
        } finally {
            if (!empty($temp_cert) && file_exists($temp_cert)) {
                wp_delete_file($temp_cert);
            }
        }

        $this->respond_from_http_result($response, 'lnd REST connection test failed', function (array $data) {
            $alias = $data['alias'] ?? '';
            return $alias ? ' - ' . sprintf('Node: %s', esc_html($alias)) : '';
        });
    }

    private function ensure_permission(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }
    }

    /**
     * Shared tail of both test flows.
     *
     * A transport-level failure (DNS, TLS, timeout) never reached the HTTP status code, so
     * reporting it as "Request failed (HTTP 0)" told the admin nothing. HttpClientContract now
     * carries the reason and it is shown verbatim instead.
     *
     * @param callable(array): string|null $success_suffix Appends extra text (e.g. node alias) to the success message.
     */
    private function respond_from_http_result(array $response, string $log_prefix, ?callable $success_suffix = null): void
    {
        if (!empty($response[HttpClientContract::ERROR_KEY])) {
            $reason = (string) $response[HttpClientContract::ERROR_KEY];

            $this->gateway->register_paycrypto_me_log(
                \sprintf('%s: %s', $log_prefix, esc_html($reason)),
                'error'
            );

            wp_send_json_error(array('message' => sprintf(
                'Could not reach the server: %s',
                esc_html($reason)
            )));
        }

        $code = (int) ($response['response']['code'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($code >= 200 && $code < 300) {
            $message = sprintf('Connection OK (HTTP %d).', $code);
            if ($success_suffix) {
                $data = json_decode($body, true);
                $message .= $success_suffix(is_array($data) ? $data : array());
            }
            wp_send_json_success(array('message' => $message));
        }

        $this->gateway->register_paycrypto_me_log(
            \sprintf('%s: status=%d body=%s', $log_prefix, $code, esc_html(substr($body, 0, 500))),
            'error'
        );

        $message = sprintf('Request failed (HTTP %d).', $code);
        if (!empty($body)) {
            $message .= ' ' . wp_strip_all_tags(wp_trim_words($body, 40));
        }

        wp_send_json_error(array('message' => $message));
    }
}
