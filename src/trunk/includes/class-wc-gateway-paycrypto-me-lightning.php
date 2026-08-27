<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       WC_Gateway_Lightning
 * @extends     WC_Payment_Gateway
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class WC_Gateway_PayCryptoMe_Lightning extends Abstract_WC_Gateway_PayCryptoMe
{
    private LightningConnectionTester $connection_tester;
    private ?LightningConfigValidator $config_validator = null;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        $this->id = 'paycrypto_me_lightning';
        $this->method_title = __('Bitcoin Payments', 'paycrypto-me-for-woocommerce') . ' (' . __('Lightning Network', 'paycrypto-me-for-woocommerce') . ')';
        $this->method_description = __('Accept Bitcoin payments self-hosted via Lightning Network', 'paycrypto-me-for-woocommerce') . ' (' . __('Provided by PayCrypto.Me', 'paycrypto-me-for-woocommerce') . ').';
        $this->has_fields = false;

        $this->icon         = WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-lightning-icon.png';
        $this->express_icon = WC_PayCryptoMe::plugin_url() . '/assets/lightning-network-icon.png';
        $this->title = $this->get_option('title') ?: __('Pay with Bitcoin', 'paycrypto-me-for-woocommerce');
        $this->description = $this->get_option('description') ?: __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'paycrypto-me-for-woocommerce');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users', 'no');
        $this->debug_log = $this->get_option('debug_log', 'no');
        $this->payment_timeout_hours = absint($this->get_option('payment_timeout_hours', 24));
        $this->payment_number_confirmations = absint($this->get_option('payment_number_confirmations', 0));
        $this->enable_express_payment = $this->get_option('enable_express_payment', 'yes') === 'yes';
        $this->express_payment_text = $this->get_option('express_payment_text', '') ?: __('Buy with', 'paycrypto-me-for-woocommerce');

        $this->connection_tester = new LightningConnectionTester(new WpHttpClient(), $this);
        $this->config_validator  = new LightningConfigValidator();

        parent::__construct();

        add_filter('woocommerce_generate_node_type_html', [$this, 'generate_node_type_html'], 10, 4);
        add_filter('woocommerce_generate_btcpay_test_button_html', [$this, 'generate_btcpay_test_button_html'], 10, 4);
        add_filter('woocommerce_generate_lnd_test_button_html', [$this, 'generate_lnd_test_button_html'], 10, 4);
        add_action('wp_ajax_paycrypto_test_btcpay_connection', [$this, 'ajax_test_btcpay_connection']);
        add_action('wp_ajax_paycrypto_test_lnd_connection', [$this, 'ajax_test_lnd_connection']);
    }

    public function process_admin_options()
    {
        $this->sync_debug_log_from_post();

        return parent::process_admin_options();
    }

    protected function admin_enqueue_scripts_content($screen)
    {
        if (!$screen) {
            return;
        }

        $is_orders = $screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order';
        $is_settings = strpos($screen->id, 'woocommerce_page_wc-settings') !== false || $screen->id === 'woocommerce_page_wc-settings';

        if ($is_orders) {
            return;
        }

        if ($is_settings) {
            wp_localize_script('paycrypto-me-admin', 'PayCryptoMeLightningData', array(
                'nodeFieldName' => $this->get_field_key('node_type'),
                'btcpayUrlName' => $this->get_field_key('btcpay_url'),
                'btcpayApiName' => $this->get_field_key('btcpay_api_key'),
                'btcpayStoreName' => $this->get_field_key('btcpay_store_id'),
                'btcpayNonce' => wp_create_nonce('paycrypto_btcpay_test'),
                'lndRestUrlName' => $this->get_field_key('lnd_rest_url'),
                'lndMacaroonName' => $this->get_field_key('lnd_macaroon_hex'),
                'lndCertificateName' => $this->get_field_key('lnd_certificate'),
                'lndVerifySslName' => $this->get_field_key('lnd_verify_ssl'),
                'lndNonce' => wp_create_nonce('paycrypto_lnd_test')
            ));
        }
    }

    public function get_available_networks()
    {
        return [];
    }

    public function get_available_cryptocurrencies($network = null)
    {
        return ['BTC']; //@NOTE: all networks using same crypto.
    }

    protected function init_form_fields_items()
    {
        return [
            'proxy_settings' => [
                'title' => __('Proxy Settings', 'paycrypto-me-for-woocommerce'),
                'type' => 'title',
                'description' => __('Configure proxy settings for connecting to your Lightning node.', 'paycrypto-me-for-woocommerce'),
            ],
            'node_type' => [
                'title' => __('Lightning Node Type', 'paycrypto-me-for-woocommerce'),
                'type' => 'node_type',
                'default' => 'btcpay',
            ],
            'invoice_expiry' => [
                'title' => __('Invoice Expiry', 'paycrypto-me-for-woocommerce'),
                'type' => 'number',
                'description' => __('Time before invoice expires. Min: 300 (5 min), Max: 86400 (24h). Default: 3600 (1 hour)', 'paycrypto-me-for-woocommerce'),
                'default' => '3600',
                'placeholder' => '3600',
                'custom_attributes' => [
                    'min' => '300',
                    'max' => '86400',
                    'step' => '1',
                ],
                'desc_tip' => true,
            ],
            'btcpay_url' => [
                'title' => __('BTCPay Server URL', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'description' => __('Full URL to your BTCPay Server (HTTPS required).', 'paycrypto-me-for-woocommerce'),
                'placeholder' => 'https://btcpay.example.com',
                'default' => '',
                'class' => 'paycrypto-btcpay-field',
                'desc_tip' => true,
            ],
            'btcpay_api_key' => [
                'title' => __('BTCPay API Key', 'paycrypto-me-for-woocommerce'),
                'type' => 'password',
                'description' => __('API key for BTCPay (min 20 characters).', 'paycrypto-me-for-woocommerce'),
                'placeholder' => 'sk_live_...',
                'default' => '',
                'class' => 'paycrypto-btcpay-field',
            ],
            'btcpay_store_id' => [
                'title' => __('BTCPay Store ID', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'description' => __('Your BTCPay store identifier.', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-btcpay-field',
            ],
            'btcpay_payment_method_id' => [
                'title' => __('BTCPay Lightning Payment Method ID (advanced)', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'description' => __('Only change this if your BTCPay Server version reports a different Lightning payment method identifier than the default. Leave as "BTC-LN" unless instructed otherwise.', 'paycrypto-me-for-woocommerce'),
                'placeholder' => 'BTC-LN',
                'default' => 'BTC-LN',
                'class' => 'paycrypto-btcpay-field',
                'desc_tip' => true,
            ],
            'btcpay_test_connection' => [
                'title' => __('BTCPay Connection Test', 'paycrypto-me-for-woocommerce'),
                'type' => 'btcpay_test_button',
                'default' => '',
            ],
            'webhook_info' => [
                'title' => __('Webhook Configuration', 'paycrypto-me-for-woocommerce'),
                'type' => 'title',
                'description' => $this->pro_soon_badge() . '<br>' . __('Automatic payment confirmation via webhooks (BTCPay push / lnd polling) ships in the upcoming PayCrypto.Me Pro add-on. In the free version, Lightning payments are confirmed manually — the settings below are a preview and are not editable yet.', 'paycrypto-me-for-woocommerce'),
            ],
            'btcpay_webhook_secret' => [
                'title' => __('BTCPay Webhook Secret', 'paycrypto-me-for-woocommerce'),
                'type' => 'password',
                'description' => __('Reserved for the Pro add-on. Not used by the free version.', 'paycrypto-me-for-woocommerce'),
                'placeholder' => __('Available in the Pro add-on', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-btcpay-field paycrypto-pro-field',
                'custom_attributes' => [
                    'disabled' => 'disabled',
                ],
            ],
            'lnd_rest_url' => [
                'title' => __('lnd REST URL', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'description' => __('REST API endpoint (HTTPS required).', 'paycrypto-me-for-woocommerce'),
                'placeholder' => 'https://localhost:8080',
                'default' => '',
                'class' => 'paycrypto-lnd-field',
                'desc_tip' => true,
            ],
            'lnd_macaroon_hex' => [
                'title' => __('lnd Macaroon (hex)', 'paycrypto-me-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Admin macaroon in hexadecimal format (min 100 characters).', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-lnd-field',
                'css' => 'min-height: 80px; font-family: monospace; font-size: 0.9em;',
            ],
            'lnd_certificate' => [
                'title' => __('SSL Certificate (PEM)', 'paycrypto-me-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('Optional: Paste your SSL certificate in PEM format. If provided, this will be used for verification instead of disabling SSL checks.', 'paycrypto-me-for-woocommerce'),
                'default' => '',
                'class' => 'paycrypto-lnd-field',
                'css' => 'min-height: 120px; font-family: monospace; font-size: 0.85em;',
            ],
            'lnd_verify_ssl' => [
                'title' => __('Verify SSL Certificate', 'paycrypto-me-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable SSL certificate verification', 'paycrypto-me-for-woocommerce'),
                'description' => __('Enable for production. If disabled and no certificate is provided, SSL verification is skipped (development only).', 'paycrypto-me-for-woocommerce'),
                'default' => 'yes',
                'class' => 'paycrypto-lnd-field',
            ],
            'lnd_test_connection' => [
                'title' => __('lnd REST Connection Test', 'paycrypto-me-for-woocommerce'),
                'type' => 'lnd_test_button',
                'default' => '',
            ],
        ];
    }

    /**
     * Generate the custom HTML for the node_type field (renders radios without WP sanitization).
     *
     * @param string $field_html
     * @param string $key
     * @param array  $data
     * @param object $wc_settings
     * @return string
     */
    public function generate_node_type_html(...$args)
    {
        if (count($args) === 2 && is_array($args[1])) {
            $data = $args[1];
        } elseif (count($args) >= 3 && is_array($args[2])) {
            $data = $args[2];
        } else {
            $data = array();
        }

        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'desc_tip' => false));

        $field_key = $this->get_field_key('node_type');
        $value = $this->get_option('node_type', 'btcpay');

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc"><label for="' . esc_attr($field_key) . '">' . esc_html($data['title']) . ' ' . $this->get_tooltip_html($data) . '</label></th>';
        $html .= '<td class="forminp"><fieldset style="display:flex; align-items:center; gap:12px;"><legend class="screen-reader-text"><span>' . esc_html($data['title']) . '</span></legend>';
        $html .= '<label><input type="radio" name="' . esc_attr($field_key) . '" value="btcpay" ' . checked($value, 'btcpay', false) . '> BTCPay Server</label> ';
        $html .= '<label><input type="radio" name="' . esc_attr($field_key) . '" value="lnd_rest" ' . checked($value, 'lnd_rest', false) . '> lnd REST</label>';
        if (!empty($data['description'])) {
            $html .= '<p class="description">' . wp_kses_post($data['description']) . '</p>';
        }
        $html .= '</fieldset></td></tr>';

        return $html;
    }

    /**
     * Generate the custom HTML for the btcpay_test_button field.
     *
     * @param string $field_html
     * @param string $key
     * @param array  $data
     * @param object $wc_settings
     * @return string
     */
    public function generate_btcpay_test_button_html(...$args)
    {
        if (count($args) === 2 && is_array($args[1])) {
            $data = $args[1];
        } elseif (count($args) >= 3 && is_array($args[2])) {
            $data = $args[2];
        } else {
            $data = array();
        }

        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'desc_tip' => false));

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc">';
        $html .= '<button id="paycrypto-btcpay-test" type="button" class="button paycrypto-btcpay-field">' . esc_html__('🔌 Test connection', 'paycrypto-me-for-woocommerce') . '</button> ';
        $html .= '</th>';
        $html .= '<td class="forminp">';
        $html .= '<span id="paycrypto-btcpay-test-result"></span>';
        if (!empty($data['description'])) {
            $html .= '<p class="description">' . wp_kses_post($data['description']) . '</p>';
        }
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    /**
     * Generate the custom HTML for the lnd_test_button field.
     *
     * @param string $field_html
     * @param string $key
     * @param array  $data
     * @param object $wc_settings
     * @return string
     */
    public function generate_lnd_test_button_html(...$args)
    {
        if (count($args) === 2 && is_array($args[1])) {
            $data = $args[1];
        } elseif (count($args) >= 3 && is_array($args[2])) {
            $data = $args[2];
        } else {
            $data = array();
        }

        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'desc_tip' => false));

        $html = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc">';
        $html .= '<button id="paycrypto-lnd-test" type="button" class="button paycrypto-lnd-field">' . esc_html__('🔌 Test connection', 'paycrypto-me-for-woocommerce') . '</button> ';
        $html .= '</th>';
        $html .= '<td class="forminp">';
        $html .= '<span id="paycrypto-lnd-test-result"></span>';
        if (!empty($data['description'])) {
            $html .= '<p class="description">' . wp_kses_post($data['description']) . '</p>';
        }
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    public function ajax_test_btcpay_connection()
    {
        $this->connection_tester->test_btcpay_connection();
    }

    public function ajax_test_lnd_connection()
    {
        $this->connection_tester->test_lnd_connection();
    }

    public function build_order_display_args(\WC_Order $order): ?array
    {
        $payment_request = $order->get_meta('_paycrypto_me_payment_request');

        if (!$payment_request || !OrderGatewayMatcher::matches($order, $this->id)) {
            return null;
        }

        return [
            'payment_identifier'     => $payment_request,
            'payment_uri'            => $order->get_meta('_paycrypto_me_payment_uri'),
            'logo_path'              => WC_PayCryptoMe::plugin_abspath() . 'assets/lightning-network-icon.png',
            'qr_logo_options'        => [
                'border' => [
                    'shape'      => 'circle',
                    'width'      => 4,
                    'color'      => '#FFFFFF',
                    'background' => '#FFFFFF',
                    'size'       => 48,
                ],
            ],
            'crypto_network'         => 'lightning',
            'network_label'          => __('Lightning Network', 'paycrypto-me-for-woocommerce'),
            'crypto_amount'          => null,
            'crypto_currency'        => 'BTC',
            'confirmations_required' => 0,
            // Lightning invoice expiry is enforced by the node (BOLT11), so the countdown is real.
            'show_expiry'            => true,
        ];
    }

    protected function unavailability_reasons(): array
    {
        $configuration = array();
        $node_type = $this->get_option('node_type', 'btcpay');

        // Named per field: saving lnd_rest with only the BTCPay fields filled used to report
        // "settings saved" and then drop the gateway from checkout with no explanation at all.
        $required = $node_type === 'lnd_rest'
            ? array(
                'lnd_rest_url'     => __('lnd REST URL', 'paycrypto-me-for-woocommerce'),
                'lnd_macaroon_hex' => __('lnd Macaroon (hex)', 'paycrypto-me-for-woocommerce'),
            )
            : array(
                'btcpay_url'      => __('BTCPay Server URL', 'paycrypto-me-for-woocommerce'),
                'btcpay_api_key'  => __('BTCPay API Key', 'paycrypto-me-for-woocommerce'),
                'btcpay_store_id' => __('BTCPay Store ID', 'paycrypto-me-for-woocommerce'),
            );

        foreach ($required as $option => $label) {
            if (empty($this->get_option($option))) {
                $configuration[] = sprintf(
                    '%1$s is empty, and it is required for the selected node type (%2$s).',
                    esc_html($label),
                    esc_html($node_type === 'lnd_rest' ? 'lnd REST' : 'BTCPay Server')
                );
            }
        }

        return array('environment' => array(), 'configuration' => $configuration);
    }

    private function config_validator(): LightningConfigValidator
    {
        if ($this->config_validator === null) {
            $this->config_validator = new LightningConfigValidator();
        }

        return $this->config_validator;
    }

    // Kept on the gateway (not the validator): reads the gateway's submitted form data via
    // WC_Settings_API. Delegates the node_type decision to the validator to avoid duplication.
    private function _is_lnd_rest_selected()
    {
        return $this->config_validator()->is_lnd_rest_selected($this->get_post_data(), $this->get_field_key('node_type'));
    }

    // The 9 validate_*_field() stubs stay on the gateway: WC_Settings_API discovers them via
    // method_exists($this, 'validate_<key>_field') on the gateway instance. Real logic lives in
    // LightningConfigValidator; "is lnd_rest selected" is resolved here (from POST) and passed in.
    public function validate_btcpay_url_field($key, $value)
    {
        return $this->config_validator()->validate_btcpay_url($value, $this->_is_lnd_rest_selected());
    }

    public function validate_btcpay_api_key_field($key, $value)
    {
        return $this->config_validator()->validate_btcpay_api_key($value, $this->_is_lnd_rest_selected());
    }

    public function validate_btcpay_store_id_field($key, $value)
    {
        return $this->config_validator()->validate_btcpay_store_id($value);
    }

    public function validate_btcpay_payment_method_id_field($key, $value)
    {
        return $this->config_validator()->validate_btcpay_payment_method_id($value);
    }

    public function validate_btcpay_webhook_secret_field($key, $value)
    {
        return $this->config_validator()->validate_btcpay_webhook_secret($value, $this->_is_lnd_rest_selected());
    }

    public function validate_lnd_rest_url_field($key, $value)
    {
        return $this->config_validator()->validate_lnd_rest_url($value, $this->_is_lnd_rest_selected());
    }

    public function validate_lnd_macaroon_hex_field($key, $value)
    {
        return $this->config_validator()->validate_lnd_macaroon_hex($value, $this->_is_lnd_rest_selected());
    }

    public function validate_lnd_certificate_field($key, $value)
    {
        return $this->config_validator()->validate_lnd_certificate($value, $this->_is_lnd_rest_selected());
    }

    public function validate_invoice_expiry_field($key, $value)
    {
        return $this->config_validator()->validate_invoice_expiry($value);
    }
}