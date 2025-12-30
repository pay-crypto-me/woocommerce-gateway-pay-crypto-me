<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BitcoinPaymentProcessor
 * @extends     PaymentProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class BitcoinPaymentProcessor extends AbstractPaymentProcessor
{
    private BitcoinAddressService $bitcoin_address_service;

    public function __construct(\WC_Payment_Gateway $gateway)
    {
        parent::__construct($gateway);
        $this->bitcoin_address_service = new BitcoinAddressService();
    }

    public function process(\WC_Order $order, array $payment_data): array
    {
        $xPub = $this->gateway->get_option('network_identifier');
        $network = $this->gateway->get_option('selected_network');

        $bitcoin_network = $network === 'mainnet' ?
            \BitWasp\Bitcoin\Network\NetworkFactory::bitcoin() :
            \BitWasp\Bitcoin\Network\NetworkFactory::bitcoinTestnet();

        if (empty($xPub)) {
            throw new PayCryptoMeException(__('Bitcoin xPub is not configured in the payment gateway settings.', 'woocommerce-gateway-paycrypto-me'));
        }

        try {

            $order_index = hexdec(substr(hash('sha256', $order->get_id() . $order->get_date_created() . time()), 0, 8));

            $order_index %= 0x80000000;

            $payment_address = $this->bitcoin_address_service->generate_address_from_xPub($xPub, $order_index, $bitcoin_network);

            //TODO: Save the generated address and index to the database for future reference

            $payment_data['payment_address'] = $payment_address;

            $message = \sprintf(
                __('Payment sent to %s, Order Reference #%s', 'woocommerce-gateway-paycrypto-me'),
                get_bloginfo('name'),
                $order->get_id()
            );

            $payment_data['payment_uri'] = $this->bitcoin_address_service->build_bitcoin_payment_uri(
                message: $message,
                address: $payment_address,
                amount: $payment_data['crypto_amount'],
                label: $order->get_billing_first_name(),
            );

        } catch (\Exception $e) {
            throw new PayCryptoMeException(
                \sprintf(__("Bitcoin Payment Processor: %s", 'woocommerce-gateway-paycrypto-me'), $e->getMessage()),
                0,
                $e
            );
        }

        return $payment_data;
    }

    public function process_refund($order, $amount, $reason, $gateway): bool
    {
        // Implement Bitcoin-specific refund processing logic here
    }
}