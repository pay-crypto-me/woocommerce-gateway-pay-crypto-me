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

const settings = getSetting('paycrypto_me_data', {});
const defaultLabel = __('Cryptocurrency Payment', 'woocommerce-gateway-pay-crypto-me');

const label = decodeEntities(settings.title) || defaultLabel;

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

const Label = ({ components }) => {
    const { PaymentMethodLabel } = components;

    const icon = settings.icon ? createElement('img', {
        src: settings.icon,
        alt: label,
        className: 'wc-paycrypto-me-icon',
    }) : null;

    return createElement(PaymentMethodLabel, {
        text: label,
        icon: icon
    });
};

const ariaLabel = label;

const canMakePayment = () => {
    return true;
};

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

registerPaymentMethod(PayCryptoMePaymentMethod);

if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV === 'development') {
    console.log('PayCrypto.Me payment method registered:', PayCryptoMePaymentMethod);
    console.log('PayCrypto.Me settings:', settings);
}