document.addEventListener('DOMContentLoaded', function () {
    var networkSelect = document.querySelector('select[name="woocommerce_paycrypto_me_selected_network"]');
    var identifierLabel = document.querySelector('label[for="woocommerce_paycrypto_me_network_identifier"]');
    var identifierInput = document.getElementById('woocommerce_paycrypto_me_network_identifier');

    if (!networkSelect || !identifierLabel || !identifierInput) return;

    function networkOnChange(...rest) {
        var selected = networkSelect.value;

        var networks = window.PayCryptoMeAdminData?.networks || {};

        var field_placeholder = networks[selected]?.field_placeholder || '';
        var field_label = networks[selected]?.field_label || 'Network Identifier';
        var field_type = networks[selected]?.field_type || 'text';

        identifierInput.placeholder = field_placeholder;
        identifierLabel.textContent = field_label;
        identifierInput.type = field_type;

        if (rest.length > 0) {
            identifierInput.value = '';
            identifierInput.focus();
        }
    }

    networkSelect.addEventListener('change', networkOnChange);
    networkOnChange();

    //

    var btn = document.getElementById('copy-btc-admin');
    if (btn) {
        btn.addEventListener('click', function () {
            var address = document.getElementById('btc-address-admin').textContent;
            navigator.clipboard.writeText(address).then(function () {
                alert('Address copied!');
            });
        });
    }
});
