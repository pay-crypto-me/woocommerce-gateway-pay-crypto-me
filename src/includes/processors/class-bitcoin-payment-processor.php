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

        try {
            $existing = $this->db->get_by_order_id((int) $order->get_id());

            if ($existing && !empty($existing['payment_address'])) {
                $payment_address = $existing['payment_address'];
                $payment_data['derivation_index'] = (int) $existing['derivation_index'];
            } else {

                $derivation_index = $this->generate_derivation_index((int) $order->get_id());

                $payment_address = $this->bitcoin_address_service->generate_address_from_xPub($xPub, $derivation_index, $bitcoin_network);

                $inserted = $this->db->insert_address((int) $order->get_id(), $xPub, $network, $derivation_index, $payment_address);
                if ($inserted === false) {
                    $this->gateway->register_paycrypto_me_log(
                        \sprintf(__('Failed to persist generated address for order #%s', 'woocommerce-gateway-paycrypto-me'), $order->get_id()),
                        'error'
                    );
                }

                $payment_data['derivation_index'] = $derivation_index;
            }

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
                sprintf(__('Bitcoin Payment Processor: %s', 'woocommerce-gateway-paycrypto-me'), $e->getMessage()),
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

    private function generate_derivation_index(int $order_id): int
    {
        $salt = get_option('siteurl');

        if (function_exists('wp_salt')) {
            $salt = wp_salt('auth');
        } elseif (defined('AUTH_SALT')) {
            $salt = AUTH_SALT;
        }

        $raw = hash_hmac('sha256', (string) $order_id, (string) $salt);
        $derivation_index = hexdec(substr($raw, 0, 8)) % 0x80000000;

        return (int) $derivation_index;
    }
}