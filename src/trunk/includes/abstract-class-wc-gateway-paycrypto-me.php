<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       Abstract_WC_Gateway_PayCryptoMe
 * @extends     WC_Payment_Gateway
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

abstract class Abstract_WC_Gateway_PayCryptoMe extends \WC_Payment_Gateway
{
    protected $hide_for_non_admin_users;
    protected $configured_networks;
    protected $debug_log;
    protected $payment_timeout_hours;
    protected $payment_number_confirmations;
    protected $enable_express_payment;
    protected $express_payment_text;
    protected $show_express_icon;
    protected $express_icon_position;
    protected $express_icon;
    protected $support_btc_address = 'bc1qgvc07956sxuudk3jku6n03q5vc9tkrvkcar7uw';
    protected PaymentDisplayDataBuilder $display_data_builder;

    public function __construct()
    {
        $this->display_data_builder = new PaymentDisplayDataBuilder(new QrCodeService());

        $this->has_fields = true;

        $this->supports = ['products', 'pre-orders'];

        $this->init_form_fields();
        $this->init_settings();

        $this->show_express_icon     = $this->get_option('show_express_icon', 'yes') === 'yes';
        $this->express_icon_position = $this->get_option('express_icon_position', 'left');

        add_filter('woocommerce_generate_icon_position_html', [$this, 'generate_icon_position_html'], 10, 4);

        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_admin_order_details_section'));
        add_action('woocommerce_order_details_before_order_table', array($this, 'render_checkout_order_details_section'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_styles'));

        do_action('paycryptome_for_woocommerce_gateway_loaded', $this);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));

        add_action('wp_ajax_paycryptome_reset_derivation_index', array($this, 'ajax_reset_derivation_index'));
    }

    abstract protected function admin_enqueue_scripts_content(WP_Screen|null $screen);
    abstract public function get_available_networks();
    abstract public function get_available_cryptocurrencies($network = null);
    abstract protected function init_form_fields_items();

    /**
     * Gateway-specific values for the order-details display.
     *
     * Returns null when the order has no payment for this gateway (guard),
     * otherwise the variable inputs consumed by PaymentDisplayDataBuilder::build().
     */
    abstract public function build_order_display_args(\WC_Order $order): ?array;

    public function render_admin_order_details_section($order)
    {
        $this->render_checkout_order_details_section($order);
    }

