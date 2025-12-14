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
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        $script_path = "/assets/js/frontend/blocks.js?v=" . WC_PayCryptoMe::VERSION;
        $script_asset_path = WC_PayCryptoMe::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : [
                'dependencies' => [],
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
            wp_set_script_translations('wc-paycrypto-me-payments-blocks', 'woocommerce-gateway-pay-crypto-me', dirname(__FILE__, 3) . '/languages/');
        }

        wp_localize_script(
            'wc-paycrypto-me-payments-blocks',
            'paycrypto_me_data',
            $this->get_payment_method_data()
        );

        return ['wc-paycrypto-me-payments-blocks'];
    }

    public function get_payment_method_data()
    {
        return [
            'icon' => $this->gateway->icon,
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'enable_logging' => $this->get_setting('enable_logging', 'no'),
            'supports' => array_filter($this->gateway->supports, [$this->gateway, 'supports'])
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