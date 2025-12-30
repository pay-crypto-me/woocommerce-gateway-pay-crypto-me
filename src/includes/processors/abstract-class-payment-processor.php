<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PaymentProcessor
 * @implements  GatewayProcessorContract
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

abstract class AbstractPaymentProcessor implements GatewayProcessorContract
{
    protected \WC_Payment_Gateway $gateway;

    public function __construct(\WC_Payment_Gateway $gateway)
    {
        $this->gateway = $gateway;
    }
}
