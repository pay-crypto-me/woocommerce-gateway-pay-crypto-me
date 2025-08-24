<?php
/**
 * WC_Gateway_PayCrypto_Me class
 *
 * @author   PayCrypto.Me
 * @package  PayCrypto.Me for WooCommerce
 * @since    0.1.0
 */

defined('ABSPATH') || exit;

/**
 * PayCrypto.Me Gateway.
 *
 * @class    WC_Gateway_PayCrypto_Me
 * @version  0.1.0
 */
class WC_Gateway_PayCrypto_Me extends WC_Payment_Gateway
{
    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        // $this->id = 'paycrypto_me';
        // $this->icon = apply_filters('woocommerce_paycrypto_me_icon', '');
        // $this->has_fields = false;
        // $this->method_title = __('PayCrypto.Me', 'woocommerce-gateway-pay-crypto-me');
        // $this->method_description = __('Accept cryptocurrency payments via PayCrypto.Me.', 'woocommerce-gateway-pay-crypto-me');

        // // Load the settings.
        // $this->init_form_fields();
        // $this->init_settings();

        // // Define user set variables.
        // $this->title = $this->get_option('title');
        // $this->description = $this->get_option('description');
        // $this->api_key = $this->get_option('api_key');
        // $this->api_secret = $this->get_option('api_secret');
        // $this->test_mode = 'yes' === $this->get_option('test_mode', 'no');
        // $this->debug_mode = 'yes' === $this->get_option('debug_mode', 'no');

        // // Actions.
        // add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        // add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

        // // Customer Emails.
        // add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);

        // // Maybe log.
        // if ($this->debug_mode) {
        //     if (class_exists('WC_Logger')) {
        //         $this->log = new WC_Logger();
        //     } else {
        //         $this->log = new WC_Logger_WP();
        //     }
        // }

        /** */

        $this->id                 = 'paycrypto-me';
        // $this->icon               = '';
        $this->has_fields         = false;
        $this->method_title       = __('PayCrypto.Me Payments', 'pay-crypto-me-woocommerce');
        $this->method_description = __('PayCrypto.Me Payments introduces a complete solution to receive your payments through the main cryptocurrencies.', 'pay-crypto-me-woocommerce');

        $this->supports = array('products', 'pre-orders');

        // $this->init_form_fields();
        // $this->init_settings();

        // $this->title       = $this->get_option('title');
        // $this->description = $this->get_option('description');
        // $this->enabled     = $this->get_option('enabled');
        // $this->testmode    = 'yes' === $this->get_option('testmode');

        // add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'pay-crypto-me-woocommerce'),
                'label' => __('Enable PayCrypto.Me Payments', 'pay-crypto-me-woocommerce'),
                'type' => 'checkbox',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'pay-crypto-me-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'pay-crypto-me-woocommerce'),
                'default' => __('Pay with Crypto', 'pay-crypto-me-woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'pay-crypto-me-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'pay-crypto-me-woocommerce'),
                'default' => __('Pay with your cryptocurrency.', 'pay-crypto-me-woocommerce'),
            ),
            'testmode' => array(
                'title' => __('Test mode', 'pay-crypto-me-woocommerce'),
                'label' => __('Enable Test Mode', 'pay-crypto-me-woocommerce'),
                'type' => 'checkbox',
                'default' => 'yes',
                'desc_tip' => true,
            )
        );
    }
    public function is_available()
    {
        if ('yes' !== $this->enabled) {
            return false;
        }
        return true;
    }
    public function process_payment($order_id)
    {
        //TODO: Implement the payment processing logic here
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
    }
}