<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PaymentProcessor
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

class PaymentProcessor
{
    private PaymentOrderValidator $validator;

    public function __construct()
    {
        $this->validator = new PaymentOrderValidator();
    }

    public function process_payment($order_id, \WC_Payment_Gateway $gateway): array
    {
        try {
            $order = wc_get_order($order_id);
            $final_amount = $this->apply_filter_payment_amount($order, $gateway);
            $payment_data = $this->apply_filter_payment_data($order, $gateway, $final_amount);

            $this->validator->validate_order($order, $payment_data, $gateway);

            $this->validator->validate_gateway_config($gateway);

            $this->trigger_hook_before($order, $payment_data, $gateway);

            $payment_data = $this->handle_payment_processor_strategy($order, $payment_data, $gateway);

            $this->update_order_after_payment($order, $payment_data);

            $this->trigger_hook_after($order, $payment_data, $gateway);

            $this->register_payment_log($order, $payment_data, $gateway);

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order, $payment_data)
            );

        } catch (\Throwable $e) {

            $e = PayCryptoMePaymentException::convertToMyself($e);

            wc_add_notice($e->getUserFriendlyMessage(), 'error');

                $gateway->register_paycrypto_me_log(
                    \sprintf(
                        'PayCrypto.Me error for order #%s: %s',
                        intval( $order_id ),
                        esc_html( wp_strip_all_tags( $e->getMessage() ) )
                    ),
                    'error'
                );

            return [
                'result' => 'failure',
                'redirect' => wc_get_checkout_url()
            ];
        }
    }

    private function trigger_hook_before(\WC_Order $order, array $payment_data, \WC_Payment_Gateway $gateway)
    {
        do_action('paycryptome_before_payment', $order, $gateway, $payment_data);
    }

    private function trigger_hook_after(\WC_Order $order, array $payment_data, \WC_Payment_Gateway $gateway)
    {
        do_action('paycryptome_after_payment', $order, $gateway, $payment_data);
    }

    private function register_payment_log(\WC_Order $order, array $payment_data, \WC_Payment_Gateway $gateway)
    {
        $order_id = $order->get_id();

        $raw_addr = $payment_data['payment_address'] ?? $payment_data['payment_request'] ?? 'N-A';
        $masked_addr = \strlen((string) $raw_addr) > 10
            ? substr((string) $raw_addr, 0, 6) . '...' . substr((string) $raw_addr, -4)
            : (string) $raw_addr;

        $meta_data = [
            'crypto_currency'  => $payment_data['crypto_currency'] ?? 'N-A',
            'crypto_network'   => $payment_data['crypto_network'] ?? 'N-A',
            'crypto_amount'    => $payment_data['crypto_amount'] ?? 'N-A',
            'fiat_currency'    => $payment_data['fiat_currency'] ?? 'N-A',
            'fiat_amount'      => $payment_data['fiat_amount'] ?? 'N-A',
            'payment_address'  => $masked_addr,
        ];

        $gateway->register_paycrypto_me_log(
                \sprintf(
                    'Payment process initiated for order #%s: %s',
                    intval( $order_id ),
                    esc_html( wp_json_encode( $meta_data ) )
                ),
                'info'
            );
    }

    private function update_order_after_payment(\WC_Order $order, array $payment_data)
    {
        foreach ($payment_data as $key => $value) {
            $order->add_meta_data("_paycrypto_me_{$key}", $value, true);
        }

        $order->save_meta_data();

        $order->add_order_note( 'PayCrypto.Me payment initiated. Awaiting cryptocurrency payment confirmation.' );

        $order->update_status( 'pending', 'Awaiting cryptocurrency payment' );
    }

    private function get_return_url($order, $result)
    {
        if (isset($result['redirect_url'])) {
            return $result['redirect_url'];
        }

        return $order->get_checkout_order_received_url();
    }
    private function apply_filter_payment_amount($order, \WC_Payment_Gateway $gateway)
    {
        $final_amount = apply_filters('paycryptome_payment_amount', $order->get_total(), $order->get_id(), $gateway);
        return $final_amount;
    }

    private function apply_filter_payment_data($order, $gateway, $modified_total = null)
    {
        $selected_crypto = null;

        $fiat_currency = $order->get_currency();
        $payment_amount = $modified_total ?? $order->get_total();
        $payment_expires_at = $gateway->get_option('payment_timeout_hours');

        if (isset($_POST['woocommerce-process-checkout-nonce'])) {
            $checkout_nonce = sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce']));
            if (!wp_verify_nonce($checkout_nonce, 'woocommerce-process_checkout')) {
                throw new PayCryptoMePaymentException('Security check failed during checkout.');
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the checkout nonce above is
        // verified when present; Blocks/Store API checkout uses its own REST nonce and doesn't post
        // this classic field, so the check can't be made unconditional without breaking that flow.
        if (!empty($_POST['paycrypto_me_crypto_currency'])) {
            $selected_crypto = strtoupper(sanitize_text_field(wp_unslash($_POST['paycrypto_me_crypto_currency'])));
        } else {
            // Express payment flows may not POST this field; fall back to the gateway default.
            $fallback = $gateway->get_available_cryptocurrencies()[0] ?? '';
            if (empty($fallback)) {
                throw new PayCryptoMePaymentException(
                    'Crypto currency wasn\'t received via payment.',
                    esc_html__('Selected payment method cannot be processed. Please try choosing another one.', 'paycrypto-me-for-woocommerce')
                );
            }
            $selected_crypto = strtoupper($fallback);
        }

        if (!$gateway->check_cryptocurrency_support($selected_crypto, null)) {
            throw new PayCryptoMePaymentException(
                \sprintf(
                    'Selected payment method (%s) is not supported for payment.',
                    esc_html( (string) $selected_crypto )
                ),
                esc_html__('Selected payment method is not supported. Please try choosing another one.', 'paycrypto-me-for-woocommerce')
            );
        }

        $payment_data = apply_filters('paycryptome_payment_data', [
            // Stays null in the free plugin. Third parties fill crypto_amount via this filter — it
            // flows into the on-chain BIP21 URI amount and is persisted as order meta; the display
            // can also be set via paycryptome_order_display_args. Fiat→crypto is add-on scope.
            'crypto_amount'      => null,
            'fiat_amount'        => $payment_amount,
            'fiat_currency'      => $fiat_currency,
            'payment_expires_at' => $payment_expires_at,
        ], $order->get_id(), $gateway);

        $payment_data['crypto_currency'] = $selected_crypto;

        return $payment_data;
    }

    private function handle_payment_processor_strategy(\WC_Order $order, array $payment_data, \WC_Payment_Gateway $gateway)
    {
        $processor = $this->get_processor_for_gateway($gateway);

        $payment_data = $processor->process($order, $payment_data);

        return $payment_data;
    }

    private function get_processor_for_gateway(\WC_Payment_Gateway $gateway)
    {
        $processor = ProcessorStrategiesFactory::create($gateway);

        return $processor;
    }
}