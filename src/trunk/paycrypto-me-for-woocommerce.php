<?php
/**
 * Plugin Name: PayCrypto.Me for WooCommerce
 * Plugin URI: https://github.com/paycrypto-me/paycrypto-me-for-woocommerce
 * Description: PayCrypto.Me for WooCommerce lets your store accept Bitcoin payments — On-Chain and Lightning Network — directly into wallets and nodes you control.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 6.5
 * WC tested up to: 10.9
 * Contributors: paycryptome, lucasrosa95
 * Donate link: https://paycrypto.me/
 * Author: PayCrypto.Me
 * Author URI: https://paycrypto.me/
 * Text Domain: paycrypto-me-for-woocommerce
 * Domain Path: /languages/
 *
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    // Installing from a GitHub source zip is a real path here: vendor/ is gitignored and the
    // Plugin URI above points at GitHub. Without the autoloader, none of this plugin's own
    // namespaced classes resolve either (Composer's classmap covers includes/ and exceptions/),
    // so every class below would fatal the moment anything tried to use it. Warn and stop
    // instead of registering hooks that are guaranteed to fatal when WordPress fires them.
    add_action('admin_notices', function () {
        echo '<div class="error"><p>' . esc_html__('PayCrypto.Me for WooCommerce is missing required files (vendor/). If you installed it from a GitHub source zip, please install it from the WordPress.org plugin directory or an official release zip instead.', 'paycrypto-me-for-woocommerce') . '</p></div>';
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [PayCryptoMeBitcoinGatewayActivate::class, 'activate']);
register_activation_hook(__FILE__, [PayCryptoMeLightningGatewayActivate::class, 'activate']);

if (!class_exists(__NAMESPACE__ . '\\WC_PayCryptoMe')) {
    class WC_PayCryptoMe
    {
        public const VERSION = '0.1.0';

        // Schema version for the 4 custom tables (independent of plugin VERSION above) —
        // bump this whenever the dbDelta SQL in either *GatewayActivate class changes, so
        // maybe_upgrade_db() re-runs dbDelta for existing installs (WordPress doesn't
        // re-fire register_activation_hook on a plugin update, only on activate).
        public const DB_VERSION = '1';

        public const URL_SUPPORT = 'mailto:contact@paycrypto.me';
        public const URL_PREMIUM = 'https://paycrypto.me/';
        public const URL_GITHUB = 'https://github.com/paycrypto-me/paycrypto-me-for-woocommerce/';

        protected static $instance = null;

        protected function __construct()
        {
            $this->includes();
            add_filter('woocommerce_payment_gateways', [__CLASS__, 'add_gateway']);
            add_filter('woocommerce_available_payment_gateways', [AvailablePaymentGatewaysFilter::class, 'filter']);
            add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);
            add_action('woocommerce_blocks_loaded', [$this, 'load_blocks_support']);
            add_action('init', [$this, 'load_textdomain']);
            add_action('admin_notices', [__CLASS__, 'render_db_activation_errors']);

            self::maybe_upgrade_db();
        }

        /**
         * Re-runs dbDelta for both custom-table sets when DB_VERSION changed since the last
         * recorded run — the only way schema changes reach a site that installed an earlier
         * version, since WordPress only fires register_activation_hook on activate/reactivate.
         */
        public static function maybe_upgrade_db()
        {
            if (get_option('paycrypto_me_db_version') === self::DB_VERSION) {
                return;
            }

            PayCryptoMeBitcoinGatewayActivate::activate();
            PayCryptoMeLightningGatewayActivate::activate();

            update_option('paycrypto_me_db_version', self::DB_VERSION);
        }

        public static function render_db_activation_errors()
        {
            $errors = get_option('paycrypto_me_db_activation_errors', []);

            if (empty($errors) || !current_user_can('manage_options')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p>%s</p><ul>',
                esc_html__('PayCrypto.Me for WooCommerce: some database tables failed to install correctly. Payments may not work correctly until this is resolved — check with your host and try deactivating/reactivating the plugin.', 'paycrypto-me-for-woocommerce')
            );

            // Escaped per-item in the loop rather than mapped/imploded into the printf above:
            // static analysis can't trace escaping through array_map(), and this notice must stay
            // provably escaped since $errors carries raw $wpdb->last_error strings.
            foreach ($errors as $error) {
                printf('<li>%s</li>', esc_html($error));
            }

            echo '</ul></div>';

            delete_option('paycrypto_me_db_activation_errors');
        }

        public function load_textdomain()
        {
            // Deliberate: WordPress.org's automatic loading only covers language packs delivered via
            // translate.wordpress.org; it does not load the .mo files bundled in this plugin's own
            // /languages folder. We ship 7 complete locales maintained in-house and want them available
            // immediately on activation, so we load them explicitly. Plugin Check flags this call as
            // discouraged (its guidance assumes only language-pack translations); the directive doesn't
            // apply to bundled files, and there's no inline suppression for this specific PCP check.
            load_plugin_textdomain(
                'paycrypto-me-for-woocommerce',
                false,
                dirname(plugin_basename(__FILE__)) . '/languages/'
            );
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
                $gateways[] = __NAMESPACE__ . '\WC_Gateway_PayCryptoMe_Lightning';
            }

            return $gateways;
        }

        public static function add_action_links($links)
        {
            $action_links = [
                sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')),
                    esc_html__('Settings', 'paycrypto-me-for-woocommerce')
                ),
                sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer" style="color:#00a32a;font-weight:600;">%s</a>',
                    esc_url(self::URL_PREMIUM),
                    esc_html__('Get Premium', 'paycrypto-me-for-woocommerce')
                ),
            ];

            return array_merge($action_links, $links);
        }

        public static function add_row_meta_links($links, $file)
        {
            if (plugin_basename(__FILE__) !== $file) {
                return $links;
            }

            $links[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url(self::URL_SUPPORT),
                esc_html__('Support', 'paycrypto-me-for-woocommerce')
            );
            $links[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url(self::URL_GITHUB),
                esc_html__('GitHub', 'paycrypto-me-for-woocommerce')
            );

            return $links;
        }

        protected function includes()
        {
            if (class_exists('WC_Payment_Gateway')) {
                include_once plugin_dir_path(__FILE__) . 'includes/abstract-class-wc-gateway-paycrypto-me.php';
                include_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-paycrypto-me.php';
                include_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-paycrypto-me-lightning.php';
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
                include_once plugin_dir_path(__FILE__) . 'includes/blocks/class-wc-gateway-paycrypto-me-lightning-blocks.php';
            }
        }
        public static function log($message, $level = 'info')
        {
            $logger = \wc_get_logger();
            $logger->log($level, $message, ['source' => 'paycrypto_me']);
        }
        public function __clone()
        {
            _doing_it_wrong(__FUNCTION__, 'Cloning is forbidden.', '0.1.0');
        }
        public function __wakeup()
        {
            _doing_it_wrong(__FUNCTION__, 'Unserializing is forbidden.', '0.1.0');
        }
    }
}

function wc_paycrypto_me_initialize()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>PayCrypto.Me for WooCommerce requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    \PayCryptoMe\WooCommerce\WC_PayCryptoMe::instance();
}

add_action('plugins_loaded', __NAMESPACE__ . '\\wc_paycrypto_me_initialize', 10);

// Plugin-list links are registered at file scope (independent of WooCommerce being active)
// so they always show while the plugin itself is active.
add_filter(
    'plugin_action_links_' . plugin_basename(__FILE__),
    [WC_PayCryptoMe::class, 'add_action_links']
);
add_filter('plugin_row_meta', [WC_PayCryptoMe::class, 'add_row_meta_links'], 10, 2);

