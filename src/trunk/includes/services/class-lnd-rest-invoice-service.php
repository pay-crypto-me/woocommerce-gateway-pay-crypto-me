<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LndRestInvoiceService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LndRestInvoiceService extends AbstractLightningInvoiceService
{
    public function create_invoice(array $args): LightningInvoiceResponse
    {
        $lnd_url  = rtrim($this->gateway->get_option('lnd_rest_url'), '/');
        $macaroon = $this->gateway->get_option('lnd_macaroon_hex');

        $url  = "{$lnd_url}/v1/invoices";
        $body = [
            'memo'   => (string) ($args['memo'] ?? ''),
            'expiry' => (string) ((int) abs((int) ($args['expiry'] ?? 3600))),
        ];

        // Free version creates zero-amount invoices; the premium add-on's fiat→sats filter
        // (paycryptome_lightning_lnd_invoice_args) sets `value` in sats to enforce the amount.
        if (isset($args['value'])) {
            $body['value'] = (string) ((int) abs((int) $args['value']));
        }

        $data = $this->request_with_cert('post', $url, [
            'headers' => [
                'Grpc-Metadata-macaroon' => $macaroon,
                'Content-Type'           => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        $payment_request = (string) ($data['payment_request'] ?? '');
        $r_hash_b64      = (string) ($data['r_hash'] ?? '');
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding r_hash from the trusted lnd REST response, not obfuscating code.
        $invoice_id      = bin2hex(base64_decode(strtr($r_hash_b64, '-_', '+/')));

        return new LightningInvoiceResponse($invoice_id, $payment_request, 'OPEN');
    }

    public function resolve_payment_request(string $invoice_id): string
    {
        // lnd's create_invoice() always returns payment_request synchronously; nothing to resolve.
        return '';
    }

    public function get_invoice_status(string $invoice_id): LightningInvoiceStatusResponse
    {
        $lnd_url  = rtrim($this->gateway->get_option('lnd_rest_url'), '/');
        $macaroon = $this->gateway->get_option('lnd_macaroon_hex');

        $url  = "{$lnd_url}/v1/invoice/{$invoice_id}";
        $data = $this->request_with_cert('get', $url, [
            'headers' => [
                'Grpc-Metadata-macaroon' => $macaroon,
            ],
        ]);

        $state = (string) ($data['state'] ?? '');

        return new LightningInvoiceStatusResponse($state === 'SETTLED', $state);
    }

    /**
     * Wraps an HTTP call with lnd's TLS-certificate handling: when a cert is configured it is
     * written to a temp file passed as `sslcertificates`, otherwise `sslverify` is toggled by the
     * setting. The temp file is always removed afterward, including on parse/exception paths.
     *
     * @param 'post'|'get' $method
     * @throws PayCryptoMePaymentException from parse_response() on HTTP/JSON errors
     */
    private function request_with_cert(string $method, string $url, array $http_args): array
    {
        $certificate = $this->gateway->get_option('lnd_certificate');
        $verify_ssl  = $this->gateway->get_option('lnd_verify_ssl');

        $temp_cert = '';
        if (!empty($certificate)) {
            $temp_cert = tempnam(sys_get_temp_dir(), 'lnd_cert_');
            if ($temp_cert && file_put_contents($temp_cert, $certificate)) {
                $http_args['sslcertificates'] = $temp_cert;
            }
        } else {
            $http_args['sslverify'] = ($verify_ssl === 'yes');
        }

        try {
            $response = $this->http->{$method}($url, $http_args);
            return $this->parse_response($response);
        } finally {
            if (!empty($temp_cert) && file_exists($temp_cert)) {
                wp_delete_file($temp_cert);
            }
        }
    }

    protected function error_log_label(): string
    {
        return 'lnd REST';
    }

    protected function payment_failed_message(): string
    {
        return __('Payment via Lightning node failed. Please try again.', 'paycrypto-me-for-woocommerce');
    }
}
