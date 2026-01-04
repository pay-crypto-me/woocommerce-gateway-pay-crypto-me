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
    private PayCryptoMeDBStatementsService $db;

    public function __construct(\WC_Payment_Gateway $gateway)
    {
        parent::__construct($gateway);
        $this->bitcoin_address_service = new BitcoinAddressService();
        $this->db = new PayCryptoMeDBStatementsService();
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

        if (!$this->bitcoin_address_service->validate_extended_pubkey($xPub, $bitcoin_network)) {
            throw new PayCryptoMeException(
                \sprintf(
                    __('Invalid Bitcoin extended public key configured: %s. Please provide a valid.', 'woocommerce-gateway-paycrypto-me'),
                    esc_html(substr($xPub, 0, 4) . '...' . substr($xPub, -3))
                )
            );
        }

        try {
            $existing = $this->db->get_by_order_id((int) $order->get_id());

            if ($existing && !empty($existing['payment_address'])) {
                $payment_address = $existing['payment_address'];
                $derivation_index = $existing['derivation_index'];
            } else {

                $derivation_index = $this->db->insert_derivation_index($xPub, $network);

                if ($derivation_index === false) {
                    throw new PayCryptoMeException(
                        \sprintf(__('Failed to generate derivation index for order #%s', 'woocommerce-gateway-paycrypto-me'), $order->get_id())
                    );
                }

                $payment_address = $this->bitcoin_address_service->generate_address_from_xPub($xPub, $derivation_index, $bitcoin_network);

                $inserted = $this->db->insert_address((int) $order->get_id(), $xPub, $network, $derivation_index, $payment_address);
                if ($inserted === false) {
                    $this->gateway->register_paycrypto_me_log(
                        \sprintf(__('Failed to persist generated address for order #%s', 'woocommerce-gateway-paycrypto-me'), $order->get_id()),
                        'error'
                    );
                }
            }

            $payment_data['payment_address'] = $payment_address;
            $payment_data['derivation_index'] = $derivation_index;

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
                \sprintf(__('Bitcoin Payment Processor: %s', 'woocommerce-gateway-paycrypto-me'), $e->getMessage()),
                0,
                $e
            );
        }

        return $payment_data;
    }

    public function process_refund($order, $amount, $reason, $gateway): bool
    {
        //TODO: Implement refund process
    }
}