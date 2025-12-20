<?php
/**
 * Plugin Name: PayCrypto.Me for WooCommerce
 * Plugin URI: https://github.com/paycrypto-me/woocommerce-gateway-paycrypto-me/
 * Description: PayCrypto.Me Payments for WooCommerce introduces a complete solution that allows your customers to pay with BTC, ETH, SOL, and many other cryptocurrencies in your WooCommerce store.
 * Version: 0.1.0
 * Author: PayCrypto.Me
 * Author URI: https://paycrypto.me/
 * Developer: Lucas Rosa
 * Developer URI: https://github.com/lucas-rosa95
 * Text Domain: woocommerce-gateway-paycrypto-me
 * Domain Path: /languages/
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// register_activation_hook(__FILE__, function () {
//     // @NOTE: create options, tables, etc --- IGNORE ---
// });

// register_deactivation_hook(__FILE__, function () {
//     //@NOTE: clear caches, transients, etc --- IGNORE ---
// });

if (!class_exists(__NAMESPACE__ . '\\WC_PayCryptoMe')) {
    class WC_PayCryptoMe
    {
        public const string VERSION = '0.1.0';
        protected static $instance = null;

        protected function __construct()
        {
            $this->includes();
            $this->load_textdomain();

            add_filter('woocommerce_payment_gateways', [__CLASS__, 'add_gateway']);
            add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);
            add_action('woocommerce_blocks_loaded', [$this, 'load_blocks_support']);
        }

        public static function instance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public static function plugin_url()
        {
            return untrailingslashit(plugins_url('/', __FILE__));
        }

        public static function plugin_abspath()
        {
            return trailingslashit(plugin_dir_path(__FILE__));
        }

        public static function add_gateway($gateways)
        {
            $options = get_option('woocommerce_paycrypto_me_settings', []);

            $hide_for_non_admin_users =
                isset($options['hide_for_non_admin_users']) ? $options['hide_for_non_admin_users'] : 'no';

            if (
                ('yes' === $hide_for_non_admin_users && current_user_can('manage_options')) ||
                'no' === $hide_for_non_admin_users
            ) {
                $gateways[] = __NAMESPACE__ . '\WC_Gateway_PayCryptoMe';
            }

            return $gateways;
        }

        protected function load_textdomain()
        {
            load_plugin_textdomain('woocommerce-gateway-paycrypto-me', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        }

        protected function includes()
        {
            if (class_exists('WC_Payment_Gateway')) {
                include_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-paycrypto-me.php';
            }
        }

        public function declare_wc_compatibility()
        {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('woocommerce_blocks', __FILE__, true);
            }
        }
        public function load_blocks_support()
        {
            if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
                include_once plugin_dir_path(__FILE__) . 'includes/blocks/class-wc-gateway-paycrypto-me-blocks.php';
            }
        }
        public static function log($message, $level = 'info')
        {
            $options = get_option('woocommerce_paycrypto_me_settings', []);
            $logging = isset($options['debug_log']) ? $options['debug_log'] : 'no';
            if ('yes' === $logging && function_exists('wc_get_logger')) {
                $logger = \wc_get_logger();
                $logger->log($level, $message, ['source' => 'paycrypto_me']);
            }
        }
        public function __clone()
        {
            _doing_it_wrong(__FUNCTION__, esc_html__('Cloning is forbidden.', 'woocommerce-gateway-paycrypto-me'), '0.1.0');
        }
        public function __wakeup()
        {
            _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing is forbidden.', 'woocommerce-gateway-paycrypto-me'), '0.1.0');
        }
    }
}

function wc_paycrypto_me_initialize()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>' . esc_html__('PayCrypto.Me for WooCommerce requires WooCommerce to be installed and active.', 'woocommerce-gateway-paycrypto-me') . '</p></div>';
        });
        return;
    }

    \PayCryptoMe\WooCommerce\WC_PayCryptoMe::instance();
}

add_action('plugins_loaded', __NAMESPACE__ . '\\wc_paycrypto_me_initialize', 10);

function paycrypto_me_before_payment($order_id, $data)
{
    do_action('paycrypto_me_before_payment', $order_id, $data);
}

