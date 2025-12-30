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
    public function process_payment($order_id, \WC_Payment_Gateway $gateway): array
    {
        try {
            $order = wc_get_order($order_id);
            $final_amount = $this->apply_filter_payment_amount($order);
            $payment_data = $this->apply_filter_payment_data($order, $gateway, $final_amount);

            $this->validate_order($order, $payment_data, $gateway);

            $this->validate_gateway_config($gateway);

            $this->trigger_hook_before($order, $payment_data, $gateway);

            $payment_data = $this->handle_payment_processor_strategy($order, $payment_data, $gateway);

            $this->update_order_after_payment($order, $payment_data);

            $this->trigger_hook_after($order, $payment_data, $gateway);

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order, $payment_data)
            );

        } catch (\Exception $e) {

            $e = PayCryptoMePaymentException::convertToMyself($e);

            wc_add_notice($e->getUserFriendlyMessage(), 'error');

            $gateway->register_paycrypto_me_log(
                \sprintf(
                    __('PayCrypto.Me error for order #%s: %s', 'woocommerce-gateway-paycrypto-me'),
                    $order_id,
                    $e->getMessage()
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
        do_action('paycrypto_me_before_payment', $order, $gateway, $payment_data);
    }

    private function trigger_hook_after(\WC_Order $order, array $payment_data, \WC_Payment_Gateway $gateway)
    {
        do_action('paycrypto_me_after_payment', $order, $gateway, $payment_data);
    }

    private function update_order_after_payment(\WC_Order $order, array $payment_data)
    {
        foreach ($payment_data as $key => $value) {
            $order->add_meta_data("_paycrypto_me_{$key}", $value, true);
        }

        $order->save_meta_data();

        $order->add_order_note(__('PayCrypto.Me payment initiated. Awaiting cryptocurrency payment confirmation.', 'woocommerce-gateway-paycrypto-me'));

        $order->update_status('pending', __('Awaiting cryptocurrency payment', 'woocommerce-gateway-paycrypto-me'));
    }

    private function get_return_url($order, $result)
    {
        if (isset($result['redirect_url'])) {
            return $result['redirect_url'];
        }

        return $order->get_checkout_order_received_url();
    }
    private function apply_filter_payment_amount($order)
    {
        $final_amount = apply_filters('paycrypto_me_payment_amount', $order->get_total(), $order->get_id());
        return $final_amount;
    }

    private function apply_filter_payment_data($order, $gateway, $modified_total = null)
    {
        $selected_crypto = null;

        $fiat_currency = $order->get_currency();
        $payment_amount = $modified_total ?? $order->get_total();
        $payment_network = $gateway->get_option('selected_network');
        $payment_expires_at = $gateway->get_option('payment_timeout_hours');
        $payment_numbers_confirmations = $gateway->get_option('payment_number_confirmations');

        if (isset($_POST['woocommerce-process-checkout-nonce'])) {
            if (!wp_verify_nonce($_POST['woocommerce-process-checkout-nonce'], 'woocommerce-process_checkout')) {
                throw new PayCryptoMePaymentException(__('Security check failed during checkout.', 'woocommerce-gateway-paycrypto-me'));
            }
        }

        if (empty($_POST['paycrypto_me_crypto_currency'])) {
            throw new PayCryptoMePaymentException(
                __('Crypto currency wasn\'t received via payment.', 'woocommerce-gateway-paycrypto-me'),
                __('Selected payment method cannot be processed. Please try choosing another one.', 'woocommerce-gateway-paycrypto-me'),
            );
        }

        $selected_crypto = strtoupper(sanitize_text_field(wp_unslash($_POST['paycrypto_me_crypto_currency'])));

        if (!$gateway->check_cryptocurrency_support($selected_crypto, $gateway->get_option('selected_network'))) {
            throw new PayCryptoMePaymentException(
                \sprintf(
                    __('Selected payment method (%s) is not supported for payment.', 'woocommerce-gateway-paycrypto-me'),
                    $selected_crypto
                ),
                __('Selected payment method is not supported. Please try choosing another one.', 'woocommerce-gateway-paycrypto-me')
            );
        }

        $payment_data = apply_filters('paycrypto_me_payment_data', [
            'crypto_amount' => 0,
            'fiat_amount' => $payment_amount,
            'fiat_currency' => $fiat_currency,
            'payment_expires_at' => $payment_expires_at,
            'payment_number_confirmations' => $payment_numbers_confirmations,
        ], $order->get_id());

        $payment_data['crypto_network'] = $payment_network;
        $payment_data['crypto_currency'] = $selected_crypto;

        return $payment_data;
    }

    private function validate_order(\WC_Order $order, array $payment_data, \WC_Payment_Gateway $gateway)
    {
        if (!$order) {
            throw new \InvalidArgumentException(__('Order is unavailable.', 'woocommerce-gateway-paycrypto-me'));
        }

        if (!$order->needs_payment()) {
            throw new \InvalidArgumentException(
                \sprintf(
                    __('Order #%s does not require payment.', 'woocommerce-gateway-paycrypto-me'),
                    $order->get_id()
                )
            );
        }

        if ($payment_data['fiat_amount'] <= 0) {
            throw new \InvalidArgumentException(\sprintf(
                __('Order #%s total amount (%s) is not valid for payment.', 'woocommerce-gateway-paycrypto-me'),
                $order->get_id(),
                strip_tags(wc_price($payment_data['fiat_amount'], ['currency' => $order->get_currency()]))
            ));
        }

        if ($order->get_payment_method() !== $gateway->id) {
            throw new \InvalidArgumentException(__(
                \sprintf(
                    __('Payment method (%s) of order #%s is incompatible to payment gateway (%s).', 'woocommerce-gateway-paycrypto-me'),
                    $order->get_payment_method(),
                    $order->get_id(),
                    $gateway->id
                ),
                'woocommerce-gateway-paycrypto-me'
            ));
        }

        if (!$order->get_currency()) {
            throw new \InvalidArgumentException(
                \sprintf(
                    __('Order #%s currency (%s) is not valid for payment.', 'woocommerce-gateway-paycrypto-me'),
                    $order->get_id(),
                    $order->get_currency()
                )
            );
        }
    }

    private function validate_gateway_config(\WC_Payment_Gateway $gateway)
    {
        if (!$gateway->is_available()) {
            throw new PayCryptoMeException(__('Payment gateway is not available.', 'woocommerce-gateway-paycrypto-me'));
        }
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

    public static function init_url_params()
    {
        add_filter('query_vars', function ($vars) {
            $vars[] = 'crypto';
            $vars[] = 'paycrypto_network';
            return $vars;
        });

        add_action('template_redirect', function () {
            if (is_checkout()) {
                $crypto = get_query_var('crypto');
                $network = get_query_var('paycrypto_network');

                if (!empty($crypto) && WC()->session) {
                    WC()->session->set('paycrypto_me_selected_crypto', sanitize_text_field($crypto));
                }

                if (!empty($network) && WC()->session) {
                    WC()->session->set('paycrypto_me_selected_network', sanitize_text_field($network));
                }
            }
        });
    }

    public static function instance()
    {
        return new self();
    }
}