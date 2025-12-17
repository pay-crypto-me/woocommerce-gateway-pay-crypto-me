<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BitcoinPaymentProcessor
 * @implements  GatewayProcessorContract
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class BitcoinPaymentProcessor implements GatewayProcessorContract
{
    public function process(\WC_Order $order, \WC_Payment_Gateway $gateway, array $currency_data): array
    {
        // Implement Bitcoin-specific payment processing logic here
    }

    public function process_refund($order, $amount, $reason, $gateway): bool
    {
        // Implement Bitcoin-specific refund processing logic here
    }
}