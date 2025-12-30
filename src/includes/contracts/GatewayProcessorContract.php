<?php

/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @interface   GatewayProcessorContract
 * @author      PayCrypto.Me
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

interface GatewayProcessorContract
{
    public function process(\WC_Order $order, array $payment_data): array;
}