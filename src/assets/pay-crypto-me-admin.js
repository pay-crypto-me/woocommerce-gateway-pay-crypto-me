document.addEventListener('DOMContentLoaded', function() {
    var networkSelect = document.querySelector('select[name="woocommerce_paycrypto_me_selected_network"]');
    var identifierLabel = document.querySelector('label[for="woocommerce_paycrypto_me_network_identifier"]');
    if (!networkSelect || !identifierLabel) return;

    // Labels por network
    var labels = {
        mainnet: 'Wallet xPub',
        testnet: 'Testnet xPub',
        lightning: 'Lightning Address'
    };

    function updateLabel() {
        var selected = networkSelect.value;
        identifierLabel.textContent = labels[selected] || 'Network Identifier';
    }

    networkSelect.addEventListener('change', updateLabel);
    updateLabel();

    //

    var btn = document.getElementById('copy-btc-admin');
    if (btn) {
        btn.addEventListener('click', function() {
            var address = document.getElementById('btc-address-admin').textContent;
            navigator.clipboard.writeText(address).then(function() {
                alert('Address copied!');
            });
        });
    }
});
