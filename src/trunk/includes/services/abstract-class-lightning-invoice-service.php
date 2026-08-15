<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       AbstractLightningInvoiceService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

abstract class AbstractLightningInvoiceService implements LightningInvoiceServiceContract
{
    public function __construct(
        protected HttpClientContract  $http,
        protected \WC_Payment_Gateway $gateway,
    ) {}

    /** Short label used to prefix HTTP/parse error log messages (e.g. "BTCPay", "lnd REST"). */
    abstract protected function error_log_label(): string;

    /** User-facing message shown when a request fails. */
    abstract protected function payment_failed_message(): string;

    /**
     * @throws PayCryptoMePaymentException when HTTP status >= 400, body is empty, or JSON is invalid
     */
    protected function parse_response(array $response): array
    {
        // Named separately from the HTTP branch below: a transport failure has no status code, and
        // recording it as "status=0 body=" hid the actual reason from the exception message.
        if (!empty($response[HttpClientContract::ERROR_KEY])) {
            throw new PayCryptoMePaymentException(
                \sprintf(
                    '%s transport error: %s',
                    esc_html($this->error_log_label()),
                    esc_html((string) $response[HttpClientContract::ERROR_KEY])
                ),
                esc_html($this->payment_failed_message())
            );
        }

        $status_code = (int) ($response['response']['code'] ?? 0);
        $body        = (string) ($response['body'] ?? '');

        if ($status_code >= 400 || $body === '') {
            throw new PayCryptoMePaymentException(
                \sprintf('%s HTTP error: status=%d body=%s', esc_html($this->error_log_label()), esc_html((string) $status_code), esc_html(substr($body, 0, 500))),
                esc_html($this->payment_failed_message())
            );
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new PayCryptoMePaymentException(
                \sprintf('%s invalid JSON response', esc_html($this->error_log_label())),
                esc_html($this->payment_failed_message())
            );
        }

        return $data;
    }
}
