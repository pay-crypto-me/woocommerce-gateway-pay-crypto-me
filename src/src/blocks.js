/**
 * PayCrypto.Me WooCommerce Blocks Integration (ES6+ Version)
 * 
 * This version uses modern imports and needs to be compiled
 * Save as: src/blocks.js (source file)
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';

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

    const icon = settings.icon ? createElement('img', {
        src: settings.icon,
        alt: label,
        className: 'wc-paycrypto-me-icon',
        style: {
            width: '24px',
            height: '24px',
            marginRight: '8px',
            verticalAlign: 'middle'
        }
    }) : null;

    return createElement(PaymentMethodLabel, {
        text: label,
        icon: icon
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
    }
};

// Register the payment method with WooCommerce Blocks
registerPaymentMethod(PayCryptoMePaymentMethod);

// Debug logging (only in development - this will work when using build tools)
if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV === 'development') {
    console.log('PayCrypto.Me payment method registered:', PayCryptoMePaymentMethod);
    console.log('PayCrypto.Me settings:', settings);
}