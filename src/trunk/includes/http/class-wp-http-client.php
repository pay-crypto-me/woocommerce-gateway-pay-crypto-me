<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       WpHttpClient
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class WpHttpClient implements HttpClientContract
{
    // Matches what LightningConnectionTester already uses for its "Test connection" calls.
    // Without an explicit timeout, WP's default (5s) is too short for a node behind Tor or a
    // cold lnd/BTCPay instance — worse, on the BTCPay create+resolve path the request has
    // already created the invoice on the node by the time it times out on our side.
    private const DEFAULT_TIMEOUT = 15;

    public function post(string $url, array $args): array
    {
        $response = wp_remote_post($url, $this->with_default_timeout($args));
        if (\is_wp_error($response)) {
            WC_PayCryptoMe::log(
                \sprintf('HTTP POST error to %s: %s', esc_url_raw($url), esc_html($response->get_error_message())),
                'error'
            );
            return [self::ERROR_KEY => $response->get_error_message()];
        }
        return $response;
    }

    public function get(string $url, array $args): array
    {
        $response = wp_remote_get($url, $this->with_default_timeout($args));
        if (\is_wp_error($response)) {
            WC_PayCryptoMe::log(
                \sprintf('HTTP GET error to %s: %s', esc_url_raw($url), esc_html($response->get_error_message())),
                'error'
            );
            return [self::ERROR_KEY => $response->get_error_message()];
        }
        return $response;
    }

    /** Caller-supplied 'timeout' (if any) always wins over the default. */
    private function with_default_timeout(array $args): array
    {
        return $args + ['timeout' => self::DEFAULT_TIMEOUT];
    }
}
