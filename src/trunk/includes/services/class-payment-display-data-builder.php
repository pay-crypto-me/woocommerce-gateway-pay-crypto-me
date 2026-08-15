<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PaymentDisplayDataBuilder
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PaymentDisplayDataBuilder
{
    private QrCodeService $qr_code_service;

    public function __construct(QrCodeService $qr_code_service)
    {
        $this->qr_code_service = $qr_code_service;
    }

    /**
     * Normalizes the order-details display array shared by both gateways.
     *
     * $args carries the gateway-specific values: payment_identifier, payment_uri,
     * logo_path, crypto_network, network_label, crypto_amount, crypto_currency,
     * confirmations_required. Optional qr_logo_options is forwarded verbatim to
     * QrCodeService (e.g. a 'border' config to draw a ring behind the QR logo).
     *
     * $logger is forwarded to QrCodeService so a failed QR (missing gd/iconv/fileinfo) is
     * reported instead of silently rendering an order page without one.
     */
    public function build(\WC_Order $order, array $args, ?callable $logger = null): array
    {
        // Gateways whose expiry isn't actually enforced (on-chain) opt out via show_expiry;
        // defaults to true so the enforced Lightning countdown still renders.
        $show_expiry          = $args['show_expiry'] ?? true;
        $expires_hours        = (int) $order->get_meta('_paycrypto_me_payment_expires_at');
        $order_date           = $order->get_date_created();
        $expires_at_timestamp = $show_expiry
            ? $this->resolve_expiry_timestamp($order, $expires_hours, $order_date)
            : null;
        $expires_at_formatted = $expires_at_timestamp === null
            ? null
            : wp_date(get_option('date_format') . ' ' . get_option('time_format'), $expires_at_timestamp);

        // Computed locally from the order's own expiry meta — no node round-trip and no status
        // tracking involved (that is add-on scope). Only gateways whose expiry is actually
        // enforced opt in via show_expiry, so an on-chain address is never called expired.
        $is_expired = $expires_at_timestamp !== null && $expires_at_timestamp <= time();

        return [
            'payment_identifier'     => $args['payment_identifier'],
            'payment_uri'            => $args['payment_uri'],
            'payment_qr_code'        => $this->qr_code_service->generate_qr_code_data_uri($args['payment_uri'], $args['logo_path'], $args['qr_logo_options'] ?? [], $logger),
            'fiat_amount'            => $order->get_meta('_paycrypto_me_fiat_amount'),
            'fiat_currency'          => $order->get_meta('_paycrypto_me_fiat_currency'),
            'crypto_amount'          => $args['crypto_amount'],
            'crypto_currency'        => $args['crypto_currency'],
            'crypto_label'           => $this->crypto_label($args['crypto_currency']),
            'network_label'          => $args['network_label'],
            'crypto_network'         => $args['crypto_network'],
            'expires_at'             => $order->get_meta('_paycrypto_me_payment_expires_at'),
            'expires_at_formatted'   => $expires_at_formatted,
            'is_expired'             => $is_expired,
            'confirmations_required' => $args['confirmations_required'],
        ];
    }

    /**
     * Prefers the absolute expiry a gateway recorded (Lightning writes it as
     * `_paycrypto_me_payment_expires_ts`, from the invoice the node will actually honour).
     *
     * The hours fallback is anchored to the order's creation date, which only holds while the
     * payment request was created with the order: an invoice reused on a checkout retry has its
     * remaining hours counted from that retry, so the same number resolves to a moment already in
     * the past. Kept as a fallback for orders paid before the absolute value was written.
     */
    private function resolve_expiry_timestamp(\WC_Order $order, int $expires_hours, $order_date): ?int
    {
        $absolute = (int) $order->get_meta('_paycrypto_me_payment_expires_ts');

        if ($absolute > 0) {
            return $absolute;
        }

        if ($expires_hours > 0 && $order_date) {
            return $order_date->getTimestamp() + $expires_hours * HOUR_IN_SECONDS;
        }

        return null;
    }

    private function crypto_label($crypto_currency): string
    {
        // Bitcoin-only plugin; the map exists so an unknown code degrades to itself.
        $names = ['BTC' => 'Bitcoin'];

        return $names[$crypto_currency] ?? (string) $crypto_currency;
    }
}
