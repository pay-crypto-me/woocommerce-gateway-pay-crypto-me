<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BitcoinProcessorStrategiesFactory
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class BitcoinProcessorStrategiesFactory
{
    public static function create(\WC_Payment_Gateway $gateway): GatewayProcessorContract
    {
        $network = $gateway->get_option('selected_network');

        return match ($network) {
            'lightning' => new LightningPaymentProcessor($gateway),
            default => new BitcoinPaymentProcessor($gateway),
        };
    }
}
