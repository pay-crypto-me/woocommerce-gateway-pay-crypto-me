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

use BitWasp\Bitcoin\Network\NetworkFactory;

class WC_Gateway_PayCryptoMe extends Abstract_WC_Gateway_PayCryptoMe
{
    protected $hide_for_non_admin_users;
    protected $configured_networks;
    protected $debug_log;
    protected $payment_timeout_hours;
    protected $payment_number_confirmations;
    private ?BitcoinAddressService $bitcoin_address_service = null;
    private ?PayCryptoMeDBStatementsService $db_statements_service = null;

    public function __construct()
    {
        $this->id = 'paycrypto_me';

        $this->icon         = WC_PayCryptoMe::plugin_url() . '/assets/paycrypto-me-icon.png';
        $this->express_icon = WC_PayCryptoMe::plugin_url() . '/assets/bitcoin-icon.png';
        $this->method_title = __('Bitcoin Payments', 'paycrypto-me-for-woocommerce') . ' (' . __('On-Chain', 'paycrypto-me-for-woocommerce') . ')';
        $this->method_description = __('Accept Bitcoin payments Non-custodial via On-Chain', 'paycrypto-me-for-woocommerce') . ' (' . __('Provided by PayCrypto.Me', 'paycrypto-me-for-woocommerce') . ').';

        $this->title = $this->get_option('title') ?: __('Pay with Bitcoin', 'paycrypto-me-for-woocommerce');
        $this->description = $this->get_option('description') ?: __('Use directly your Bitcoin wallet to pay. Place the order to view the QR code and payment instructions.', 'paycrypto-me-for-woocommerce');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users', 'no');
        $this->debug_log = $this->get_option('debug_log', 'no');
        $this->configured_networks = $this->get_option('configured_networks', array());
        $this->payment_timeout_hours = absint($this->get_option('payment_timeout_hours', 1));
        $this->payment_number_confirmations = absint($this->get_option('payment_number_confirmations', 2));
        $this->enable_express_payment = $this->get_option('enable_express_payment', 'yes') === 'yes';
        $this->express_payment_text = $this->get_option('express_payment_text', '') ?: __('Buy with', 'paycrypto-me-for-woocommerce');

        // Deliberately NOT instantiated here: WooCommerce constructs every registered gateway on
        // every request (frontend, admin, cron, REST, AJAX), but these services are only needed
        // when saving settings or resetting the derivation index. See the lazy accessors below.

        parent::__construct();

        // Registered here, not in the abstract constructor: ajax_reset_derivation_index() only
        // exists on this (On-Chain) gateway — the Lightning gateway has no derivation index to
        // reset, so registering it on the abstract class made Lightning register a callback
        // pointing at a method that doesn't exist there.
        add_action('wp_ajax_paycryptome_reset_derivation_index', array($this, 'ajax_reset_derivation_index'));
    }

    protected function unavailability_reasons(): array
    {
        $environment = array();
        $configuration = array();

        $missing = EnvironmentRequirements::missing_onchain_extensions();

        // Reported only when the configured identifier actually needs that math. A fixed bech32
        // address needs none of it (bech32 is pure PHP), so such a store keeps taking on-chain
        // payments on a host without GMP — only xPub derivation is impossible there.
        if (!empty($missing) && $this->configured_identifier_requires_gmp()) {
            $environment[] = sprintf(
                'This server is missing the PHP %1$s extension, which is required to derive addresses from an xPub. Ask your host to enable it, or configure a single fixed address starting with %2$s instead.',
                esc_html(EnvironmentRequirements::describe($missing)),
                esc_html($this->segwit_prefix($this->get_option('selected_network', 'mainnet')))
            );
        }

        if (empty($this->get_option('selected_network'))) {
            $configuration[] = 'No network is selected in the gateway settings.';
        }

        if (empty($this->get_option('network_identifier'))) {
            $configuration[] = 'No wallet xPub or Bitcoin address is configured in the gateway settings.';
        }

        return array('environment' => $environment, 'configuration' => $configuration);
    }

