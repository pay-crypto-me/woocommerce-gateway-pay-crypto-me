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
    protected $payment_number_confirmations;
    private BitcoinAddressService $bitcoin_address_service;
    private QrCodeService $qr_code_service;
    private $support_btc_address = 'bc1qgvc07956sxuudk3jku6n03q5vc9tkrvkcar7uw';
    private $support_btc_payment_address = 'PM8TJdrkRoSqkCWmJwUMojQCG1rEXsuCTQ4GG7Gub7SSMYxaBx7pngJjhV8GUeXbaJujy8oq5ybpazVpNdotFftDX7f7UceYodNGmffUUiS5NZFu4wq4';

    public function __construct()
    {
        $this->id = 'paycrypto_me';

        $this->has_fields = true;

        $this->supports = ['products', 'pre-orders', 'refunds'];

        $this->icon = WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-icon.png';
        $this->method_title = __('PayCrypto.Me', 'woocommerce-gateway-paycrypto-me');
        $this->method_description = _x('PayCrypto.Me introduces a complete solution to receive your payments through the main cryptocurrencies.', 'Gateway description', 'woocommerce-gateway-paycrypto-me');

        $this->bitcoin_address_service = new BitcoinAddressService();
        $this->qr_code_service = new QrCodeService();

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title') ?: __('Pay with Bitcoin', 'woocommerce-gateway-paycrypto-me');
        $this->description = $this->get_option('description') ?: __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'woocommerce-gateway-paycrypto-me');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users', 'no');
        $this->debug_log = $this->get_option('debug_log', 'yes');
        $this->configured_networks = $this->get_option('configured_networks', array());
        $this->payment_timeout_hours = $this->get_option('payment_timeout_hours', '1');
        $this->payment_number_confirmations = $this->get_option('payment_number_confirmations', '2');

        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_admin_order_details_section'));
        add_action('woocommerce_order_details_before_order_table', array($this, 'render_checkout_order_details_section'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_styles'));

        do_action('woocommerce_paycrypto_me_gateway_loaded', $this);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }

    public function process_admin_options()
    {
        // Validação de nonce para segurança CSRF
        if (isset($_POST['paycrypto_me_nonce'])) {
            if (!wp_verify_nonce($_POST['paycrypto_me_nonce'], 'paycrypto_me_settings')) {
                wp_die(__('Security check failed', 'woocommerce-gateway-paycrypto-me'));
            }
        } else if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'woocommerce-settings')) {
            wp_die(__('Security check failed', 'woocommerce-gateway-paycrypto-me'));
        }

        $selected_network = isset($_POST['woocommerce_paycrypto_me_selected_network']) ? sanitize_text_field($_POST['woocommerce_paycrypto_me_selected_network']) : null;
        $network_identifier = isset($_POST['woocommerce_paycrypto_me_network_identifier']) ? sanitize_text_field($_POST['woocommerce_paycrypto_me_network_identifier']) : '';
        $network_config = $this->get_network_config($selected_network);

        if (empty($network_identifier)) {
            \WC_Admin_Settings::add_error(
                \sprintf(__('Please enter a valid %s.', 'woocommerce-gateway-paycrypto-me'), $network_config['field_label'])
            );
            return false;
        }

        if (!$this->validate_network_identifier($selected_network, $network_identifier)) {
            \WC_Admin_Settings::add_error(
                \sprintf(__('The %s provided is not valid for the selected network.', 'woocommerce-gateway-paycrypto-me'), $network_config['field_label'])
            );
            return false;
        }

        return parent::process_admin_options();
    }

    public function get_available_networks()
    {
        return array(
            'mainnet' => array(
                'name' => __('Bitcoin Mainnet', 'woocommerce-gateway-paycrypto-me'),
                'address_prefix' => array('1', '3', 'bc1'),
                'xpub_prefix' => array('xpub', 'ypub', 'zpub'),
                'testnet' => false,
                'field_type' => 'text',
                'field_label' => __('Wallet address or xPub', 'woocommerce-gateway-paycrypto-me'),
                'field_placeholder' => 'e.g., xpub6, ypub6, zpub6...',
            ),
            'testnet' => array(
                'name' => __('Bitcoin Testnet', 'woocommerce-gateway-paycrypto-me'),
                'address_prefix' => array('m', 'n', '2', 'tb1'),
                'xpub_prefix' => array('tpub', 'upub', 'vpub'),
                'testnet' => true,
                'field_type' => 'text',
                'field_label' => __('Testnet Wallet address or xPub', 'woocommerce-gateway-paycrypto-me'),
                'field_placeholder' => 'e.g., tpub6, upub6, vpub6...',
            ),
            'lightning' => array(
                'name' => __('Lightning Network', 'woocommerce-gateway-paycrypto-me'),
                'address_prefix' => array('lnbc', 'lntb', 'lnbcrt'),
                'xpub_prefix' => array(),
                'testnet' => false,
                'field_type' => 'email',
                'field_label' => __('Lightning Address', 'woocommerce-gateway-paycrypto-me'),
                'field_placeholder' => 'e.g., payments@yourstore.com',
            ),
        );
    }

    public function get_available_cryptocurrencies($network = null)
    {
        return ['BTC']; //@NOTE: all networks using same crypto.
    }

    public function check_cryptocurrency_support($currency, $network = null)
    {
        $normalized_currency = strtoupper($currency);
        $available_cryptos = $this->get_available_cryptocurrencies($network);
        return \in_array($normalized_currency, $available_cryptos, true);
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
                'title' => __('Enable/Disable', 'woocommerce-gateway-paycrypto-me'),
                'label' => __('Enable PayCrypto.Me.', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'text',
                'description' => __('Payment method name displayed on Checkout page.', 'woocommerce-gateway-paycrypto-me'),
                'default' => __('Pay with Bitcoin', 'woocommerce-gateway-paycrypto-me'),
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'textarea',
                'description' => __('Payment method description displayed on Checkout page.', 'woocommerce-gateway-paycrypto-me'),
                'default' => __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'woocommerce-gateway-paycrypto-me'),
            ),

            'selected_network' => array(
                'title' => __('Network', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'select',
                'options' => $network_options,
                'description' => __('Select the network for payments.', 'woocommerce-gateway-paycrypto-me'),
                'default' => 'mainnet',
                'required' => true,
            ),

            'network_identifier' => array(
                'title' => __('Network Identifier', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'text',
                'default' => '',
                'required' => true,
                'description' => __('Tip: It is always preferable to use the wallet xPub rather than a wallet address for Bitcoin payments.', 'woocommerce-gateway-paycrypto-me'),
                'custom_attributes' => array('maxlength' => 255)
            ),
            'payment_timeout_hours' => array(
                'title' => __('Payment Timeout (hours)', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'number',
                'description' => __('Max time (in hours) to wait to confirm payment before the order expires.', 'woocommerce-gateway-paycrypto-me'),
                'custom_attributes' => array('min' => '1', 'step' => '1', 'max' => '72'),
                'default' => '24'
            ),
            'payment_number_confirmations' => array(
                'title' => __('Payment number of confirmations', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'number',
                'description' => __('Tip: To ensure the Bitcoin payment has been made, recommended wait for 3 confirmations.', 'woocommerce-gateway-paycrypto-me'),
                'custom_attributes' => array('min' => '1', 'step' => '1', 'max' => '6'),
                'default' => '3'
            ),
            'hide_for_non_admin_users' => array(
                'title' => __('Hide for Non-Admin Users', 'woocommerce-gateway-paycrypto-me'),
                'label' => __('Show only for administrators.', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('If enabled, only administrators will see the payment method on Checkout page.', 'woocommerce-gateway-paycrypto-me'),
            ),
            'debug_log' => array(
                'title' => __('Debug', 'woocommerce-gateway-paycrypto-me'),
                'label' => __('Enable debugging messages', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'checkbox',
                'default' => 'yes',
                'description' => __('Debug logs will be saved to WooCommerce > Status > Logs.', 'woocommerce-gateway-paycrypto-me'),
            ),
            'paycrypto_me_donate' => array(
                'type' => 'title',
                'title' => __('Support the development!', 'woocommerce-gateway-paycrypto-me'),
                'description' => '<div class="paycrypto-support-box">
                    <div>
                        <img src="' . WC_PayCryptoMe::plugin_url() . '/assets/wallet_address_qrcode.png">
                    </div>
                    <div>
                        <strong>Enjoying the plugin?</strong> Send some BTC to support:
                        <div style="display: flex; align-items: center; margin-top: 8px;">
                            <span id="btc-address-admin" class="support-content">' . esc_html($this->support_btc_address) . '</span>
                            <button type="button" id="copy-btc-admin" class="support-btn">Copy</button>
                        </div>
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

    public function render_admin_order_details_section($order)
    {
        echo '<style>';
        echo '.paycrypto-me-order-details { clear: both }';
        echo '.paycrypto-me-order-details h3 { margin: 0 0 10px 0 !important; padding-top: 10px !important; }';
        echo '</style>';

        $this->render_checkout_order_details_section($order);
    }

    public function render_checkout_order_details_section($order)
    {
        $logo_path = WC_PayCryptoMe::plugin_abspath() . 'assets/paycrypto-me-icon.png';

        $paycrypto_me_payment_address = $order->get_meta('_paycrypto_me_payment_address');
        $paycrypto_me_payment_uri = $order->get_meta('_paycrypto_me_payment_uri');
        $paycrypto_me_fiat_amount = $order->get_meta('_paycrypto_me_fiat_amount');
        $paycrypto_me_crypto_amount = $order->get_meta('_paycrypto_me_crypto_amount');
        $paycrypto_me_fiat_currency = $order->get_meta('_paycrypto_me_fiat_currency');
        $paycrypto_me_payment_expires_at = $order->get_meta('_paycrypto_me_payment_expires_at');
        $paycrypto_me_payment_number_confirmations = $order->get_meta('_paycrypto_me_payment_number_confirmations');
        $paycrypto_me_crypto_network = $order->get_meta('_paycrypto_me_crypto_network');
        $paycrypto_me_crypto_currency = $order->get_meta('_paycrypto_me_crypto_currency');

        $paycrypto_me_payment_qr_code = $this->qr_code_service->generate_qr_code_data_uri($paycrypto_me_payment_uri, $logo_path);

        $paycrypto_me_crypto_network_label = match ($paycrypto_me_crypto_network) {
            'mainnet' => __('On-Chain', 'woocommerce-gateway-paycrypto-me'),
            'testnet' => __('Testnet', 'woocommerce-gateway-paycrypto-me'),
            'lightning' => __('Lightning', 'woocommerce-gateway-paycrypto-me'),
            default => $paycrypto_me_crypto_network,
        };

        if ($paycrypto_me_payment_address) {
            wc_get_template(
                'order-details/paycrypto-me-order-details.php',
                compact(
                    'paycrypto_me_payment_address',
                    'paycrypto_me_payment_qr_code',
                    'paycrypto_me_payment_uri',
                    'paycrypto_me_fiat_amount',
                    'paycrypto_me_crypto_amount',
                    'paycrypto_me_fiat_currency',
                    'paycrypto_me_payment_expires_at',
                    'paycrypto_me_payment_number_confirmations',
                    'paycrypto_me_crypto_network_label',
                    'paycrypto_me_crypto_network',
                    'paycrypto_me_crypto_currency'
                ),
                '',
                WC_PayCryptoMe::plugin_abspath() . 'templates/'
            );
        }
    }

    public function generate_settings_html($form_fields = array(), $echo = true)
    {
        $html = parent::generate_settings_html($form_fields, false);

        $nonce_field = wp_nonce_field('paycrypto_me_settings', 'paycrypto_me_nonce', true, false);
        $html = str_replace('</table>', $nonce_field . '</table>', $html);

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function admin_enqueue_scripts()
    {
        $screen = get_current_screen();

        if ($screen && $screen->id === 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] === $this->id) {
            wp_enqueue_style(
                'paycrypto-me-admin',
                WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-admin.css',
                array(),
                filemtime(WC_PayCryptoMe::plugin_abspath() . 'assets/paycrypto-me-admin.css')
            );
            wp_enqueue_script(
                'paycrypto-me-admin',
                WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-admin.js',
                array(),
                filemtime(WC_PayCryptoMe::plugin_abspath() . 'assets/paycrypto-me-admin.js'),
                true
            );
            wp_localize_script(
                'paycrypto-me-admin',
                'PayCryptoMeAdminData',
                array(
                    'networks' => $this->get_available_networks()
                )
            );
        }

        if ($screen && $screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order') {
            wp_enqueue_style(
                'paycrypto-me-admin-order-details',
                WC_PayCryptoMe::plugin_url() . '/assets/css/frontend/paycrypto-me-order-details.css',
                array(),
                filemtime(WC_PayCryptoMe::plugin_abspath() . 'assets/css/frontend/paycrypto-me-order-details.css')
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

        if (empty($this->get_option('selected_network'))) {
            return false;
        }

        if (empty($this->get_option('network_identifier'))) {
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
        //TODO: Implement refund process
    }

    public function enqueue_checkout_styles()
    {
        if (is_checkout() || is_wc_endpoint_url('order-pay')) {
            $css_file = WC_PayCryptoMe::plugin_url() . '/assets/css/frontend/paycrypto-me-styles.css';
            $css_path = WC_PayCryptoMe::plugin_abspath() . 'assets/css/frontend/paycrypto-me-styles.css';

            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'paycrypto-me-checkout',
                    $css_file,
                    array(),
                    filemtime($css_path)
                );
            }
        }

        if (is_order_received_page() || is_account_page()) {
            $css_file = WC_PayCryptoMe::plugin_url() . '/assets/css/frontend/paycrypto-me-order-details.css';
            $css_path = WC_PayCryptoMe::plugin_abspath() . 'assets/css/frontend/paycrypto-me-order-details.css';

            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'paycrypto-me-order-details',
                    $css_file,
                    array(),
                    filemtime($css_path)
                );
            }
        }
    }

    public function register_paycrypto_me_log($message, $level = 'info')
    {
        if ($this->debug_log === 'yes') {
            \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log($message, $level);
        }
    }

    private function validate_xpub_address($network_type, $identifier)
    {
        $network = $network_type === 'testnet'
            ? NetworkFactory::bitcoinTestnet()
            : NetworkFactory::bitcoin();

        try {
            if ($ok = $this->bitcoin_address_service->validate_extended_pubkey($identifier, $network)) {
                return $ok;
            }

            if ($ok = $this->bitcoin_address_service->validate_bitcoin_address($identifier, $network)) {
                return $ok;
            }
        } catch (\Throwable $th) {
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

        $this->register_paycrypto_me_log(
            \sprintf(__('Network identifier validation failed for %s: `%s`', 'woocommerce-gateway-paycrypto-me'), $network_type, $this->mask_identifier_for_log($network_type, $identifier)),
            'error'
        );

        return false;
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