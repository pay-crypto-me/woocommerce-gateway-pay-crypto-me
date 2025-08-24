<?php
/**
 * Plugin Name: PayCrypto.Me for WooCommerce
 * Plugin URI: https://github.com/pay-crypto-me/woocommerce-gateway-pay-crypto-me/
 * Description: PayCrypto.Me Payments for WooCommerce introduces a complete solution that allows your customers to pay with BTC, ETH, SOL, and many other cryptocurrencies in your WooCommerce store.
 * Version: 0.1.0
 * Author: PayCrypto.Me
 * Author URI: https://paycrypto.me/
 * Developer: Lucas Rosa
 * Developer URI: https://github.com/lucas-rosa95
 * Text Domain: woocommerce-gateway-pay-crypto-me
 * Domain Path: /languages
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

defined('ABSPATH') || exit;

if (!class_exists('WC_PayCrypto_Me_Payments')) {
    /**
     * WC_PayCrypto_Me_Payments core class
     */
    class WC_PayCrypto_Me_Payments
    {
        /**
         * The single instance of the class.
         */
        protected static $instance = null;

        protected function __construct()
        {
            $this->load_textdomain();
            $this->includes();

            add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));

        }

        public static function instance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public static function add_gateway($gateways)
        {
            $options = get_option('woocommerce_paycrypto_me_settings', array());

            $hide_for_non_admin_users =
                isset($options['hide_for_non_admin_users']) ? $options['hide_for_non_admin_users'] : 'no';

            if (
                ('yes' === $hide_for_non_admin_users && current_user_can('manage_options')) ||
                'no' === $hide_for_non_admin_users
            ) {
                $gateways[] = 'WC_Gateway_PayCrypto_Me';
            }

            return $gateways;
        }

        /**
         * Load the plugin text domain for translations
         */
        protected function load_textdomain()
        {
            load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        }

        protected function includes()
        {
            if (class_exists('WC_Payment_Gateway')) {
                include_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-pay-crypto-me.php';
            }
        }

        /**
         * Cloning is forbidden.
         */
        public function __clone()
        {
            // Override this PHP function to prevent unwanted copies of your instance.
            //TODO: Implement your own error or use `wc_doing_it_wrong()`
        }

        /**
         * Unserializing instances of this class is forbidden.
         */
        public function __wakeup()
        {
            // Override this PHP function to prevent unwanted copies of your instance.
            //TODO: Implement your own error or use `wc_doing_it_wrong()`
        }
    }

    // class WC_PayCrypto_Me_Payments
    // {

    //     /**
    //      * Constructor
    //      */
    //     public function __construct()
    //     {
    //         // Check if WooCommerce is active
    //         if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    //             // Include the main gateway class
    //             include_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-pay-crypto-me.php';

    //             // Add the gateway to WooCommerce
    //             add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
    //         } else {
    //             // WooCommerce is not active, display admin notice
    //             add_action('admin_notices', array($this, 'woocommerce_inactive_notice'));
    //         }
    //     }

    //     /**
    //      * Add the PayCrypto.Me gateway to WooCommerce
    //      *
    //      * @param array $gateways Existing payment gateways
    //      * @return array Modified payment gateways
    //      */
    //     public function add_gateway($gateways)
    //     {
    //         $gateways[] = 'WC_Gateway_Pay_Crypto_Me';
    //         return $gateways;
    //     }

    //     /**
    //      * Display an admin notice if WooCommerce is not active
    //      */
    //     public function woocommerce_inactive_notice()
    //     {
    //         echo '<div class="error"><p>' . __('PayCrypto.Me for WooCommerce requires WooCommerce to be installed and active.', 'woocommerce-gateway-pay-crypto-me') . '</p></div>';
    //     }
    // }
}

function wc_paycrypto_me_payments_initialize()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>' . __('PayCrypto.Me for WooCommerce requires WooCommerce to be installed and active.', 'woocommerce-gateway-pay-crypto-me') . '</p></div>';
        });
        return;
    }

    // Initialize the main plugin class
    $GLOBALS['my_extension'] = WC_PayCrypto_Me_Payments::instance();
}

add_action('plugins_loaded', 'wc_paycrypto_me_payments_initialize', 10);
