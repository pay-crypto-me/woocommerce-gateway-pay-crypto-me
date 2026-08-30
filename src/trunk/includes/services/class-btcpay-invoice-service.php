<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BtcpayInvoiceService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class BtcpayInvoiceService extends AbstractLightningInvoiceService
{
    public function create_invoice(array $args): LightningInvoiceResponse
    {
        $url  = "{$this->store_url()}/invoices";
        $body = [
            'amount'   => (string) ($args['amount'] ?? '0'),
            'currency' => (string) ($args['currency'] ?? 'BTC'),
            'metadata' => [
                'orderId'  => (string) ($args['order_id'] ?? ''),
                'itemDesc' => (string) ($args['memo'] ?? ''),
            ],
            'checkout' => [
                'speedPolicy'       => $this->get_speed_policy(),
                'paymentMethods'    => [$this->get_payment_method_id()],
                'expirationMinutes' => (int) floor((int) abs((int) ($args['expiry'] ?? 3600)) / 60),
            ],
        ];

        $response = $this->http->post($url, [
            'headers' => $this->auth_headers(['Content-Type' => 'application/json']),
            'body'    => wp_json_encode($body),
        ]);

        $data = $this->parse_response($response);

        $invoice_id    = (string) ($data['id'] ?? '');
        $checkout_link = $data['checkoutLink'] ?? null;
        $status        = (string) ($data['status'] ?? '');

        return new LightningInvoiceResponse($invoice_id, '', $status, $checkout_link);
    }

    public function get_invoice_status(string $invoice_id): LightningInvoiceStatusResponse
    {
        $url = "{$this->store_url()}/invoices/{$invoice_id}";

        $response = $this->http->get($url, [
            'headers' => $this->auth_headers(),
        ]);

        $data   = $this->parse_response($response);
        $status = (string) ($data['status'] ?? '');

        return new LightningInvoiceStatusResponse($status === 'Settled', $status);
    }

    public function resolve_payment_request(string $invoice_id): string
    {
        $url = "{$this->store_url()}/invoices/{$invoice_id}/payment-methods";

        $response = $this->http->get($url, [
            'headers' => $this->auth_headers(),
        ]);

        $methods = $this->parse_response($response);

        $payment_method_id = $this->get_payment_method_id();

        foreach ($methods as $method) {
            if (($method['paymentMethodId'] ?? '') === $payment_method_id) {
                $destination = (string) ($method['destination'] ?? '');

                if ($destination === '') {
                    $this->gateway->register_paycrypto_me_log(
                        \sprintf('BTCPay invoice %s: %s method present but destination is still empty.', $invoice_id, $payment_method_id),
                        'warning'
                    );
                }

                return $destination;
            }
        }

        $this->gateway->register_paycrypto_me_log(
            \sprintf(
                'BTCPay invoice %s: no %s payment method found (%d method(s) returned).',
                $invoice_id,
                $payment_method_id,
                count($methods)
            ),
            'warning'
        );

        return '';
    }

    /**
     * paymentMethodId used both to request the Lightning method at invoice creation and to
     * match it when resolving the bolt11 — same source, so the two can never drift apart.
     */
    private function get_payment_method_id(): string
    {
        return (string) apply_filters(
            'paycryptome_lightning_btcpay_payment_method_id',
            $this->gateway->get_option('btcpay_payment_method_id', WC_PayCryptoMe::BTCPAY_DEFAULT_PAYMENT_METHOD_ID)
        );
    }

    private function get_speed_policy(): string
    {
        return (string) apply_filters('paycryptome_lightning_btcpay_speed_policy', 'MediumSpeed');
    }

    private function store_url(): string
    {
        $btcpay_url = rtrim($this->gateway->get_option('btcpay_url'), '/');
        $store_id   = $this->gateway->get_option('btcpay_store_id');

        return "{$btcpay_url}/api/v1/stores/{$store_id}";
    }

    private function auth_headers(array $extra = []): array
    {
        return array_merge(
            ['Authorization' => 'token ' . $this->gateway->get_option('btcpay_api_key')],
            $extra
        );
    }

    protected function error_log_label(): string
    {
        return 'BTCPay';
    }

    protected function payment_failed_message(): string
    {
        return sprintf(
            /* translators: %s: payment integration name. */
            __('Payment via %s failed. Please try again.', 'paycrypto-me-for-woocommerce'),
            __('BTCPay Server', 'paycrypto-me-for-woocommerce')
        );
    }
}
