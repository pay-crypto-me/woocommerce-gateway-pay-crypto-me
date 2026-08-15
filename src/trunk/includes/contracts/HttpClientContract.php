<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Generic HTTP client contract.
 *
 * Convention: every service that makes HTTP calls injects this interface —
 * never call wp_remote_post / wp_remote_get directly.
 *
 * A transport-level failure (DNS, TLS, timeout) returns an array WITHOUT a
 * 'response' key and WITH self::ERROR_KEY holding the reason, so callers can
 * report "could not resolve host" instead of a meaningless "HTTP 0".
 */
interface HttpClientContract
{
    public const ERROR_KEY = 'paycrypto_transport_error';

    /**
     * @param array $args wp_remote_post-compatible args (headers, body, timeout, sslverify, sslcertificates…)
     * @return array wp_remote_* response array, or [self::ERROR_KEY => string] on transport failure
     */
    public function post(string $url, array $args): array;

    /**
     * @param array $args wp_remote_get-compatible args
     * @return array wp_remote_* response array, or [self::ERROR_KEY => string] on transport failure
     */
    public function get(string $url, array $args): array;
}
