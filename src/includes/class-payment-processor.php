<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       WC_Gateway_PayCryptoMe
 * @extends     WC_Payment_Gateway
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
        // 1. Validações
        // 2. Hooks before
        // 3. Processar crypto payment
        // 4. Atualizar order
        // 5. Hooks after
        // 6. Return WC result
    }

    public function process_refund($order_id, $amount = null, $reason = '', \WC_Payment_Gateway $gateway): bool
    {
        // 1. Validações
        // 2. Hooks before
        // 3. Processar refund
        // 4. Atualizar order
        // 5. Hooks after
        // 6. Return success/fail
    }

    private function validate_order($order_id)
    {
    }
    private function get_processor_for_gateway($gateway_id)
    {
    }
    private function update_order_status($order, $result)
    {
    }
    public static function instance()
    {
        return new self();
    }
}