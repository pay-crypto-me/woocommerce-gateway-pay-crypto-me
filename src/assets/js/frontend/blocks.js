/**
 * PayCrypto.Me WooCommerce Blocks Integration
 * 
 * Provides payment method integration for WooCommerce Blocks (Cart & Checkout blocks)
 * 
 * @package PayCrypto.Me
 * @version 0.1.0
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { __ } = window.wp.i18n;
const { createElement } = window.wp.element;

// Get payment method data from PHP
const settings = getSetting('paycrypto_me_data', {});
const defaultLabel = __('Cryptocurrency Payment', 'woocommerce-gateway-pay-crypto-me');

const label = decodeEntities(settings.title) || defaultLabel;

/**
 * Content component for the payment method
 * Displays the payment method description in the checkout
 */
const Content = () => {
    const description = decodeEntities(settings.description || '');

    if (!description) {
        return null;
    }

    return createElement('div', {
        className: 'wc-paycrypto-me-description',
        dangerouslySetInnerHTML: { __html: description }
    });
};

/**
 * Label component for the payment method
 * Displays the payment method name and icon
 */
const Label = ({ components }) => {
    const { PaymentMethodLabel } = components;

    const icon = settings.icon && settings.show_icon === 'yes' ? createElement('img', {
        className: 'wc-paycrypto-me-icon',
        src: settings.icon,
        alt: label,
    }) : null;

    return createElement(PaymentMethodLabel, {
        text: label,
        icon: icon,
        className: 'wc-paycrypto-me-label',
    });
};

/**
 * Arialabel for accessibility
 */
const ariaLabel = label;

/**
 * Check if payment method can be used
 */
const canMakePayment = () => {
    // Add any client-side validation logic here
    // For now, always return true if the method is available
    return true;
};

/**
 * PayCrypto.Me payment method configuration
 */
const PayCryptoMePaymentMethod = {
    name: 'paycrypto_me',
    label: createElement(Label),
    content: createElement(Content),
    edit: createElement(Content),
    canMakePayment,
    ariaLabel,
    supports: {
        features: settings.supports || ['products'],
        // Add more WooCommerce features as needed
        // showSavedCards: false,
        // showSaveOption: false,
    }
};

// Register the payment method with WooCommerce Blocks
registerPaymentMethod(PayCryptoMePaymentMethod);

// Debug logging (temporarily enabled for testing)
if (settings.enable_logging) {
    console.log('PayCrypto.Me payment method registered:', PayCryptoMePaymentMethod);
    console.log('PayCrypto.Me settings:', settings);
    console.log('Available WC objects:', {
        wcBlocksRegistry: !!window.wc?.wcBlocksRegistry,
        wcSettings: !!window.wc?.wcSettings,
        wpElement: !!window.wp?.element,
        wpI18n: !!window.wp?.i18n
    });
}
