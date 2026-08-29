<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       AbstractLightningProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

abstract class AbstractLightningProcessor extends AbstractPaymentProcessor
{
    private const RESOLVE_MAX_ATTEMPTS = 2;
    private const RESOLVE_DELAY_MS     = 750;

    protected LightningInvoiceServiceContract         $service;
    protected PayCryptoMeLightningDBStatementsService $db;

    abstract protected function invoice_args_filter(): string;
    abstract protected function node_type(): string;
    abstract protected function base_invoice_args(\WC_Order $order): array;

    final public function process(\WC_Order $order, array $payment_data): array
    {
        $order_id = $order->get_id();
        $existing = $this->db->get_by_order_id($order_id);

        $payment_data['crypto_network'] = "lightning:{$this->node_type()}";

        // WooCommerce reuses the same order across checkout retries and the order-pay endpoint.
        // Mirrors the on-chain resolve_derived_address() reuse branch: if the order already has
        // a still-valid invoice, return it instead of creating another one at the node — creating
        // a second invoice here would otherwise silently orphan the first (see replace_invoice()).
        if ($existing && !$this->invoice_expired($existing['expires_at'])) {
            $response = new LightningInvoiceResponse($existing['invoice_id'], $existing['payment_request'], $existing['status']);

            return $this->finalize_payment_data($payment_data, $response, $order, $this->expires_at_timestamp($existing['expires_at']));
        }

        $args = apply_filters(
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- concrete implementations return prefixed 'paycryptome_lightning_*_invoice_args'.
            $this->invoice_args_filter(),
            array_merge($this->base_invoice_args($order), [
                'order_id' => (string) $order_id,
                'memo'     => apply_filters('paycryptome_lightning_invoice_memo', '', $order, $this->gateway),
                'expiry'   => apply_filters(
                    'paycryptome_lightning_invoice_expiry',
                    absint($this->gateway->get_option('invoice_expiry', 3600)),
                    $order,
                    $this->gateway
                ),
            ]),
            $order,
            $this->gateway
        );

        $response = $this->service->create_invoice($args);

        if ($response->payment_request === '') {
            $response = $this->resolve_payment_request($response, $order);
        }

        $expiry_seconds = (int) ($args['expiry'] ?? 3600);
        $expires_ts     = time() + $expiry_seconds;
        $expires_at     = gmdate('Y-m-d H:i:s', $expires_ts);
        $amount_sats    = isset($args['amount_sats']) ? (int) $args['amount_sats'] : null;

        $persisted = $existing
            ? $this->db->replace_invoice($order_id, $this->node_type(), $response->invoice_id, $response->payment_request, $expires_at, $amount_sats, $existing['invoice_id'])
            : $this->db->insert_invoice($order_id, $this->node_type(), $response->invoice_id, $response->payment_request, $expires_at, $amount_sats);

        // Two near-simultaneous requests can both create an invoice before either persists it. The
        // unique order key serializes inserts; replace_invoice() compare-and-swaps the expired
        // invoice id. If this request lost either race, return the invoice actually on file.
        if (!$persisted) {
            $winner = $this->db->get_by_order_id($order_id);

            if ($winner) {
                $response = new LightningInvoiceResponse(
                    $winner['invoice_id'],
                    $winner['payment_request'],
                    $winner['status']
                );

                return $this->finalize_payment_data(
                    $payment_data,
                    $response,
                    $order,
                    $this->expires_at_timestamp($winner['expires_at'])
                );
            }
        }

        if (!$persisted) {
            throw new PayCryptoMePaymentException(
                \sprintf(
                    'Failed to persist Lightning invoice for order #%s (node_type=%s, invoice=%s)',
                    esc_html((string) $order_id),
                    esc_html($this->node_type()),
                    esc_html($response->invoice_id)
                ),
                esc_html__('We could not save your Lightning invoice. Please try again or contact the store.', 'paycrypto-me-for-woocommerce')
            );
        }

        // Align WC payment expiry with the actual Lightning invoice expiry.
        return $this->finalize_payment_data($payment_data, $response, $order, $expires_ts);
    }

    /**
     * payment_expires_at stays a whole-hour count for backward compatibility (it is already
     * written as order meta on live sites), but the display anchors those hours to the order's
     * creation date — and a reused invoice's remaining hours do not start there. So the absolute
     * expiry goes out alongside it: without it, revisiting a long-lived invoice (up to the 24h
     * maximum) an hour before it lapses recorded "1 hour", which the order page resolved to one
     * hour after the ORDER was created, i.e. long past — showing "Expired" and hiding the QR code
     * for an invoice the node would still have settled.
     */
    private function finalize_payment_data(array $payment_data, LightningInvoiceResponse $response, \WC_Order $order, int $expires_ts): array
    {
        $payment_data['payment_expires_at']   = (string) (int) ceil(max(0, $expires_ts - time()) / HOUR_IN_SECONDS);
        $payment_data['payment_expires_ts']   = $expires_ts;
        $payment_data['payment_request']      = $response->payment_request;
        $payment_data['lightning_invoice_id'] = $response->invoice_id;

        // Uniform URI for QR code generation — both gateways expose payment_uri.
        $payment_data['payment_uri'] = 'lightning:' . $response->payment_request;

        return apply_filters('paycryptome_lightning_payment_data', $payment_data, $response, $order, $this->gateway);
    }

    private function invoice_expired(string $expires_at): bool
    {
        return $this->expires_at_timestamp($expires_at) <= time();
    }

    private function expires_at_timestamp(string $expires_at): int
    {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $expires_at, new \DateTimeZone('UTC'));

        return $dt ? $dt->getTimestamp() : 0;
    }

    private function resolve_payment_request(LightningInvoiceResponse $response, \WC_Order $order): LightningInvoiceResponse
    {
        for ($attempt = 1; $attempt <= self::RESOLVE_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                usleep(self::RESOLVE_DELAY_MS * 1000);
            }

            $payment_request = $this->service->resolve_payment_request($response->invoice_id);

            if ($payment_request !== '') {
                if ($attempt > 1) {
                    $this->gateway->register_paycrypto_me_log(
                        \sprintf(
                            'Lightning payment_request resolved for invoice %s after %d attempt(s) (node_type=%s, order=%d)',
                            $response->invoice_id,
                            $attempt,
                            $this->node_type(),
                            $order->get_id()
                        ),
                        'info'
                    );
                }

                return new LightningInvoiceResponse(
                    $response->invoice_id,
                    $payment_request,
                    $response->status,
                    $response->checkout_link
                );
            }
        }

        throw new PayCryptoMePaymentException(
            \sprintf(
                'Lightning payment_request not resolved for invoice %s after %d attempts (node_type=%s, order=%d)',
                esc_html($response->invoice_id),
                esc_html((string) self::RESOLVE_MAX_ATTEMPTS),
                esc_html($this->node_type()),
                esc_html((string) $order->get_id())
            ),
            esc_html__('Your Lightning invoice is taking longer than expected to generate. Please try again in a moment.', 'paycrypto-me-for-woocommerce')
        );
    }
}
