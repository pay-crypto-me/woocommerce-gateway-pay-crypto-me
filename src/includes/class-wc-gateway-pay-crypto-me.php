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
    protected $hide_for_non_admin_users;
    protected $configured_networks;
    protected $debug_log;
    protected $payment_timeout_hours;
    private $support_btc_address = 'bc1qgvc07956sxuudk3jku6n03q5vc9tkrvkcar7uw';
    private $support_btc_payment_address = 'PM8TJdrkRoSqkCWmJwUMojQCG1rEXsuCTQ4GG7Gub7SSMYxaBx7pngJjhV8GUeXbaJujy8oq5ybpazVpNdotFftDX7f7UceYodNGmffUUiS5NZFu4wq4';

    public function __construct()
    {
        $this->id = 'paycrypto_me';
        $this->icon = WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-icon.png';
        $this->has_fields = true;
        $this->method_title = __('PayCrypto.Me', 'woocommerce-gateway-pay-crypto-me');
        $this->method_description = _x('PayCrypto.Me introduces a complete solution to receive your payments through the main cryptocurrencies.', 'Gateway description', 'woocommerce-gateway-pay-crypto-me');

        $this->supports = ['products', 'pre-orders', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title') ?: __('Pay with Bitcoin', 'woocommerce-gateway-pay-crypto-me');
        $this->description = $this->get_option('description') ?: __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'woocommerce-gateway-pay-crypto-me');
        $this->enabled = $this->get_option('enabled');
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users', 'no');
        $this->debug_log = $this->get_option('debug_log', 'yes');
        $this->configured_networks = $this->get_option('configured_networks', array());
        $this->payment_timeout_hours = $this->get_option('payment_timeout_hours', '1');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Carrega CSS no checkout
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_styles'));

        do_action('paycrypto_me_gateway_loaded', $this);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }

    /**
     * Get available network types
     */
    public function get_available_networks()
    {
        return array(
            'mainnet' => array(
                'name' => __('Bitcoin Mainnet', 'woocommerce-gateway-pay-crypto-me'),
                'address_prefix' => array('1', '3', 'bc1'),
                'xpub_prefix' => array('xpub', 'ypub', 'zpub'),
                'testnet' => false,
                'field_type' => 'text',
                'field_label' => __('Wallet xPub', 'woocommerce-gateway-pay-crypto-me'),
                'field_placeholder' => 'xpub6...',
            ),
            'testnet' => array(
                'name' => __('Bitcoin Testnet', 'woocommerce-gateway-pay-crypto-me'),
                'address_prefix' => array('m', 'n', '2', 'tb1'),
                'xpub_prefix' => array('tpub', 'upub', 'vpub'),
                'testnet' => true,
                'field_type' => 'text',
                'field_label' => __('Testnet xPub', 'woocommerce-gateway-pay-crypto-me'),
                'field_placeholder' => 'tpub6...',
            ),
            'lightning' => array(
                'name' => __('Lightning Network', 'woocommerce-gateway-pay-crypto-me'),
                'address_prefix' => array('lnbc', 'lntb', 'lnbcrt'),
                'xpub_prefix' => array(),
                'testnet' => false,
                'field_type' => 'text',
                'field_label' => __('Lightning Address', 'woocommerce-gateway-pay-crypto-me'),
                'field_placeholder' => 'payments@yourstore.com',
            ),
        );
    }

    /**
     * Get configured networks
     */
    public function get_configured_networks()
    {
        return $this->configured_networks;
    }



    /**
     * Get network-specific configuration
     */
    public function get_network_config($network_type = null)
    {
        $available_networks = $this->get_available_networks();
        // If specific network type requested, return that
        if ($network_type && isset($available_networks[$network_type])) {
            return $available_networks[$network_type];
        }
        // Fallback to mainnet
        return $available_networks['mainnet'];
    }

    public function init_form_fields()
    {
        $available_networks = $this->get_available_networks();
        $network_options = array();
        foreach ($available_networks as $key => $network) {
            $network_options[$key] = $network['name'];
        }

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Enable PayCrypto.Me.', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'text',
                'description' => __('Name of the payment method displayed to the customer.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => __('Pay with Bitcoin', 'woocommerce-gateway-pay-crypto-me'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'textarea',
                'description' => __('Description displayed to the customer at checkout.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'woocommerce-gateway-pay-crypto-me'),
                'desc_tip' => true,
            ),

            'selected_network' => array(
                'title' => __('Network', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'select',
                'options' => $network_options,
                'desc_tip' => __('Select the network for payments.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => 'mainnet',
                'required' => true,
            ),

            'network_identifier' => array(
                'title' => __('Network Identifier', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'text',
                'desc_tip' => __('Enter the required identifier for the selected network (e.g., xpub, Lightning Address).', 'woocommerce-gateway-pay-crypto-me'),
                'default' => '',
                'required' => true,
            ),
            'payment_timeout_hours' => array(
                'title' => __('Payment Timeout (hours)', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'number',
                'description' => __('Maximum time (in hours) for the customer to complete the crypto payment before the order expires.', 'woocommerce-gateway-pay-crypto-me'),
                'custom_attributes' => array('min' => '1', 'step' => '1', 'max' => '72'),
                'default' => '24',
                'desc_tip' => true,

            ),
            'hide_for_non_admin_users' => array(
                'title' => __('Hide for Non-Admin Users', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Show only for administrators.', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('If enabled, only administrators will see the payment method.', 'woocommerce-gateway-pay-crypto-me'),
            ),
            'debug_log' => array(
                'title' => __('Debug', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Enable debugging messages', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'default' => 'yes',
                'description' => __('Debug logs will be saved to WooCommerce > Status > Logs.', 'woocommerce-gateway-pay-crypto-me'),
            ),
            'paycrypto_me_donate' => array(
                'type' => 'title',
                'title' => __('Support the development!', 'woocommerce-gateway-pay-crypto-me'),
                'description' => '<div class="paycrypto-support-box">
                    <img src="' . WC_PayCryptoMe::plugin_url() . '/assets/buy_me_a_coffee.png">
                    <strong>Enjoying the plugin?</strong> Send some BTC to support:
                    <div class="support-divider"></div>
                    <span id="btc-address-admin" class="support-content">' . esc_html($this->support_btc_address) . '</span>
                    <button type="button" id="copy-btc-admin" class="support-btn">Copy</button>
                </div>',
            ),
        );
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        // JavaScript para forçar alinhamento
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            function fixIconAlignment() {
                var img = document.querySelector(".payment_method_paycrypto_me img, li.payment_method_paycrypto_me img");
                if (img) {
                    img.style.verticalAlign = "middle";
                    img.style.marginLeft = "8px";
                    img.style.marginTop = "0";
                    img.style.marginBottom = "0";
                    img.style.maxHeight = "18px";
                    img.style.width = "auto";
                    img.style.display = "inline";
                }
            }
            fixIconAlignment();
            setTimeout(fixIconAlignment, 100);
            setTimeout(fixIconAlignment, 500);
        });
        </script>';
    }

    public function admin_enqueue_scripts()
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] === $this->id) {
            wp_enqueue_style(
                'pay-crypto-me-admin',
                WC_PayCryptoMe::plugin_url() . '/assets/pay-crypto-me-admin.css',
                array(),
                filemtime(WC_PayCryptoMe::plugin_abspath() . 'assets/pay-crypto-me-admin.css')
            );
            wp_enqueue_script(
                'pay-crypto-me-admin',
                WC_PayCryptoMe::plugin_url() . '/assets/pay-crypto-me-admin.js',
                array(),
                filemtime(WC_PayCryptoMe::plugin_abspath() . 'assets/pay-crypto-me-admin.js'),
                true
            );
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

        // Log payment initialization
        \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log("Processing payment for order $order_id");

        // TODO: Implement crypto payment processing logic
        // - Generate wallet address based on selected network
        // - Calculate crypto amount based on exchange rate
        // - Create QR code for payment
        // - Set up payment monitoring/webhook
        // - Handle payment confirmation

        $order->update_status('on-hold', esc_html__('Awaiting crypto payment.', 'woocommerce-gateway-pay-crypto-me'));
        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // Log refund initialization
        \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log("Refund request for order $order_id: $amount");

        // TODO: Implement crypto refund logic
        // - Validate if refund is possible for crypto payments
        // - Calculate crypto refund amount based on current exchange rate
        // - Process refund transaction if supported by network
        // - Update order status and add refund notes
        // - Handle partial vs full refunds

        return false; // Refunds not implemented yet
    }

    /**
     * Carrega CSS no checkout
     */
    public function enqueue_checkout_styles()
    {
        if (is_checkout() || is_wc_endpoint_url('order-pay')) {
            $css_file = WC_PayCryptoMe::plugin_url() . '/assets/checkout-styles.css';
            $css_path = WC_PayCryptoMe::plugin_abspath() . 'assets/checkout-styles.css';

            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'paycrypto-me-checkout',
                    $css_file,
                    array(),
                    filemtime($css_path)
                );
            }
        }
    }
}