<?php
if (!defined('ABSPATH')) {
    exit;
}

if ($paycrypto_me_payment_address): ?>
    <section class="wc-block-order-confirmation-billing-address paycrypto-me-order-details">
        <h3><?php esc_html_e('Payment Details', 'woocommerce-gateway-paycrypto-me'); ?></h3>

        <div class="paycrypto-me-order-details__container">
            <div class="paycrypto-me-order-details__wrapper">
                <small><?php esc_html_e('Fiat Amount:', 'woocommerce-gateway-paycrypto-me'); ?></small>
                <small><?php echo wp_kses_post( wc_price( $paycrypto_me_fiat_amount, array( 'currency' => $paycrypto_me_fiat_currency ) ) ); ?></small>
            </div>
            <?php
            if (!empty($paycrypto_me_crypto_amount)) {
                ?>
                <div class="paycrypto-me-order-details__wrapper">
                    <small>
                        <?php esc_html_e('Crypto Amount:', 'woocommerce-gateway-paycrypto-me'); ?>
                    </small>
                    <small><?php echo esc_html( number_format_i18n( (float) $paycrypto_me_crypto_amount, 8 ) . ' ' . $paycrypto_me_crypto_currency ); ?></small>
                </div>
                <?php
                }
            ?>
            <div class="paycrypto-me-order-details__wrapper">
                <small>
                    <?php esc_html_e('Crypto Network:', 'woocommerce-gateway-paycrypto-me'); ?>
                </small>
                <div class="paycrypto-me-network-switch">
                    <label class="paycrypto-me-network-<?php echo esc_attr($paycrypto_me_crypto_network); ?>-label">
                        <?php echo esc_html($paycrypto_me_crypto_network_label); ?>
                    </label>
                </div>
            </div>
            <div
                class="paycrypto-me-order-details__wrapper paycrypto-me-order-details__wrapper--qr-code"
                style="margin-top: 8px; justify-content: center; line-height: 1;">
                <small
                    style="font-weight: 700;"><?php esc_html_e('Scan QR Code to Pay:', 'woocommerce-gateway-paycrypto-me'); ?></small>
            </div>
            <div class="paycrypto-me-order-details__qr-code-image">
                <img src="<?php echo $paycrypto_me_payment_qr_code ?>"
                    alt="<?php esc_attr_e('QR Code for Payment', 'woocommerce-gateway-paycrypto-me'); ?>" />
            </div>
            <div class="paycrypto-me-order-details__wrapper paycrypto-me-order-details__wrapper--address">
                <small
                    class="paycrypto-me-order-details__address"><?php echo esc_html($paycrypto_me_payment_address); ?></small>
            </div>
            <button
                class="woocommerce-button wp-element-button paycrypto-me-order-details__button paycrypto-me-order-details__copy-address-button"
                data-address="<?php echo esc_attr($paycrypto_me_payment_address); ?>"
                onclick="window.navigator.clipboard.writeText(this.getAttribute('data-address')) && alert('<?php esc_html_e('Payment address copied to clipboard.', 'woocommerce-gateway-paycrypto-me'); ?>');">
                <?php esc_html_e('Copy Payment Address', 'woocommerce-gateway-paycrypto-me'); ?>
            </button>
            <a class="woocommerce-button wp-element-button paycrypto-me-order-details__button paycrypto-me-order-details__open-wallet-button"
                class="woocommerce-button wp-element-button paycrypto-me-order-details__button paycrypto-me-order-details__open-wallet-button"
                href="<?php echo $paycrypto_me_payment_uri ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Open your Wallet App', 'woocommerce-gateway-paycrypto-me'); ?>
            </a>
        </div>

    </section>
<?php endif; ?>