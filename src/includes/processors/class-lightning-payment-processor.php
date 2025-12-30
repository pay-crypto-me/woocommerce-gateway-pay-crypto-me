<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningPaymentProcessor
 * @extends     PaymentProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class LightningPaymentProcessor extends AbstractPaymentProcessor
{
    public function process(\WC_Order $order, array $payment_data): array
    {
        $identifier = $this->gateway->get_option('network_identifier');

        if (empty($identifier)) {
            throw new PayCryptoMeException(__('Lightning identifier is not configured in the payment gateway settings.', 'woocommerce-gateway-paycrypto-me'));
        }

        //TODO: implement generate lightning invoice based on identifier and order details

        return $payment_data;
    }

    public function process_refund($order, $amount, $reason, $gateway): bool
    {
        // Implement Bitcoin-specific refund processing logic here
    }
}