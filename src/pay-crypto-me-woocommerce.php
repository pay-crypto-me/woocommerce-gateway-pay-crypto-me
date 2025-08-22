<?php

/**
 * Plugin Name: PayCrypto.Me Payments Gateway
 * Plugin URI: https://github.com/pay-crypto-me/pay-crypto-me-woocommerce
 * Description: PayCrypto.Me WooCommerce introduces a complete solution to receive your payments through the main cryptocurrencies to your WooCommerce website.
 * Version: 0.1.0
 *
 * Author: PayCrypto.Me
 * Author URI: https://paycrypto.me/
 *
 * Text Domain: pay-crypto-me-woocommerce
 * Domain Path: /i18n/languages/
 *
 * Requires at least: 4.2
 * Tested up to: 6.6
 *
 * Copyright: © 2009-2024 Automattic.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

/**
 * WC PayCrypto.Me Payment gateway plugin class.
 *
 * @class WC_Pay_Crypto_Me_Payments
 */
class WC_Pay_Crypto_Me_Payments
{
  public static function init()
  {
    add_action('plugins_loaded', array(__CLASS__, 'includes'), 0);
    add_filter('woocommerce_payment_gateways', array(__CLASS__, 'add_gateway'));
    add_action('woocommerce_blocks_loaded', array(__CLASS__, 'woocommerce_pay_crypto_me_woocommerce_block_support'));
  }

  public static function includes()
  {
    if (class_exists('WC_Payment_Gateway')) {
      require_once 'includes/wc-gateway-pay-crypto-me.php';
    }
  }

  public static function add_gateway($gateways)
  {
    $options = get_option('pay_crypto_me_woocommerce_settings', array());

    if (isset($options['hide_for_non_admin_users'])) {
      $hide_for_non_admin_users = $options['hide_for_non_admin_users'];
    } else {
      $hide_for_non_admin_users = 'no';
    }

    if (('yes' === $hide_for_non_admin_users && current_user_can('manage_options')) || 'no' === $hide_for_non_admin_users) {
      $gateways[] = 'WC_Gateway_PayCrypto_Me';
    }
    return $gateways;
  }

  public static function plugin_url()
  {
    return untrailingslashit(plugins_url('/', __FILE__));
  }

  public static function plugin_abspath()
  {
    return trailingslashit(plugin_dir_path(__FILE__));
  }

  public static function woocommerce_pay_crypto_me_woocommerce_block_support()
  {
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
      require_once 'includes/blocks/wc-pay-crypto-me-payments-blocks.php';
      add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
          $payment_method_registry->register(new WC_Gateway_PayCrypto_Me_Blocks_Support());
        }
      );
    }
  }
}

WC_Pay_Crypto_Me_Payments::init();