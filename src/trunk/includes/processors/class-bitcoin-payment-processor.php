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

    public function __construct(
        \WC_Payment_Gateway $gateway,
        ?BitcoinAddressService $bitcoin_address_service = null,
        ?PayCryptoMeDBStatementsService $db = null
    ) {
        parent::__construct($gateway);
        $this->bitcoin_address_service = $bitcoin_address_service ?? new BitcoinAddressService();
        $this->db = $db ?? new PayCryptoMeDBStatementsService();
    }

    public function process(\WC_Order $order, array $payment_data): array
    {
        $payment_data['payment_number_confirmations'] = (int) abs((int) $this->gateway->get_option('payment_number_confirmations', 0));
        $payment_data['crypto_network']               = (string) $this->gateway->get_option('selected_network', 'mainnet');

        $xPub = $this->gateway->get_option('network_identifier');
        // Same value that goes into the order meta and the wallet row, not a second read of the
        // option: read separately without the 'mainnet' default, an unset setting recorded the
        // order as mainnet while resolve_bitcoin_network() derived a testnet address for it.
        $network = $payment_data['crypto_network'];

        $bitcoin_network = $this->resolve_bitcoin_network($network);

        if (empty($xPub)) {
            throw new PayCryptoMeException('Bitcoin xPub is not configured in the payment gateway settings.');
        }

        // Accept either an extended public key (xpub/ypub/zpub/...) or a single
        // static Bitcoin address (bech32/legacy). If a static address is provided
        // treat it as the payment address for this order (no derivation).
        if ($this->bitcoin_address_service->validate_bitcoin_address($xPub, $bitcoin_network)) {
            $payment_data['payment_address'] = $xPub;
            $payment_data['payment_uri']     = $this->build_payment_uri($order, $xPub, $payment_data['crypto_amount']);

            return $this->finalize($payment_data, $order);
        }

        $xpub_logger = fn($message, $level) => $this->gateway->register_paycrypto_me_log($message, $level);

        if (!$this->bitcoin_address_service->validate_extended_pubkey($xPub, $bitcoin_network, $xpub_logger)) {
            throw new PayCryptoMeException(
                \sprintf(
                    'Invalid Bitcoin extended public key configured: %s. Please provide a valid.',
                    esc_html(substr($xPub, 0, 4) . '...' . substr($xPub, -3))
                )
            );
        }

        try {
            [$payment_address, $derivation_index] = $this->resolve_derived_address($order, $xPub, $network, $bitcoin_network);

            $payment_data['payment_address']  = $payment_address;
            $payment_data['derivation_index'] = $derivation_index;
            $payment_data['payment_uri']      = $this->build_payment_uri($order, $payment_address, $payment_data['crypto_amount']);
        } catch (\Throwable $e) {
            $clean = wp_strip_all_tags( $e->getMessage() );
            throw new PayCryptoMeException(
                \sprintf('Bitcoin Payment Processor: %s', esc_html( $clean )),
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous Throwable (arg 3), not output; the message above is already escaped.
                $e
            );
        }

        return $this->finalize($payment_data, $order);
    }

    /**
     * Single exit point for the on-chain payment_data — third-party seam mirroring
     * paycryptome_lightning_payment_data (add-on adjusts the final on-chain data here).
     */
    private function finalize(array $payment_data, \WC_Order $order): array
    {
        return apply_filters('paycryptome_bitcoin_payment_data', $payment_data, $order, $this->gateway);
    }

    private function resolve_bitcoin_network($network): \BitWasp\Bitcoin\Network\NetworkInterface
    {
        return $network === 'mainnet'
            ? \BitWasp\Bitcoin\Network\NetworkFactory::bitcoin()
            : \BitWasp\Bitcoin\Network\NetworkFactory::bitcoinTestnet();
    }

    /**
     * Returns [payment_address, derivation_index]: reuses the order's existing reservation
     * when present, otherwise reserves an index, derives an address and persists it.
     *
     * @throws PayCryptoMeException on xPub/address persistence failure
     */
    private function resolve_derived_address(\WC_Order $order, string $xPub, $network, $bitcoin_network): array
    {
        $existing = $this->db->get_by_order_id((int) $order->get_id());

        if ($existing && !empty($existing['payment_address'])) {
            return [$existing['payment_address'], $existing['derivation_index']];
        }

        if (!$wallet_xpub_id = $this->db->get_wallet_xpubkey_id($xPub, $network)) {
            $wallet_xpub_id = $this->db->insert_wallet_xpubkey($xPub, $network);
        }

        if (!$wallet_xpub_id) {
            throw new PayCryptoMeException(
                \sprintf('Failed to persist wallet xPub for order #%s', esc_html( (string) $order->get_id() ))
            );
        }

        $derivation_index = (int) $this->db->reserve_derivation_index_for_wallet((int) $wallet_xpub_id);

        // Derivation + persistence run in the same try/catch as the reservation above so that
        // ANY failure in between (missing GMP, invalid xpub, a write failure) releases the
        // index instead of burning it — see release_derivation_index() docblock.
        try {
            $gateway = $this->gateway;
            $payment_address = $this->bitcoin_address_service->generate_address_from_xPub(
                $xPub,
                $derivation_index,
                $bitcoin_network,
                null,
                static function (string $msg, string $level) use ($gateway): void {
                    $gateway->register_paycrypto_me_log($msg, $level);
                }
            );

            $inserted = $this->db->insert_address((int) $order->get_id(), $derivation_index, $payment_address, $wallet_xpub_id);

            if ($inserted === false) {
                throw new PayCryptoMeException(
                    \sprintf('Failed to persist generated address for order #%s', esc_html( (string) $order->get_id() ))
                );
            }
        } catch (\Throwable $e) {
            $this->db->release_derivation_index($wallet_xpub_id, $derivation_index);

            $this->gateway->register_paycrypto_me_log(
                \sprintf(
                    'Released derivation index %d for wallet #%d after failure for order #%s: %s',
                    $derivation_index,
                    $wallet_xpub_id,
                    $order->get_id(),
                    esc_html( wp_strip_all_tags( $e->getMessage() ) )
                ),
                'error'
            );

            throw $e;
        }

        return [$payment_address, $derivation_index];
    }

    private function build_payment_uri(\WC_Order $order, string $payment_address, $crypto_amount): string
    {
        $message = \sprintf(
            /* translators: 1: payment address, 2: order reference number. */
            __('Payment sent to %1$s, Order Reference #%2$s', 'paycrypto-me-for-woocommerce'),
            $payment_address,
            $order->get_order_number()
        );

        $uri = $this->bitcoin_address_service->build_bitcoin_payment_uri(
            message: $message,
            address: $payment_address,
            amount: $crypto_amount,
            label: $order->get_billing_first_name(),
        );

        return apply_filters('paycryptome_bitcoin_payment_uri', $uri, $order, $payment_address, $crypto_amount, $this->gateway);
    }
}