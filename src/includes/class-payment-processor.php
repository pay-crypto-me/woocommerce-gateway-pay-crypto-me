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
            $currency_data = $this->apply_filter_payment_data($order, $gateway, $final_amount);

            $this->validate_order($order, $currency_data, $gateway);

            $this->validate_gateway_config($gateway);

            $this->trigger_hook_before($order, $currency_data, $gateway);

            $result = $this->handle_payment_processor_strategy($order, $gateway, $currency_data);

            // Atualizar status da order e adicionar metadados
            $this->update_order_after_payment($order, $result, $gateway);

            // Hook depois do processamento
            $this->trigger_hook_after('paycrypto_me_after_payment', $order, $gateway, $result);

            // Retornar resultado do WooCommerce
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order, $result)
            );

        } catch (\Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            
            return array(
                'result' => 'failure',
                'redirect' => wc_get_checkout_url()
            );
        }
    }

    private function trigger_hook_before($order, $currency_data, $gateway)
    {
        do_action('paycrypto_me_before_payment', $order, $gateway, $currency_data);
    }

    private function trigger_hook_after($hook_name, $order, $gateway, $result)
    {
        do_action($hook_name, $order, $gateway, $result);
    }

    private function update_order_after_payment($order, $result, $gateway)
    {
        // Adicionar metadados de pagamento crypto
        if (isset($result['payment_address'])) {
            $order->add_meta_data('_paycrypto_me_payment_address', $result['payment_address'], true);
        }
        
        if (isset($result['crypto_amount'])) {
            $order->add_meta_data('_paycrypto_me_crypto_amount', $result['crypto_amount'], true);
        }
        
        if (isset($result['crypto_currency'])) {
            $order->add_meta_data('_paycrypto_me_crypto_currency', $result['crypto_currency'], true);
        }

        // Salvar metadados
        $order->save_meta_data();

        // Adicionar nota na order
        $order->add_order_note(
            sprintf(
                __('PayCrypto.Me payment initiated. Awaiting cryptocurrency payment confirmation.', 'woocommerce-gateway-paycrypto-me')
            )
        );

        // Definir status como pending payment
        $order->update_status('pending', __('Awaiting cryptocurrency payment', 'woocommerce-gateway-paycrypto-me'));
    }

    private function get_return_url($order, $result)
    {
        // Se há uma URL específica no resultado, usar ela
        if (isset($result['redirect_url'])) {
            return $result['redirect_url'];
        }

        // Senão, usar a URL padrão de thank you do WooCommerce
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
        $payment_receive_address = $gateway->get_option('network_identifier');

        if (isset($_POST['selected_crypto'])) {
            // Validação de segurança para checkout
            if (!wp_verify_nonce($_POST['woocommerce-process-checkout-nonce'] ?? '', 'woocommerce-process_checkout')) {
                throw new \InvalidArgumentException(__('Security check failed during checkout.', 'woocommerce-gateway-paycrypto-me'));
            }
            $selected_crypto = strtoupper(sanitize_text_field(wp_unslash($_POST['selected_crypto'])));
        }

        if (empty($selected_crypto)) {
            throw new \InvalidArgumentException(
                \sprintf(
                    __('Selected currency (%s) is unprocessable.', 'woocommerce-gateway-paycrypto-me'),
                    $selected_crypto
                )
            );
        }

        if (!$gateway->check_cryptocurrency_support($selected_crypto, $gateway->get_option('selected_network'))) {
            throw new \InvalidArgumentException(
                \sprintf(
                    __('Selected currency (%s) is not supported.', 'woocommerce-gateway-paycrypto-me'),
                    $selected_crypto
                )
            );
        }

        $final_data = apply_filters('paycrypto_me_payment_data', [
            'fiat_currency' => $fiat_currency,
            'crypto_currency' => $selected_crypto,
            'payment_amount' => $payment_amount,
            'payment_network' => $payment_network,
            'payment_expires_at' => $payment_expires_at,
            'payment_receive_address' => $payment_receive_address,
        ], $order->get_id());

        return $final_data;
    }

    private function validate_order($order, $currency_data, $gateway)
    {
        if (!$order) {
            throw new \InvalidArgumentException(__('Order not found.', 'woocommerce-gateway-paycrypto-me'));
        }

        if (!$order->needs_payment()) {
            throw new \InvalidArgumentException(__('Order does not require payment.', 'woocommerce-gateway-paycrypto-me'));
        }

        if ($currency_data['total'] <= 0) {
            throw new \InvalidArgumentException(__('Invalid payment amount.', 'woocommerce-gateway-paycrypto-me'));
        }

        if ($order->get_payment_method() !== $gateway->id) {
            throw new \InvalidArgumentException(__('Payment method mismatch.', 'woocommerce-gateway-paycrypto-me'));
        }

        if (!$order->get_currency()) {
            throw new \InvalidArgumentException(__('Order currency is not valid.', 'woocommerce-gateway-paycrypto-me'));
        }

        return true;
    }

    private function validate_gateway_config(\WC_Payment_Gateway $gateway)
    {
        if (!$gateway->is_available()) {
            throw new \Exception(__('Payment gateway is not enabled.', 'woocommerce-gateway-paycrypto-me'));
        }

        $selected_network = $gateway->get_option('selected_network');
        if (!$selected_network) {
            throw new \Exception(__('No network selected in gateway settings.', 'woocommerce-gateway-paycrypto-me'));
        }

        $network_identifier = $gateway->get_option('network_identifier');
        if (empty($network_identifier)) {
            throw new \Exception(__('Network identifier not configured.', 'woocommerce-gateway-paycrypto-me'));
        }

        return true;
    }

    private function handle_payment_processor_strategy(\WC_Order $order, \WC_Payment_Gateway $gateway, array $currency_data)
    {
        $gateway_id = $gateway->id;
        $processor = $this->get_processor_for_gateway($gateway_id);

        $processResult = $processor->process($order, $gateway, $currency_data);

        return $processResult;
    }

    private function get_processor_for_gateway($gateway_id)
    {
        $processor = ProcessorStrategiesFactory::create($gateway_id);
        return $processor;
    }

    public static function init_url_params()
    {
        // Registrar query vars no WordPress
        add_filter('query_vars', function($vars) {
            $vars[] = 'crypto';
            $vars[] = 'paycrypto_network';
            return $vars;
        });

        // Hook para salvar na session quando vem via URL
        add_action('template_redirect', function() {
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