    /**
     * An unset identifier counts as requiring it: the merchant hasn't opted into the
     * fixed-address route yet, so the limitation is still worth reporting.
     */
    private function configured_identifier_requires_gmp(): bool
    {
        $network_type = (string) $this->get_option('selected_network', 'mainnet');

        return $this->get_bitcoin_address_service()->requires_gmp_math(
            (string) $this->get_option('network_identifier'),
            $this->network_for($network_type)
        );
    }

    private function network_for(?string $network_type): \BitWasp\Bitcoin\Network\NetworkInterface
    {
        return $network_type === 'testnet' ? NetworkFactory::bitcoinTestnet() : NetworkFactory::bitcoin();
    }

    private function segwit_prefix(?string $network_type): string
    {
        return $this->network_for($network_type)->getSegwitBech32Prefix() . '1';
    }

    /**
     * render_missing_extension_notice() below covers the environment bucket on this screen, in a
     * longer form that can point at "the field below" — so render_unavailability_notice() leaves
     * it out and only lists the configuration gaps.
     */
    protected function renders_environment_notice_inline(): bool
    {
        return true;
    }

    /**
     * WooCommerce renders this gateway's settings screen through admin_options(), which makes it
     * the one place a warning is guaranteed to be visible exactly where the merchant configures
     * the key — and only there.
     */
    public function admin_options()
    {
        $this->render_missing_extension_notice();

        parent::admin_options();
    }

    /** Separate from admin_options() so it can be asserted without WooCommerce's settings render. */
    public function render_missing_extension_notice(): void
    {
        $missing = EnvironmentRequirements::missing_onchain_extensions();

        if (!empty($missing)) {
            printf(
                '<div class="notice notice-warning inline"><p><strong>%s</strong><br>%s</p></div>',
                'This server cannot derive addresses from an extended public key.',
                wp_kses_post(sprintf(
                    'The PHP %1$s extension is not installed, so an xPub/yPub/zPub cannot be used here — ask your host to enable it. You can still accept on-chain payments right now by entering a single fixed address starting with %2$s in the field below; every order is then paid to that same address, which is worse for privacy but works without the extension.',
                    esc_html(EnvironmentRequirements::describe($missing)),
                    '<code>' . esc_html($this->segwit_prefix($this->get_option('selected_network', 'mainnet'))) . '</code>'
                ))
            );
        }
    }

    private function get_bitcoin_address_service(): BitcoinAddressService
    {
        return $this->bitcoin_address_service ??= new BitcoinAddressService();
    }

    private function get_db_statements_service(): PayCryptoMeDBStatementsService
    {
        return $this->db_statements_service ??= new PayCryptoMeDBStatementsService();
    }

    public function ajax_reset_derivation_index()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        check_ajax_referer('paycrypto_me_nonce', 'security');

        $reset = $this->get_db_statements_service()->reset_derivation_indexes();

        if ($reset === false) {
            $this->register_paycrypto_me_log('Failed to reset derivation indexes via admin panel.', 'error');
            wp_send_json_error('Reset failed. Check WooCommerce logs for details.', 500);
            return;
        }

        $this->register_paycrypto_me_log('Derivation indexes have been reset via admin panel.', 'warning');

