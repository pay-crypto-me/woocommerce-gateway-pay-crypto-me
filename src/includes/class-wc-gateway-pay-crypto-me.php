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

\defined('ABSPATH') || exit;

use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Network\NetworkFactory;

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

        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_styles'));

        do_action('paycrypto_me_gateway_loaded', $this);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }

    public function process_admin_options()
    {
        $selected_network = isset($_POST['woocommerce_paycrypto_me_selected_network']) ? sanitize_text_field($_POST['woocommerce_paycrypto_me_selected_network']) : null;
        $network_identifier = isset($_POST['woocommerce_paycrypto_me_network_identifier']) ? sanitize_text_field($_POST['woocommerce_paycrypto_me_network_identifier']) : '';
        $network_config = $this->get_network_config($selected_network);

        if (empty($network_identifier)) {
            \WC_Admin_Settings::add_error(\sprintf(__('Please enter a valid %s.', 'woocommerce-gateway-pay-crypto-me'), $network_config['field_label']), 'error');
            return false;
        }

        if (!$this->validate_network_identifier($selected_network, $network_identifier)) {
            \WC_Admin_Settings::add_error(\sprintf(__('The %s provided is not valid for the selected network.', 'woocommerce-gateway-pay-crypto-me'), $network_config['field_label']), 'error');
            return false;
        }

        return parent::process_admin_options();
    }

    public function get_available_networks()
    {
        return array(
            'mainnet' => array(
                'name' => __('Bitcoin Mainnet', 'woocommerce-gateway-pay-crypto-me'),
                'address_prefix' => array('1', '3', 'bc1'),
                'xpub_prefix' => array('xpub', 'ypub', 'zpub'),
                'testnet' => false,
                'field_type' => 'text',
                'field_label' => __('Wallet address or xPub', 'woocommerce-gateway-pay-crypto-me'),
                'field_placeholder' => 'e.g., xpub6, ypub6, zpub6...',
            ),
            'testnet' => array(
                'name' => __('Bitcoin Testnet', 'woocommerce-gateway-pay-crypto-me'),
                'address_prefix' => array('m', 'n', '2', 'tb1'),
                'xpub_prefix' => array('tpub', 'upub', 'vpub'),
                'testnet' => true,
                'field_type' => 'text',
                'field_label' => __('Testnet Wallet address or xPub', 'woocommerce-gateway-pay-crypto-me'),
                'field_placeholder' => 'e.g., tpub6, upub6, vpub6...',
            ),
            'lightning' => array(
                'name' => __('Lightning Network', 'woocommerce-gateway-pay-crypto-me'),
                'address_prefix' => array('lnbc', 'lntb', 'lnbcrt'),
                'xpub_prefix' => array(),
                'testnet' => false,
                'field_type' => 'email',
                'field_label' => __('Lightning Address', 'woocommerce-gateway-pay-crypto-me'),
                'field_placeholder' => 'e.g., payments@yourstore.com',
            ),
        );
    }

    public function get_available_cryptocurrencies($network = null) {
        return ['BTC']; //@NOTE: all networks using same crypto.
    }

    public function check_cryptocurrency_support($currency, $network = null)
    {
        $available_cryptos = $this->get_available_cryptocurrencies($network);
        $normalized_currency = strtoupper($currency);

        return \in_array($normalized_currency, array_keys($available_cryptos), true);
    }

    public function get_configured_networks()
    {
        return $this->configured_networks;
    }

    public function get_network_config($network_type = null)
    {
        $available_networks = $this->get_available_networks();
        if ($network_type && isset($available_networks[$network_type])) {
            return $available_networks[$network_type];
        }

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
                'description' => __('Payment method name displayed on Checkout page.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => __('Pay with Bitcoin', 'woocommerce-gateway-pay-crypto-me'),
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'textarea',
                'description' => __('Payment method description displayed on Checkout page.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'woocommerce-gateway-pay-crypto-me'),
            ),

            'selected_network' => array(
                'title' => __('Network', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'select',
                'options' => $network_options,
                'description' => __('Select the network for payments.', 'woocommerce-gateway-pay-crypto-me'),
                'default' => 'mainnet',
                'required' => true,
            ),

            'network_identifier' => array(
                'title' => __('Network Identifier', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'text',
                'default' => '',
                'required' => true,
                'description' => __('Tip: It is always preferable to use the wallet xPub rather than a wallet address for Bitcoin payments.', 'woocommerce-gateway-pay-crypto-me'),
                'custom_attributes' => array('maxlength' => 255),
            ),
            'payment_timeout_hours' => array(
                'title' => __('Payment Timeout (hours)', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'number',
                'description' => __('Max time (in hours) to wait to confirm payment before the order expires.', 'woocommerce-gateway-pay-crypto-me'),
                'custom_attributes' => array('min' => '1', 'step' => '1', 'max' => '72'),
                'default' => '24'
            ),
            'hide_for_non_admin_users' => array(
                'title' => __('Hide for Non-Admin Users', 'woocommerce-gateway-pay-crypto-me'),
                'label' => __('Show only for administrators.', 'woocommerce-gateway-pay-crypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('If enabled, only administrators will see the payment method on Checkout page.', 'woocommerce-gateway-pay-crypto-me'),
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
                    <div>
                        <img src="' . WC_PayCryptoMe::plugin_url() . '/assets/wallet_address_qrcode.png">
                    </div>
                    <div>
                        <strong>Enjoying the plugin?</strong> Send some BTC to support:
                        <div class="support-divider"></div>
                        <span id="btc-address-admin" class="support-content">' . esc_html($this->support_btc_address) . '</span>
                        <button type="button" id="copy-btc-admin" class="support-btn">Copy</button>
                    </div>
                </div>',
            ),
        );
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

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
            wp_localize_script(
                'pay-crypto-me-admin',
                'PayCryptoMeAdminData',
                array(
                    'networks' => $this->get_available_networks()
                )
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
        return PaymentProcessor::instance()->process_payment($order->get_id(), $this);
    }

    public function process_payment($order_id)
    {
        return PaymentProcessor::instance()->process_payment($order_id, $this);
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        return PaymentProcessor::instance()->process_refund($order_id, $amount, $reason, $this);
    }

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

    private function validate_xpub_address($network_type, $identifier)
    {
        try {
            $network = $network_type === 'testnet'
                ? NetworkFactory::bitcoinTestnet()
                : NetworkFactory::bitcoin();

            $keyFactory = new HierarchicalKeyFactory();
            $keyFactory->fromExtended($identifier, $network);
            return true;
        } catch (\Exception $e) {
            // Not a valid xpub, continue to address validation
        }

        try {
            $network = $network_type === 'testnet'
                ? NetworkFactory::bitcoinTestnet()
                : NetworkFactory::bitcoin();

            $addressCreator = new AddressCreator();
            $addressCreator->fromString($identifier, $network);
            return true;
        } catch (\Exception $e) {
            // Not a valid address
        }

        return false;
    }

    private function validate_network_identifier($network_type, $identifier)
    {
        if ($ok = $network_type === 'lightning' && is_email($identifier)) {
            return $ok;
        } else if ($ok = $network_type !== 'lightning' && $this->validate_xpub_address($network_type, $identifier)) {
            return $ok;
        }

        $this->register_paycrypto_me_log(\sprintf(__('Network identifier validation failed for %s: `%s`', 'woocommerce-gateway-pay-crypto-me'), $network_type, $this->mask_identifier_for_log($network_type, $identifier)), 'error');

        return false;
    }

    private function register_paycrypto_me_log(...$rest)
    {
        if ($this->debug_log === 'yes') {
            \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log(...$rest);
        }
    }

    private function mask_identifier_for_log($network_type, $identifier)
    {
        if ($network_type === 'lightning') {
            $parts = explode('@', $identifier);
            if (\count($parts) === 2) {
                return $parts[0] . '@' . substr($parts[1], 0, 1) . (strpos($parts[1], '.') !== false ?
                    '***.' . substr(strrchr($parts[1], '.'), 1) :
                    '***');
            }
        } else {
            if (\strlen($identifier) > 10) {
                return substr($identifier, 0, 6) . '...' . substr($identifier, -4);
            }
        }
        return $identifier;
    }
}