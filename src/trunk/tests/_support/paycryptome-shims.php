<?php
namespace PayCryptoMe\WooCommerce {
    // Minimal plugin shim used by AssetManager and tests
    if (!class_exists('PayCryptoMe\\WooCommerce\\WC_PayCryptoMe')) {
        class WC_PayCryptoMe
        {
            public const VERSION = '0.1.0';
            public const NAME_BRAND = 'PayCrypto.Me';
            public const NAME_PRO_ADDON = 'PayCrypto.Me Pro';
            public const NAME_PRO_ADDON_SHORT = 'Pro';
            public const BTCPAY_DEFAULT_PAYMENT_METHOD_ID = 'BTC-LN';

            public static function plugin_abspath()
            {
                // Prefer tests fixtures directory if present
                $tests_assets = dirname(__DIR__) . '/assets/blocks/';
                if (is_dir($tests_assets)) {
                    return dirname(__DIR__) . '/';
                }
                return dirname(__DIR__, 2) . '/';
            }

            public static function plugin_url()
            {
                return 'http://example.org/wp-content/plugins/paycrypto-me-for-woocommerce';
            }

            public static function log($message, $level = 'info')
            {
                fwrite(STDERR, "[paycryptome] {$level}: {$message}\n");
            }
        }
    }

    if (!function_exists('PayCryptoMe\\WooCommerce\\esc_sql')) {
        function esc_sql($text)
        {
            return addslashes((string) $text);
        }
    }
}
