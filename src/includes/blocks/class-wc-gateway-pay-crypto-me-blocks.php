<?php

namespace PayCryptoMe\WooCommerce;

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_PayCryptoMe_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'paycrypto_me';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_paycrypto_me_settings', []);
    }

    public function get_payment_method_script_handles()
    {
        return [];
    }

    public function get_payment_method_data()
    {
        return [];
    }
}

add_action(
    'woocommerce_blocks_payment_method_type_registration',
    function ($payment_method_registry) {
        $payment_method_registry->register(
            new \PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Blocks()
        );
    }
);