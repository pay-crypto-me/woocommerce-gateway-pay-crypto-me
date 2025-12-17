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
    public static function create(string $gateway_id): GatewayProcessorContract
    {
        switch ($gateway_id) {
            case 'paycrypto_me': //TODO: paycrypto_me_bitcoin
                return new BitcoinPaymentProcessor();
            // case 'paycrypto_me_ethereum':
            //     return new EthereumProcessorStrategy();
            default:
                throw new \InvalidArgumentException(\sprintf("There isn't any processor strategy for gateway ID: %s", $gateway_id));
        }
    }
}
