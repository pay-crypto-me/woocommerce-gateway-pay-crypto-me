<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningConfigValidator
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Pure, gateway-agnostic validation/sanitization for the Lightning gateway settings fields.
 *
 * Each method mirrors one of the gateway's WC_Settings_API `validate_<key>_field()` hooks.
 * "Is lnd_rest selected" is resolved by the gateway (it reads the submitted POST) and passed
 * in, so this class needs no WC_Payment_Gateway instance and is unit-testable in isolation.
 * Field-level errors are surfaced through WC_Admin_Settings::add_error() exactly as before.
 */
class LightningConfigValidator
{
    public function is_lnd_rest_selected(array $post_data, string $node_type_field_key): bool
    {
        $node_type = isset($post_data[$node_type_field_key])
            ? sanitize_text_field(wp_unslash($post_data[$node_type_field_key]))
            : 'btcpay';

        return $node_type === 'lnd_rest';
    }

    public function validate_btcpay_url($value, bool $is_lnd_rest_selected): string
    {
        if ($is_lnd_rest_selected || is_null($value) || $value === '') {
            return is_null($value) ? '' : esc_url_raw(trim(wp_unslash($value)));
        }
        $val = trim(wp_unslash($value));
        $url = esc_url_raw($val);
        if (empty($url)) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            /* translators: %s: field label, e.g. "BTCPay Server URL". */
            \WC_Admin_Settings::add_error(sprintf(esc_html__('%s must use HTTPS.', 'paycrypto-me-for-woocommerce'), esc_html__('BTCPay Server URL', 'paycrypto-me-for-woocommerce')));
            return '';
        }
        return $url;
    }

    public function validate_btcpay_api_key($value, bool $is_lnd_rest_selected): string
    {
        $val = $this->sanitize_text_val($value);
        if (!$is_lnd_rest_selected && $val !== '' && strlen($val) < 20) {
            /* translators: 1: field label, e.g. "BTCPay API Key". 2: minimum length. */
            \WC_Admin_Settings::add_error(sprintf(esc_html__('%1$s must be at least %2$d characters.', 'paycrypto-me-for-woocommerce'), esc_html__('BTCPay API Key', 'paycrypto-me-for-woocommerce'), 20));
            return '';
        }
        return $val;
    }

    public function validate_btcpay_store_id($value): string
    {
        return $this->sanitize_text_val($value);
    }

    public function validate_btcpay_payment_method_id($value): string
    {
        $val = $this->sanitize_text_val($value);
        return $val !== '' ? $val : 'BTC-LN';
    }

    public function validate_btcpay_webhook_secret($value, bool $is_lnd_rest_selected): string
    {
        $val = $this->sanitize_text_val($value);
        if (!$is_lnd_rest_selected && $val !== '' && strlen($val) < 16) {
            \WC_Admin_Settings::add_error(esc_html__('BTCPay webhook secret is shorter than the recommended 16 characters.', 'paycrypto-me-for-woocommerce'));
        }
        return $val;
    }

    public function validate_lnd_rest_url($value, bool $is_lnd_rest_selected): string
    {
        if (!$is_lnd_rest_selected || is_null($value) || $value === '') {
            return is_null($value) ? '' : esc_url_raw(trim(wp_unslash($value)));
        }
        $val = trim(wp_unslash($value));
        $url = esc_url_raw($val);
        if (empty($url)) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            /* translators: %s: field label, e.g. "BTCPay Server URL". */
            \WC_Admin_Settings::add_error(sprintf(esc_html__('%s must use HTTPS.', 'paycrypto-me-for-woocommerce'), esc_html__('lnd REST URL', 'paycrypto-me-for-woocommerce')));
            return '';
        }
        return $url;
    }

    public function validate_lnd_macaroon_hex($value, bool $is_lnd_rest_selected): string
    {
        $val = $this->sanitize_text_val($value);
        $val = preg_replace('/\s+/', '', $val);
        if ($is_lnd_rest_selected && $val !== '') {
            if (strlen($val) < 100) {
                /* translators: 1: field label, e.g. "BTCPay API Key". 2: minimum length. */
                \WC_Admin_Settings::add_error(sprintf(esc_html__('%1$s must be at least %2$d characters.', 'paycrypto-me-for-woocommerce'), esc_html__('lnd Macaroon (hex)', 'paycrypto-me-for-woocommerce'), 100));
                return '';
            }
            if (!ctype_xdigit($val)) {
                \WC_Admin_Settings::add_error(esc_html__('lnd Macaroon must be a valid hexadecimal string.', 'paycrypto-me-for-woocommerce'));
                return '';
            }
        }
        return $val;
    }

    public function validate_lnd_certificate($value, bool $is_lnd_rest_selected): string
    {
        $val = wp_kses_post(wp_unslash($value));
        if (!$is_lnd_rest_selected || $val === '') {
            return $val;
        }
        if (strpos($val, '-----BEGIN CERTIFICATE-----') === false || strpos($val, '-----END CERTIFICATE-----') === false) {
            \WC_Admin_Settings::add_error(esc_html__('Invalid certificate format. Must be valid PEM format starting with -----BEGIN CERTIFICATE-----.', 'paycrypto-me-for-woocommerce'));
            return '';
        }
        return $val;
    }

    public function validate_invoice_expiry($value): string
    {
        $val = absint($value);
        if ($val < 300) {
            \WC_Admin_Settings::add_error(esc_html__('Invoice Expiry must be at least 300 seconds (5 minutes).', 'paycrypto-me-for-woocommerce'));
            return '3600';
        }
        if ($val > 86400) {
            \WC_Admin_Settings::add_error(esc_html__('Invoice Expiry cannot exceed 86400 seconds (24 hours).', 'paycrypto-me-for-woocommerce'));
            return '3600';
        }
        return strval($val);
    }

    private function sanitize_text_val($v): string
    {
        return is_null($v) ? '' : sanitize_text_field(wp_unslash($v));
    }
}
