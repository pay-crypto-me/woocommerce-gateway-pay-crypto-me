(function () {
    document.querySelectorAll('.paycrypto-me-order-details__copy-address-button').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            // The template sets type="button"; this also covers a theme still shipping an older
            // override of it, where the default submit would save the surrounding admin order form.
            event.preventDefault();

            navigator.clipboard.writeText(btn.dataset.address).then(function () {
                btn.classList.add('paycrypto-me--copied');
                setTimeout(function () { btn.classList.remove('paycrypto-me--copied'); }, 2000);
            });
        });
    });
})();
