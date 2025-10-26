<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       WC_Gateway_PayCryptoMe
 * @extends     WC_Payment_Gateway
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

class WC_Gateway_PayCryptoMe extends \WC_Payment_Gateway
{
    protected $api_key;
    protected $testmode;
    protected $hide_for_non_admin_users;
    protected $enable_logging;

    public function __construct()
    {
        $this->id = 'paycrypto_me';
        $this->icon = WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-icon.png';
        $this->has_fields = false;
        $this->method_title = __('PayCrypto.Me', 'woocommerce-gateway-pay-crypto-me');
        $this->method_description = __('PayCrypto.Me introduces a complete solution to receive your payments through the main cryptocurrencies.', 'woocommerce-gateway-pay-crypto-me');

        $this->supports = ['products', 'pre-orders', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = $this->get_option('testmode');
        $this->api_key = $this->get_option('api_key');
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users', 'no');
        $this->enable_logging = $this->get_option('enable_logging', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        do_action('paycrypto_me_gateway_loaded', $this);

        if ($this->enable_logging === 'yes') {
            \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log('PayCrypto.Me Gateway initialized.');
        }
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Enable PayCrypto.Me', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'text',
                'description' => __('Name of the payment method displayed to the customer.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => __('Cryptocurrencies via PayCrypto.Me', 'woocommerce-gateway-pay-crypto-me'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'textarea',
                'description' => __('Description displayed to the customer at checkout.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => __('Pay with Bitcoin, Ethereum, Solana, and more.', 'woocommerce-gateway-pay-crypto-me'),
                'desc_tip' => true,
            ),
            'api_key' => array(
                'title' => __('API Key', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'text',
                'description' => __('Sua chave de API PayCrypto.Me.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => '',
            ),
            'testmode' => array(
                'title' => __('Test Mode', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Enable Test Mode', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'description' => __('Use the PayCrypto.Me test environment.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => 'yes',
            ),
            'hide_for_non_admin_users' => array(
                'title' => __('Hide for Non-Admin Users', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Show only for administrators', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('If enabled, only administrators will see the payment method.', 'woocommerce-gateway-pay-crypto-me'),
            ),
            'enable_logging' => array(
                'title' => __('Enable Log', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Save log events (WooCommerce > Status > Logs)', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('Save events for debugging.', 'woocommerce-gateway-pay-crypto-me'),
            ),
        );
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
    }

    public function is_available()
    {
        if ('yes' !== $this->enabled) {
            return false;
        }
        if ('yes' === $this->hide_for_non_admin_users && !current_user_can('manage_options')) {
            return false;
        }
        return true;
    }

    public function process_pre_order_payment($order)
    {
        return $this->process_payment($order->get_id());
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        do_action('paycrypto_me_before_payment', $order_id, $_POST);

        if ($this->enable_logging === 'yes') {
            \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log("starting process_payment for order $order_id");
        }

        $order->update_status('on-hold', __('Awaiting crypto payment.', 'woocommerce-gateway-pay-crypto-me'));
        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if ($this->enable_logging === 'yes') {
            \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log("Processing refund for order $order_id, amount: $amount, reason: $reason");
        }

        //@TODO: Implement real refund logic if supported by the API.

        return true;
    }
}