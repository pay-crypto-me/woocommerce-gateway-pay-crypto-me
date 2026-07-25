document.addEventListener('DOMContentLoaded', function () {
    selected_network_renderer();

    copy_btc_admin_button_renderer();

    reset_derivation_index_button_renderer();

    lightning_fields_renderer();

    express_payment_description_renderer();
});

function selected_network_renderer() {
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
}

function copy_btc_admin_button_renderer() {
    var btn = document.getElementById('copy-btc-admin');
    if (btn) {
        btn.addEventListener('click', function () {
            var address = document.getElementById('btc-address-admin').textContent;
            navigator.clipboard.writeText(address).then(function () {
                alert('Address copied!');
            });
        });
    }
}

function reset_derivation_index_button_renderer() {
    var resetBtn = document.getElementById('paycrypto-me-reset-derivation-index');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (confirm('Are you sure you want to reset the payment address derivation index? This action cannot be undone.')) {
                fetch(window.PayCryptoMeAdminData.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        action: 'paycryptome_reset_derivation_index',
                        security: window.PayCryptoMeAdminData.nonce
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Derivation index has been reset successfully.');
                        } else {
                            alert('Error: ' + data.data);
                        }
                    })
                    .catch(error => {
                        alert('An unexpected error occurred.');
                        console.error('Error:', error);
                    });
            }
        });
    }
}

function lightning_fields_renderer() {
    if (!window.PayCryptoMeLightningData) return;

    var data = window.PayCryptoMeLightningData;
    var radios = document.getElementsByName(data.nodeFieldName);

    function toggleRows() {
        var sel = document.querySelector("input[name='" + data.nodeFieldName + "']:checked");
        var selectedValue = sel ? sel.value : '';

        var showBtcpay = selectedValue === 'btcpay';
        document.querySelectorAll('.paycrypto-btcpay-field').forEach(function (el) {
            var tr = el.closest('tr');
            if (!tr) return;
            if (showBtcpay) {
                tr.classList.remove('hidden');
            } else {
                tr.classList.add('hidden');
            }
        });

        var showLnd = selectedValue === 'lnd_rest';
        document.querySelectorAll('.paycrypto-lnd-field').forEach(function (el) {
            var tr = el.closest('tr');
            if (!tr) return;
            if (showLnd) {
                tr.classList.remove('hidden');
            } else {
                tr.classList.add('hidden');
            }
        });
    }

    if (radios.length) {
        Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', toggleRows); });
        toggleRows();
    }

    var testBtn = document.getElementById('paycrypto-btcpay-test');
    if (testBtn) {
        testBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var btn = this;
            var result = document.getElementById('paycrypto-btcpay-test-result');
            result.textContent = '';
            btn.disabled = true;
            btn.textContent = 'Testing...';
            var postData = {
                action: 'paycrypto_test_btcpay_connection',
                security: data.btcpayNonce,
                btcpay_url: document.querySelector('[name="' + data.btcpayUrlName + '"]') ? document.querySelector('[name="' + data.btcpayUrlName + '"]').value : '',
                btcpay_api_key: document.querySelector('[name="' + data.btcpayApiName + '"]') ? document.querySelector('[name="' + data.btcpayApiName + '"]').value : '',
                btcpay_store_id: document.querySelector('[name="' + data.btcpayStoreName + '"]') ? document.querySelector('[name="' + data.btcpayStoreName + '"]').value : ''
            };

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onload = function () {
                btn.disabled = false;
                btn.textContent = 'Test connection';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        result.style.color = 'green';
                        result.textContent = res.data.message || 'OK';
                    } else {
                        result.style.color = 'red';
                        result.textContent = (res.data && res.data.message) ? res.data.message : (res.data || 'Error');
                    }
                } catch (err) {
                    result.style.color = 'red';
                    result.textContent = 'Unexpected response';
                }
            };
            var params = Object.keys(postData).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(postData[k]); }).join('&');
            xhr.send(params);
        });
    }

    var lndTestBtn = document.getElementById('paycrypto-lnd-test');
    if (lndTestBtn) {
        lndTestBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var btn = this;
            var result = document.getElementById('paycrypto-lnd-test-result');
            result.textContent = '';
            btn.disabled = true;
            btn.textContent = 'Testing...';

            var lndVerifySsl = document.querySelector('[name="' + data.lndVerifySslName + '"]');
            var verifySslValue = lndVerifySsl && lndVerifySsl.checked ? 'yes' : 'no';

            var postData = {
                action: 'paycrypto_test_lnd_connection',
                security: data.lndNonce,
                lnd_rest_url: document.querySelector('[name="' + data.lndRestUrlName + '"]') ? document.querySelector('[name="' + data.lndRestUrlName + '"]').value : '',
                lnd_macaroon_hex: document.querySelector('[name="' + data.lndMacaroonName + '"]') ? document.querySelector('[name="' + data.lndMacaroonName + '"]').value : '',
                lnd_certificate: document.querySelector('[name="' + data.lndCertificateName + '"]') ? document.querySelector('[name="' + data.lndCertificateName + '"]').value : '',
                lnd_verify_ssl: verifySslValue
            };

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onload = function () {
                btn.disabled = false;
                btn.textContent = 'Test connection';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        result.style.color = 'green';
                        result.textContent = res.data.message || 'OK';
                    } else {
                        result.style.color = 'red';
                        result.textContent = (res.data && res.data.message) ? res.data.message : (res.data || 'Error');
                    }
                } catch (err) {
                    result.style.color = 'red';
                    result.textContent = 'Unexpected response';
                }
            };
            var params = Object.keys(postData).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(postData[k]); }).join('&');
            xhr.send(params);
        });
    }
}

function express_payment_description_renderer() {
    const expressPaymentEnabled = document.getElementsByName('woocommerce_paycrypto_me_lightning_enable_express_payment');

    if (!expressPaymentEnabled || expressPaymentEnabled.length === 0) return;

    const expressPaymentDescription = document.querySelector('[data-express_payment-text]');

    if (!expressPaymentDescription) return;

    const expressPaymentDescriptionTr = expressPaymentDescription.closest('tr');
    expressPaymentDescriptionTr.classList.add('hidden');

    const toggleExpressPaymentDescription = function (hidden) {
        if (hidden) {
            expressPaymentDescription.value = '';
            expressPaymentDescriptionTr.classList.add('hidden');
        } else {
            expressPaymentDescriptionTr.classList.remove('hidden');
        }
    }

    expressPaymentEnabled.forEach(function (el) {
        el.addEventListener('change', function () {
            toggleExpressPaymentDescription(!this.checked);
        });
    });

    toggleExpressPaymentDescription(!expressPaymentEnabled[0].checked);
}