    public function render_checkout_order_details_section($order)
    {
        $args = $this->build_order_display_args($order);

        if ($args === null) {
            return;
        }

        // Third-party seam (pre-build): lets an add-on flip show_expiry, set crypto_amount, etc.
        // before PaymentDisplayDataBuilder computes the final display array.
        $args = apply_filters('paycryptome_order_display_args', $args, $order, $this);

        // Third-party seam (post-build): lets an add-on adjust already-computed fields (QR, labels).
        $payment_display_data = apply_filters(
            'paycryptome_order_display_data',
            $this->display_data_builder->build($order, $args),
            $order,
            $this
        );

        // Enqueued here (not enqueue_checkout_styles, which only runs on wp_enqueue_scripts) because
        // this section renders on both the frontend order-received page and the admin order-edit screen.
        $js_path = WC_PayCryptoMe::plugin_abspath() . 'assets/js/paycrypto-me-order-details.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'paycrypto-me-order-details',
                WC_PayCryptoMe::plugin_url() . '/assets/js/paycrypto-me-order-details.js',
                array(),
                filemtime($js_path),
                true
            );
        }

        wc_get_template(
            'order-details/paycrypto-me-order-details.php',
            compact('payment_display_data'),
            '',
            WC_PayCryptoMe::plugin_abspath() . 'templates/'
        );
    }

    public function admin_enqueue_scripts()
    {
        $screen = get_current_screen();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings-tab check for asset enqueue; no state change.
        $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';

        if ($screen && $screen->id === 'woocommerce_page_wc-settings' && strpos($section, 'paycrypto_me') === 0) {
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
                    'networks' => $this->get_available_networks(),
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('paycrypto_me_nonce'),
                )
            );
        }

        // Shared by both gateways (unlike admin_enqueue_scripts_content, which each gateway overrides
        // independently) so the order-details admin styling applies regardless of which gateway paid
        // the order. Enqueued here, not from render_admin_order_details_section(), because that hook
        // fires mid-page (after admin_print_styles already ran) and would be too late to print.
        if ($screen && ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order')) {
            $css_path = WC_PayCryptoMe::plugin_abspath() . 'assets/css/admin/paycrypto-me-order-details-admin.css';
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'paycrypto-me-order-details-admin',
                    WC_PayCryptoMe::plugin_url() . '/assets/css/admin/paycrypto-me-order-details-admin.css',
                    array(),
                    filemtime($css_path)
                );
            }
        }

        $this->admin_enqueue_scripts_content($screen);
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
        $network_options = array();
        $available_networks = $this->get_available_networks();

        foreach ($available_networks as $key => $network) {
            $network_options[$key] = $network['name'];
        }

        $selected_network_item = !$network_options ? [] : [
            'selected_network' => array(
                'title' => __('Network', 'paycrypto-me-for-woocommerce'),
                'type' => 'select',
                'options' => $network_options,
                'description' => __('Select the network for payments.', 'paycrypto-me-for-woocommerce'),
                'default' => 'mainnet',
                'required' => true,
            )
        ];

        $this->form_fields = array_merge(
            [
                'enabled' => array(
                    'title' => __('Enable Method', 'paycrypto-me-for-woocommerce'),
                    'label' => __('Enable', 'paycrypto-me-for-woocommerce') . ' ' . $this->method_title,
                    'type' => 'checkbox',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => __('Title', 'paycrypto-me-for-woocommerce'),
                    'type' => 'text',
                    'description' => __('Payment method name displayed on Checkout page.', 'paycrypto-me-for-woocommerce'),
                    'default' => __('Pay with Bitcoin', 'paycrypto-me-for-woocommerce'),
                ),
                'description' => array(
                    'title' => __('Description', 'paycrypto-me-for-woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Payment method description displayed on Checkout page.', 'paycrypto-me-for-woocommerce'),
                    'default' => __('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'paycrypto-me-for-woocommerce'),
                ),
                'express_payment_section' => array(
                    'type' => 'title',
                    'title' => __('Express Payment (One-Click)', 'paycrypto-me-for-woocommerce'),
                    'description' => __('Enable Express Payment to pay with one click. When enabled, customers are redirected straight to the payment QR code.', 'paycrypto-me-for-woocommerce'),
                ),
                'enable_express_payment' => array(
                    'label' => __('Enable Express Payment', 'paycrypto-me-for-woocommerce'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ),
                'express_payment_text' => array(
                    'type' => 'text',
                    'title' => __('Express Button Label', 'paycrypto-me-for-woocommerce'),
                    'placeholder' => __('Buy with', 'paycrypto-me-for-woocommerce'),
                    'description' => __('Text displayed on the Express Payment button. If empty, the default label will be used \'Buy with\'', 'paycrypto-me-for-woocommerce'),
                    'default' => '',
                    'custom_attributes' => array(
                        'data-express_payment-text' => '',
                    ),
                ),
                'show_express_icon' => array(
                    'title' => __('Express Button Icon', 'paycrypto-me-for-woocommerce'),
                    'label' => __('Show icon on Express Payment button', 'paycrypto-me-for-woocommerce'),
                    'type' => 'checkbox',
                    'default' => 'yes',
                    'description' => __('When enabled, the payment network icon is displayed alongside the button label.', 'paycrypto-me-for-woocommerce'),
                ),
                'express_icon_position' => array(
                    'title' => __('Icon Position', 'paycrypto-me-for-woocommerce'),
                    'type' => 'icon_position',
                    'default' => 'left',
                    'description' => __('Controls where the icon appears relative to the button label. e.g. "₿ Pay with" (left) or "Pay with ₿" (right).', 'paycrypto-me-for-woocommerce'),
                    'options' => array(
                        'left'  => __('Left — icon before label', 'paycrypto-me-for-woocommerce'),
                        'right' => __('Right — icon after label', 'paycrypto-me-for-woocommerce'),
                    ),
                ),
            ],
            $selected_network_item,
            $this->init_form_fields_items(),
            [
                'hide_for_non_admin_users' => array(
                    'title' => __('Hide for Non-Admin Users', 'paycrypto-me-for-woocommerce'),
                    'label' => __('Show only for administrators.', 'paycrypto-me-for-woocommerce'),
                    'type' => 'checkbox',
                    'default' => 'no',
                    'description' => __('If enabled, only administrators will see the payment method on Checkout page.', 'paycrypto-me-for-woocommerce'),
                ),
                'debug_log' => array(
                    'title' => __('Debug', 'paycrypto-me-for-woocommerce'),
                    'label' => __('Enable debugging messages', 'paycrypto-me-for-woocommerce'),
                    'type' => 'checkbox',
                    'default' => 'no',
                    'description' => __('Debug logs will be saved to WooCommerce > Status > Logs.', 'paycrypto-me-for-woocommerce'),
                ),
                'paycrypto_me_donate' => array(
                    'type' => 'title',
                    'title' => __('Support the development!', 'paycrypto-me-for-woocommerce'),
                    'description' => '<div class="paycrypto-support-box">
                    <div>
                        <img src="' . WC_PayCryptoMe::plugin_url() . '/assets/wallet_address_qrcode.png">
                    </div>
                    <div>
                        ' . __('<strong>Enjoying the plugin?</strong> Send some BTC to support:', 'paycrypto-me-for-woocommerce') . '
                        <div style="display: flex; align-items: center; margin-top: 8px;">
                            <span id="btc-address-admin" class="support-content">' . esc_html($this->support_btc_address) . '</span>
                            <button type="button" id="copy-btc-admin" class="support-btn">' . esc_html__('Copy', 'paycrypto-me-for-woocommerce') . '</button>
                        </div>
                    </div>
                </div>',
                ),
            ]
        );
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
        return (new PaymentProcessor())->process_payment($order->get_id(), $this);
    }

    public function process_payment($order_id)
    {
        return (new PaymentProcessor())->process_payment($order_id, $this);
    }

    public function enqueue_checkout_styles()
    {
        if (is_checkout() || is_wc_endpoint_url('order-pay')) {
            // Enqueue block styles only on checkout to avoid layout break elsewhere.
            $block_css = WC_PayCryptoMe::plugin_abspath() . 'assets/blocks/paycrypto_me-blocks.css';
            if (file_exists($block_css)) {
                wp_enqueue_style(
                    'paycrypto_me-blocks-style',
                    WC_PayCryptoMe::plugin_url() . '/assets/blocks/paycrypto_me-blocks.css',
                    array(),
                    filemtime($block_css)
                );
            }
            $lightning_css = WC_PayCryptoMe::plugin_abspath() . 'assets/blocks/paycrypto_me_lightning-blocks.css';
            if (file_exists($lightning_css)) {
                wp_enqueue_style(
                    'paycrypto_me_lightning-blocks-style',
                    WC_PayCryptoMe::plugin_url() . '/assets/blocks/paycrypto_me_lightning-blocks.css',
                    array(),
                    filemtime($lightning_css)
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
            $safe_message = wp_strip_all_tags((string) $message);
            \PayCryptoMe\WooCommerce\WC_PayCryptoMe::log($safe_message, $level);
        }
    }

    /**
     * During a settings save, $this->debug_log still holds the value persisted
     * before this request, so logs emitted while saving (validation notices, the
     * validate_*_field callbacks) would honor the old value. Re-sync it from the
     * submitted checkbox so they reflect the value being saved right now.
     *
     * Only ever called from process_admin_options(), which runs after the nonce
     * has already been verified — hence the safe direct $_POST read.
     */
    protected function sync_debug_log_from_post()
    {
        $field_key = $this->get_field_key('debug_log');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller verifies the nonce before dispatching here.
        $this->debug_log = isset($_POST[$field_key]) ? 'yes' : 'no';
    }

    public function generate_icon_position_html(...$args)
    {
        if (count($args) === 2 && is_array($args[1])) {
            $data = $args[1];
        } elseif (count($args) >= 3 && is_array($args[2])) {
            $data = $args[2];
        } else {
            $data = array();
        }

        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'options' => array(), 'default' => 'left'));

        $field_key = $this->get_field_key('express_icon_position');
        $value     = $this->get_option('express_icon_position', $data['default']);

        $html  = '<tr valign="top">';
        $html .= '<th scope="row" class="titledesc"><label>' . esc_html($data['title']) . '</label></th>';
        $html .= '<td class="forminp"><fieldset style="display:flex; align-items:center; gap:12px;"><legend class="screen-reader-text"><span>' . esc_html($data['title']) . '</span></legend>';

        foreach ($data['options'] as $option_value => $option_label) {
            $html .= '<label><input type="radio" name="' . esc_attr($field_key) . '" value="' . esc_attr($option_value) . '" ' . checked($value, $option_value, false) . '> ' . esc_html($option_label) . '</label> ';
        }

        $html .= '</fieldset>';

        if (!empty($data['description'])) {
            $html .= '<p class="description">' . wp_kses_post($data['description']) . '</p>';
        }

        $html .= '</td></tr>';

        return $html;
    }

    // Renders the "Premium · Coming soon" badge for settings fields whose behavior belongs to
    // the Premium add-on (async status updates: Lightning webhooks, on-chain confirmation
    // tracking). The associated input is disabled so the free version never acts on the value.
    protected function premium_soon_badge(): string
    {
        return '<span class="paycrypto-premium-badge">'
            . esc_html__('Premium · Coming soon', 'paycrypto-me-for-woocommerce')
            . '</span>';
    }

    public function get_payment_method_data()
    {
        return [
            'icon'                 => $this->icon ?? '',
            'express_icon'         => $this->express_icon ?? '',
            'show_express_icon'    => $this->show_express_icon ?? true,
            'express_icon_position' => $this->express_icon_position ?? 'left',
            'gateway_id'          => $this->id ?? '',
            'debug_log'           => $this->debug_log ?? 'no',
            'title'               => $this->title ?: 'PayCrypto.Me',
            'description'         => $this->description ?? '',
            'supports'            => $this->supports ?? ['products'],
            'express_payment_text' => $this->express_payment_text ?? '',
            'enable_express_payment' => $this->enable_express_payment ?? false,
            'crypto_currency'     => $this->get_available_cryptocurrencies()[0] ?? '',
        ];
    }
}