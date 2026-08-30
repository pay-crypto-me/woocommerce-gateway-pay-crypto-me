<?php
/**
 * Plugin Name: PayCrypto.Me for WooCommerce
 * Plugin URI: https://paycrypto.me/woocommerce/
 * Description: PayCrypto.Me for WooCommerce lets your store accept Bitcoin payments — On-Chain and Lightning Network — directly into wallets and nodes you control.
 * Version: 0.2.1
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

// The bundled vendor/ is resolved for the PHP version declared in "Requires PHP" above
// (config.platform.php in composer.json states the same floor), so Composer's generated
// platform_check.php throws a RuntimeException the moment autoload.php is required on anything
// older — an uncaught fatal that takes the whole site down without naming the plugin behind it.
// WordPress refuses to activate or update a plugin below its "Requires PHP", so what is left is a
// site whose PHP was downgraded after activation; it deserves the same treatment as a missing
// vendor/ below: say what is wrong and stop loading. PhpFloorConsistencyTest fails if this value,
// the two headers and the Composer pin ever drift apart.
if (PHP_VERSION_ID < 80100) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p>PayCrypto.Me for WooCommerce requires PHP 8.1 or newer. This site runs PHP '
            . esc_html(PHP_VERSION)
            . ', so the plugin stopped loading instead of taking the site down with a fatal error. '
            . 'Ask your host to update PHP.</p></div>';
    });

    return;
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    // Installing from a GitHub source zip is a real path here: vendor/ is gitignored and the
    // Plugin URI above points at GitHub. Without the autoloader, none of this plugin's own
    // namespaced classes resolve either (Composer's classmap covers includes/ and exceptions/),
    // so every class below would fatal the moment anything tried to use it. Warn and stop
    // instead of registering hooks that are guaranteed to fatal when WordPress fires them.
    add_action('admin_notices', function () {
        echo '<div class="error"><p>PayCrypto.Me for WooCommerce is missing required files (vendor/). If you installed it from a GitHub source zip, please install it from the WordPress.org plugin directory or an official release zip instead.</p></div>';
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Single entry point on purpose: DbInstaller::install() has to clear the previous error buffer
// once, run both activators, and only then decide whether the schema version may be recorded. Two
// separate activation hooks could not coordinate that (the second would wipe the first's errors).
// activate(), not install() directly: activation fires do_action("activate_{$plugin}", $network_wide)
// with a bool, which install(bool $force) would otherwise receive as $force.
register_activation_hook(__FILE__, [DbInstaller::class, 'activate']);

if (!class_exists(__NAMESPACE__ . '\\WC_PayCryptoMe')) {
    class WC_PayCryptoMe
    {
        public const VERSION = '0.2.1';

        public const URL_SUPPORT = 'mailto:contact@paycrypto.me';
        public const URL_PRO = 'https://paycrypto.me/woocommerce/';
        public const URL_GITHUB = 'https://github.com/paycrypto-me/paycrypto-me-for-woocommerce/';

        public const NAME_BRAND = 'PayCrypto.Me';
        public const NAME_PRO_ADDON = 'PayCrypto.Me Pro';
        public const NAME_PRO_ADDON_SHORT = 'Pro';
        public const BTCPAY_DEFAULT_PAYMENT_METHOD_ID = 'BTC-LN';

        protected static $instance = null;

        protected function __construct()
        {
            $this->includes();
            add_filter('woocommerce_payment_gateways', [__CLASS__, 'add_gateway']);
            add_filter('woocommerce_available_payment_gateways', [AvailablePaymentGatewaysFilter::class, 'filter']);
            add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);
            add_action('woocommerce_blocks_loaded', [$this, 'load_blocks_support']);
            add_action('init', [$this, 'load_textdomain']);
            add_action('admin_notices', [DbInstaller::class, 'render_activation_errors']);
            add_action('admin_notices', [__CLASS__, 'render_gateway_unavailability_notices']);

            // Hooked, not called here: this constructor runs on plugins_loaded, i.e. on EVERY
            // request including a shopper's. An ALTER on paycrypto_me_bitcoin_transactions_data
            // (which grows with the store's orders) would then land in a customer's page load, and
            // dbDelta's own require of wp-admin/includes/upgrade.php has no business running there.
            // admin_init covers the merchant's next admin page. upgrader_process_complete only
            // actually helps WP-CLI/cron auto-updates: an admin-UI update runs through
            // wp-admin/update.php, which fires admin_init BEFORE handling the upgrade, so
            // DbInstaller autoloads with the pre-update DB_VERSION and is_current() no-ops; the
            // class only sees the new DB_VERSION on the NEXT admin_init. Both hooks stay — the
            // invariant that covers the gap in between: no payment path may depend on a column
            // from a schema version newer than the recorded one — consult DbInstaller::is_current()
            // and degrade.
            add_action('admin_init', [DbInstaller::class, 'maybe_upgrade']);
            add_action('upgrader_process_complete', [DbInstaller::class, 'maybe_upgrade_after_update']);
        }

        /**
         * Renders each PayCrypto.Me gateway's "enabled but hidden from checkout" notice.
         *
         * Hooked here, once, rather than from each gateway's own constructor: WooCommerce rebuilds
         * every gateway after a settings save (WC_Settings_Payment_Gateways::save() calls
         * WC_Payment_Gateways::init() again), so a per-instance callback got registered a second
         * time — two distinct objects are not a duplicate callback as far as WordPress is
         * concerned, so the same warning was printed twice on the very screen the merchant had
         * just saved. Iterating the loaded gateways also means the notice always reflects the
         * CURRENT instance instead of a settings snapshot taken before that save.
         */
        public static function render_gateway_unavailability_notices()
        {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;

            // Coarse gate only: each gateway still decides for itself, on its own settings section
            // (Abstract_WC_Gateway_PayCryptoMe::on_own_settings_screen()). It is here so that an
            // unrelated admin page doesn't pay for instantiating every registered payment gateway.
            if (!$screen || $screen->id !== 'woocommerce_page_wc-settings' || !function_exists('WC')) {
                return;
            }

            foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
                if ($gateway instanceof Abstract_WC_Gateway_PayCryptoMe) {
                    $gateway->render_unavailability_notice();
                }
            }
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

        /**
         * Both gateways are always registered; hiding is decided per gateway.
         *
         * This used to read the On-Chain gateway's `hide_for_non_admin_users` and, when set,
         * register NEITHER gateway — so an On-Chain setting silently hid Lightning too,
         * ignoring Lightning's own setting. Abstract_WC_Gateway_PayCryptoMe::is_available()
         * already applies each gateway's own value, which is the only place that decision
         * belongs.
         */
        public static function add_gateway($gateways)
        {
            $gateways[] = __NAMESPACE__ . '\WC_Gateway_PayCryptoMe';
            $gateways[] = __NAMESPACE__ . '\WC_Gateway_PayCryptoMe_Lightning';

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
                    esc_url(self::URL_PRO),
                    esc_html(sprintf(
                        /* translators: %s: short add-on name (not translated, product name), e.g. "Pro". */
                        __('Get %s', 'paycrypto-me-for-woocommerce'),
                        self::NAME_PRO_ADDON_SHORT
                    ))
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
