<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       ProcessorStrategiesFactory
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class ProcessorStrategiesFactory
{
    public static function create(\WC_Payment_Gateway $gateway): GatewayProcessorContract
    {
        switch ($gateway->id) {
            case 'paycrypto_me': //TODO: paycrypto_me_bitcoin
                return BitcoinProcessorStrategiesFactory::create($gateway);
            default:
                throw new \InvalidArgumentException(\sprintf("There isn't any processor strategy for gateway ID: %s", $gateway->id));
        }
    }
}
