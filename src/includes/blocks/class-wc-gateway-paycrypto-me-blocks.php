<?php

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_PayCryptoMe_Blocks extends AbstractPaymentMethodType
{
    /**
     * The gateway instance.
     * @var WC_Gateway_PayCryptoMe
     */
    private $gateway;

    protected $name = 'paycrypto_me';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_paycrypto_me_settings', []);
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = $gateways[$this->name] ?? null;
    }

    public function is_active()
    {
        if (!function_exists('WC') || !WC()->payment_gateways) {
            return false;
        }

        if (!$this->gateway) {
            return false;
        }

        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        $script_path = "/assets/js/frontend/paycrypto-me-script.js?v=" . WC_PayCryptoMe::VERSION;
        $script_asset_path = WC_PayCryptoMe::plugin_abspath() . 'assets/js/frontend/paycrypto-me-script.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : [
                'dependencies' => ['wc-blocks-checkout'],
                'version' => '1.0.0'
            ];

        $script_url = WC_PayCryptoMe::plugin_url() . $script_path;

        wp_register_script(
            'wc-paycrypto-me-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-paycrypto-me-payments-blocks', 'woocommerce-gateway-paycrypto-me', dirname(__FILE__, 3) . '/languages/');
        }

        return ['wc-paycrypto-me-payments-blocks'];
    }

    public function get_payment_method_data()
    {
        if (!$this->gateway) {
            return [
                'title' => __('PayCrypto.Me', 'woocommerce-gateway-paycrypto-me'),
                'description' => __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'woocommerce-gateway-paycrypto-me'),
                'debug_log' => 'no',
                'supports' => ['products']
            ];
        }

        return [
            'icon' => $this->gateway->icon ?? '',
            'gateway_id' => $this->gateway->id ?? '',
            'crypto_currency' => $this->gateway->get_available_cryptocurrencies()[0] ?? '',
            'title' => $this->gateway->title ?: __('PayCrypto.Me', 'woocommerce-gateway-paycrypto-me'),
            'description' => $this->gateway->description ?: __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'woocommerce-gateway-paycrypto-me'),
            'debug_log' => $this->get_setting('debug_log', 'no'),
            'supports' => $this->gateway->supports ?? ['products']
        ];
    }

    public function get_setting($name, $default = '')
    {
        return isset($this->settings[$name]) ? $this->settings[$name] : $default;
    }
}

add_action(
    'woocommerce_blocks_payment_method_type_registration',
    function ($payment_method_registry) {
        $payment_method_registry->register(
            new \PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Blocks()
        );
    }
);