        wp_send_json_success('Reset request received.');
    }

    public function process_admin_options()
    {
        if (isset($_POST['paycrypto_me_nonce'])) {
            $nonce = isset($_POST['paycrypto_me_nonce']) ? sanitize_text_field(wp_unslash($_POST['paycrypto_me_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'paycrypto_me_settings')) {
                wp_die('Security check failed');
            }
        } else {
            $wpnonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
            if (!wp_verify_nonce($wpnonce, 'woocommerce-settings')) {
                wp_die('Security check failed');
            }
        }

        $this->sync_debug_log_from_post();

        $selected_network = isset($_POST['woocommerce_paycrypto_me_selected_network']) ? sanitize_text_field(wp_unslash($_POST['woocommerce_paycrypto_me_selected_network'])) : null;
        $network_identifier = isset($_POST['woocommerce_paycrypto_me_network_identifier']) ? sanitize_text_field(wp_unslash($_POST['woocommerce_paycrypto_me_network_identifier'])) : '';
        $network_config = $this->get_network_config($selected_network);

        if (empty($network_identifier)) {
            $format = 'Please enter a valid %s.';
            \WC_Admin_Settings::add_error(sprintf($format, esc_html($network_config['field_label'])));
            return false;
        }

        // Without the crypto extension there is nothing to validate an xPub against: the parse
        // fails on the host, not on the key. Save what was submitted (blocking the form would also
        // lock the admin out of the title, the enable checkbox and everything else on this screen)
        // and let admin_options()/render_unavailability_notice() report the host-level cause.
        // A bech32 address is still validated normally here — it needs no big-integer math.
        $needs_gmp = $this->get_bitcoin_address_service()->requires_gmp_math(
            $network_identifier,
            $this->network_for($selected_network)
        );

        if ($needs_gmp && !empty(EnvironmentRequirements::missing_onchain_extensions())) {
            return parent::process_admin_options();
        }

        try {
            $identifier_is_valid = $this->validate_network_identifier($selected_network, $network_identifier);
        } catch (PayCryptoMeException $e) {
            $format = 'The wallet key could not be validated because of an internal error: %s. Nothing was saved. This is not a problem with the key you entered.';
            \WC_Admin_Settings::add_error(sprintf($format, esc_html(wp_strip_all_tags($e->getMessage()))));
            return false;
        }

        if (!$identifier_is_valid) {
            if ($this->is_xpub_network_mismatch($selected_network, $network_identifier)) {
                $wrong_network = $selected_network === 'testnet' ? 'mainnet' : 'testnet';
                $format = 'This key belongs to %1$s, but the selected network is %2$s. Please provide a key for the selected network.';
                \WC_Admin_Settings::add_error(sprintf($format, esc_html($wrong_network), esc_html($selected_network)));
                return false;
            }

            $format = 'The %s provided is not valid for the selected network.';
            \WC_Admin_Settings::add_error(sprintf($format, esc_html($network_config['field_label'])));
            return false;
        }

        return parent::process_admin_options();
    }

    public function get_available_networks()
    {
        return array(
            'mainnet' => array(
                'name' => 'Bitcoin Mainnet',
                'address_prefix' => array('1', '3', 'bc1'),
                'xpub_prefix' => array('xpub', 'ypub', 'zpub'),
                'testnet' => false,
                'field_type' => 'text',
                'field_label' => __('Wallet xPub', 'paycrypto-me-for-woocommerce'),
                'field_placeholder' => 'e.g., xpub6, ypub6, zpub6...',
            ),
            'testnet' => array(
                'name' => 'Bitcoin Testnet',
                'address_prefix' => array('m', 'n', '2', 'tb1'),
                'xpub_prefix' => array('tpub', 'upub', 'vpub'),
                'testnet' => true,
                'field_type' => 'text',
                'field_label' => __('Testnet Wallet xPub', 'paycrypto-me-for-woocommerce'),
                'field_placeholder' => 'e.g., tpub6, upub6, vpub6...',
            )
        );
    }

    public function get_available_cryptocurrencies($network = null)
    {
        return ['BTC']; //@NOTE: all networks using same crypto.
    }

    protected function init_form_fields_items()
    {
        return [
            'network_identifier' => array(
                'title' => __('Network Identifier', 'paycrypto-me-for-woocommerce'),
                'type' => 'text',
                'default' => '',
                'required' => true,
                'description' => __('Tip: It is always preferable to use the wallet xPub rather than a wallet address for Bitcoin payments.', 'paycrypto-me-for-woocommerce'),
                'custom_attributes' => array('maxlength' => 255)
            ),
            'payment_timeout_hours' => array(
                'title' => __('Payment Timeout (hours)', 'paycrypto-me-for-woocommerce'),
                'type' => 'number',
                'description' => $this->pro_soon_badge() . '<br>' . __('Automatic order expiry after the timeout ships in the upcoming PayCrypto.Me Pro add-on. In the free version, on-chain addresses stay valid until paid.', 'paycrypto-me-for-woocommerce'),
                'custom_attributes' => array('min' => '1', 'step' => '1', 'max' => '72', 'disabled' => 'disabled'),
                'default' => '24',
                'class' => 'paycrypto-pro-field',
            ),
            'payment_number_confirmations' => array(
                'title' => __('Payment number of confirmations', 'paycrypto-me-for-woocommerce'),
                'type' => 'number',
                'description' => $this->pro_soon_badge() . '<br>' . __('Automatic on-chain confirmation tracking ships in the upcoming PayCrypto.Me Pro add-on. In the free version, payments are verified manually.', 'paycrypto-me-for-woocommerce'),
                'custom_attributes' => array('min' => '1', 'step' => '1', 'max' => '6', 'disabled' => 'disabled'),
                'default' => '3',
                'class' => 'paycrypto-pro-field',
            ),
            'paycrypto_danger_area' => array(
                'type' => 'title',
                'title' => __('Danger Area', 'paycrypto-me-for-woocommerce'),
                'description' => '
                <div class="paycrypto-danger-box">
                    <strong>' . esc_html__('Warning:', 'paycrypto-me-for-woocommerce') . '</strong> ' . __('Resetting the payment derivation index will lead to the reuse of addresses and loss of past data. Proceed with caution and ensure you understand the implications.', 'paycrypto-me-for-woocommerce') . '
                    <br>
                    <button type="button" id="paycrypto-me-reset-derivation-index" class="button paycrypto-danger-btn" style="margin-top: 8px;">' . esc_html__('Reset payment address derivation index', 'paycrypto-me-for-woocommerce') . '</button>
                </div>
                ',
            ),
        ];
    }

    public function payment_fields()
    {
        wc_get_template(
            'checkout/paycrypto-me-checkout-option.php',
            array(
                'debug_log' => $this->debug_log,
                'description' => $this->description,
                'crypto_currency' => $this->get_available_cryptocurrencies()[0] ?? '',
            ),
            '',
            WC_PayCryptoMe::plugin_abspath() . 'templates/'
        );
    }

    public function build_order_display_args(\WC_Order $order): ?array
    {
        $payment_address = $order->get_meta('_paycrypto_me_payment_address');

        if (!$payment_address || !OrderGatewayMatcher::matches($order, $this->id)) {
            return null;
        }

        $crypto_network = $order->get_meta('_paycrypto_me_crypto_network');

        return [
            'payment_identifier'     => $payment_address,
            'payment_uri'            => $order->get_meta('_paycrypto_me_payment_uri'),
            'logo_path'              => WC_PayCryptoMe::plugin_abspath() . 'assets/bitcoin-icon.png',
            'qr_logo_options'        => [
                'border' => [
                    'shape'      => 'circle',
                    'width'      => 4,
                    'color'      => '#FFFFFF',
                    'background' => '#FFFFFF',
                    'size'       => 48,
                ],
            ],
            'crypto_network'         => $crypto_network,
            'network_label'          => match ($crypto_network) {
                'mainnet' => __('On-Chain', 'paycrypto-me-for-woocommerce'),
                'testnet' => __('Testnet', 'paycrypto-me-for-woocommerce'),
                default   => $crypto_network,
            },
            'crypto_amount'          => $order->get_meta('_paycrypto_me_crypto_amount'),
            'crypto_currency'        => $order->get_meta('_paycrypto_me_crypto_currency'),
            'confirmations_required' => (int) $order->get_meta('_paycrypto_me_payment_number_confirmations'),
            // On-chain has no timeout enforcement (Pro add-on feature), so no expiry is shown.
            'show_expiry'            => false,
        ];
    }

    public function generate_settings_html($form_fields = array(), $echo = true)
    {
        $html = parent::generate_settings_html($form_fields, false);

        $nonce_field = wp_nonce_field('paycrypto_me_settings', 'paycrypto_me_nonce', true, false);
        $html = str_replace('</table>', $nonce_field . '</table>', $html);

        if ($echo) {
            echo wp_kses_post($html);
        }

        return $html;
    }

    protected function admin_enqueue_scripts_content($screen)
    {
        if ($screen && ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order')) {
            $css_path = WC_PayCryptoMe::plugin_abspath() . 'assets/css/frontend/paycrypto-me-order-details.css';
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'paycrypto-me-admin-order-details',
                    WC_PayCryptoMe::plugin_url() . '/assets/css/frontend/paycrypto-me-order-details.css',
                    array(),
                    filemtime($css_path)
                );
            }
        }
    }

    private function validate_xpub_address($network_type, $identifier)
    {
        // Checked before any conversion: convert_extended_pubkey_prefix() rewrites version
        // bytes to the target network, so a testnet key would otherwise always validate
        // successfully against mainnet (and vice-versa).
        if (!$this->get_bitcoin_address_service()->prefix_matches_network($identifier, $network_type)) {
            $this->register_paycrypto_me_log(
                \sprintf(
                    'xpub network mismatch for %s: `%s`',
                    $network_type,
                    $this->mask_identifier_for_log($network_type, $identifier)
                ),
                'error'
            );

            return false;
        }

        $network = $this->network_for($network_type);

        // No logger passed on purpose: a failure here just means the identifier
        // isn't an extended pubkey (it may be a valid static address), so the
        // internal parse error would only be noise. A genuine failure is still
        // reported by validate_network_identifier's final `error` log.
        try {
            if ($ok = $this->get_bitcoin_address_service()->validate_extended_pubkey($identifier, $network)) {
                return $ok;
            }
        } catch (\Error $th) {
            // Only \Error lands here: the service returns false for every "this isn't a valid
            // key" Exception. An \Error is the host or our own code failing (a missing extension,
            // a type error) — reporting it as an invalid key blames the store owner for a
            // problem that isn't theirs, so surface it as what it is.
            $this->register_paycrypto_me_log(
                \sprintf('xpub validation failed internally: %s', esc_html( wp_strip_all_tags( $th->getMessage() ) )),
                'error'
            );

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $th is the previous Throwable (arg 3), not output; the message above is already escaped.
            throw new PayCryptoMeException(esc_html(wp_strip_all_tags($th->getMessage())), 0, $th);
        }

        return false;
    }

    private function address_prefix_matches_network($network_type, $identifier)
    {
        $networks = $this->get_available_networks();

        foreach ($networks[$network_type]['address_prefix'] ?? [] as $prefix) {
            if (str_starts_with($identifier, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function validate_network_identifier($network_type, $identifier)
    {
        $network = $this->network_for($network_type);

        if ($this->validate_xpub_address($network_type, $identifier)) {
            return true;
        }

        $logger = fn($message, $level) => $this->register_paycrypto_me_log($message, $level);

        // Same \Error contract as validate_xpub_address(): a host/internal fault must not be
        // reported as an invalid address. Wrapped here too so it can never escape uncaught and
        // fatal the settings screen.
        try {
            $address_is_valid = $this->address_prefix_matches_network($network_type, $identifier)
                && $this->get_bitcoin_address_service()->validate_bitcoin_address($identifier, $network, $logger);
        } catch (\Error $th) {
            $this->register_paycrypto_me_log(
                \sprintf('address validation failed internally: %s', esc_html( wp_strip_all_tags( $th->getMessage() ) )),
                'error'
            );

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $th is the previous Throwable (arg 3), not output; the message above is already escaped.
            throw new PayCryptoMeException(esc_html(wp_strip_all_tags($th->getMessage())), 0, $th);
        }

        if ($address_is_valid) {
            $this->register_paycrypto_me_log(
                'A fixed Bitcoin address was saved. Payments will work, but every order is paid to this same address. For better privacy, we recommend using an extended public key (xpub/ypub/zpub) instead, which automatically creates a new address for each order.',
                'notice'
            );

            return true;
        }

        $this->register_paycrypto_me_log(
            \sprintf('Network identifier validation failed for %s: `%s`', $network_type, $this->mask_identifier_for_log($network_type, $identifier)),
            'error'
        );

        return false;
    }

    /**
     * Whether a failed validate_network_identifier() call was specifically caused by an
     * extended-pubkey network mismatch, so process_admin_options() can show a clearer error.
     */
    private function is_xpub_network_mismatch($network_type, $identifier)
    {
        return !$this->get_bitcoin_address_service()->prefix_matches_network($identifier, $network_type);
    }

    private function mask_identifier_for_log($network_type, $identifier)
    {
        if (\strlen($identifier) > 10) {
            return substr($identifier, 0, 6) . '...' . substr($identifier, -4);
        }

        return $identifier;
    }
}