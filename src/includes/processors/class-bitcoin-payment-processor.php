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

            $order_index = hexdec(substr(hash('sha256', $order->get_id() . $order->get_date_created()), 0, 8));

            $order_index %= 0x80000000;

            $payment_address = $this->bitcoin_address_service->generate_address_from_xPub($xPub, $order_index, $bitcoin_network);

            $payment_data['payment_address'] = $payment_address;
            $payment_data['payment_uri'] = ''; //TODO: implement generate bitcoin payment uri

        } catch (\Exception $e) {
            throw new PayCryptoMeException(__(\sprintf("Bitcoin Payment Processor: %s", $e->getMessage()), 'woocommerce-gateway-paycrypto-me'), 0, $e);
        }

        return $payment_data;
    }

    public function process_refund($order, $amount, $reason, $gateway): bool
    {
        // Implement Bitcoin-specific refund processing logic here
    }
}