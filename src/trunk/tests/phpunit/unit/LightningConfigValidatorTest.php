<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\LightningConfigValidator;

// WC_Admin_Settings/esc_url_raw/wp_parse_url/wp_kses_post fallbacks live in
// tests/_support/wp-helpers.php (loaded by bootstrap.php before any test file).
//
// This tests the extracted validator directly — no WC_Payment_Gateway mock needed
// (audit Fase 3+, LightningConfigValidator). The gateway's validate_*_field() stubs
// keep delegating here; those paths are covered by WCGatewayLightningValidationTest.

class LightningConfigValidatorTest extends TestCase
{
    private LightningConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LightningConfigValidator();
        \WC_Admin_Settings::$errors = [];
    }

    private function errors(): array
    {
        return \WC_Admin_Settings::$errors;
    }

    // --- is_lnd_rest_selected ----------------------------------------------------

    public function test_is_lnd_rest_selected_defaults_to_btcpay_when_missing()
    {
        $this->assertFalse($this->validator->is_lnd_rest_selected([], 'node_type_key'));
    }

    public function test_is_lnd_rest_selected_true_when_lnd_rest()
    {
        $this->assertTrue($this->validator->is_lnd_rest_selected(['node_type_key' => 'lnd_rest'], 'node_type_key'));
    }

    public function test_is_lnd_rest_selected_false_when_btcpay()
    {
        $this->assertFalse($this->validator->is_lnd_rest_selected(['node_type_key' => 'btcpay'], 'node_type_key'));
    }

    // --- validate_btcpay_url -----------------------------------------------------

    public function test_btcpay_url_passes_https()
    {
        $this->assertSame('https://btcpay.example.com', $this->validator->validate_btcpay_url('https://btcpay.example.com', false));
        $this->assertEmpty($this->errors());
    }

    public function test_btcpay_url_rejects_non_https()
    {
        $this->assertSame('', $this->validator->validate_btcpay_url('http://btcpay.example.com', false));
        $this->assertNotEmpty($this->errors());
    }

    public function test_btcpay_url_skips_validation_when_lnd_rest_selected()
    {
        $this->assertSame('http://insecure', $this->validator->validate_btcpay_url('http://insecure', true));
        $this->assertEmpty($this->errors());
    }

    public function test_btcpay_url_null_returns_empty_string()
    {
        $this->assertSame('', $this->validator->validate_btcpay_url(null, false));
    }

    // --- validate_btcpay_api_key -------------------------------------------------

    public function test_btcpay_api_key_rejects_short_key()
    {
        $this->assertSame('', $this->validator->validate_btcpay_api_key('short', false));
        $this->assertNotEmpty($this->errors());
    }

    public function test_btcpay_api_key_accepts_long_key()
    {
        $key = str_repeat('a', 20);
        $this->assertSame($key, $this->validator->validate_btcpay_api_key($key, false));
        $this->assertEmpty($this->errors());
    }

    public function test_btcpay_api_key_skips_length_check_when_lnd_rest_selected()
    {
        $this->assertSame('short', $this->validator->validate_btcpay_api_key('short', true));
        $this->assertEmpty($this->errors());
    }

    // --- validate_btcpay_store_id / payment_method_id ----------------------------

    public function test_btcpay_store_id_sanitizes()
    {
        $this->assertSame('store123', $this->validator->validate_btcpay_store_id('  store123  '));
    }

    public function test_btcpay_payment_method_id_defaults_to_btc_ln_when_empty()
    {
        $this->assertSame('BTC-LN', $this->validator->validate_btcpay_payment_method_id(''));
    }

    public function test_btcpay_payment_method_id_keeps_custom_value()
    {
        $this->assertSame('BTC-LN-CUSTOM', $this->validator->validate_btcpay_payment_method_id('BTC-LN-CUSTOM'));
    }

    // --- validate_btcpay_webhook_secret ------------------------------------------

    public function test_btcpay_webhook_secret_warns_when_short_but_keeps_value()
    {
        $this->assertSame('shortsecret', $this->validator->validate_btcpay_webhook_secret('shortsecret', false));
        $this->assertNotEmpty($this->errors());
    }

    public function test_btcpay_webhook_secret_no_warning_when_lnd_rest_selected()
    {
        $this->assertSame('shortsecret', $this->validator->validate_btcpay_webhook_secret('shortsecret', true));
        $this->assertEmpty($this->errors());
    }

    // --- validate_lnd_rest_url ---------------------------------------------------

    public function test_lnd_rest_url_requires_https_when_lnd_selected()
    {
        $this->assertSame('', $this->validator->validate_lnd_rest_url('http://localhost:8080', true));
        $this->assertNotEmpty($this->errors());
    }

    public function test_lnd_rest_url_accepts_https_when_lnd_selected()
    {
        $this->assertSame('https://localhost:8080', $this->validator->validate_lnd_rest_url('https://localhost:8080', true));
        $this->assertEmpty($this->errors());
    }

    public function test_lnd_rest_url_skips_validation_when_btcpay_selected()
    {
        $this->assertSame('http://localhost:8080', $this->validator->validate_lnd_rest_url('http://localhost:8080', false));
        $this->assertEmpty($this->errors());
    }

    // --- validate_lnd_macaroon_hex -----------------------------------------------

    public function test_lnd_macaroon_rejects_short()
    {
        $this->assertSame('', $this->validator->validate_lnd_macaroon_hex('abcd', true));
        $this->assertNotEmpty($this->errors());
    }

    public function test_lnd_macaroon_rejects_non_hex()
    {
        $val = str_repeat('z', 100);
        $this->assertSame('', $this->validator->validate_lnd_macaroon_hex($val, true));
        $this->assertNotEmpty($this->errors());
    }

    public function test_lnd_macaroon_accepts_valid_hex_and_strips_whitespace()
    {
        $val = str_repeat('a', 100);
        $this->assertSame($val, $this->validator->validate_lnd_macaroon_hex($val . "  \n", true));
        $this->assertEmpty($this->errors());
    }

    public function test_lnd_macaroon_skips_validation_when_btcpay_selected()
    {
        $this->assertSame('abcd', $this->validator->validate_lnd_macaroon_hex('abcd', false));
        $this->assertEmpty($this->errors());
    }

    // --- validate_lnd_certificate ------------------------------------------------

    public function test_lnd_certificate_rejects_non_pem_when_lnd_selected()
    {
        $this->assertSame('', $this->validator->validate_lnd_certificate('not a cert', true));
        $this->assertNotEmpty($this->errors());
    }

    public function test_lnd_certificate_accepts_pem()
    {
        $pem = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
        $this->assertSame($pem, $this->validator->validate_lnd_certificate($pem, true));
        $this->assertEmpty($this->errors());
    }

    public function test_lnd_certificate_empty_returns_empty_without_error()
    {
        $this->assertSame('', $this->validator->validate_lnd_certificate('', true));
        $this->assertEmpty($this->errors());
    }

    // --- validate_invoice_expiry -------------------------------------------------

    public function test_invoice_expiry_below_minimum_resets_to_default()
    {
        $this->assertSame('3600', $this->validator->validate_invoice_expiry('100'));
        $this->assertNotEmpty($this->errors());
    }

    public function test_invoice_expiry_above_maximum_resets_to_default()
    {
        $this->assertSame('3600', $this->validator->validate_invoice_expiry('99999'));
        $this->assertNotEmpty($this->errors());
    }

    public function test_invoice_expiry_within_range_kept()
    {
        $this->assertSame('7200', $this->validator->validate_invoice_expiry('7200'));
        $this->assertEmpty($this->errors());
    }

    public function test_btcpay_url_that_cannot_be_stored_reports_an_error()
    {
        // Regression: esc_url_raw() rejecting the value returned '' with no error at all, so the
        // field was silently saved empty while WooCommerce still reported "settings saved".
        \WC_Admin_Settings::$errors = [];

        $result = (new LightningConfigValidator())->validate_btcpay_url('   ', false);

        $this->assertSame('', $result);
        $this->assertCount(1, \WC_Admin_Settings::$errors);
        $this->assertStringContainsString('not a usable URL', \WC_Admin_Settings::$errors[0]);
    }

    public function test_lnd_rest_url_that_cannot_be_stored_reports_an_error()
    {
        \WC_Admin_Settings::$errors = [];

        $result = (new LightningConfigValidator())->validate_lnd_rest_url('   ', true);

        $this->assertSame('', $result);
        $this->assertCount(1, \WC_Admin_Settings::$errors);
        $this->assertStringContainsString('not a usable URL', \WC_Admin_Settings::$errors[0]);
    }

    public function test_btcpay_url_is_not_validated_when_lnd_is_selected()
    {
        // The field belongs to the other node type, so an empty value is expected, not an error.
        \WC_Admin_Settings::$errors = [];

        $this->assertSame('', (new LightningConfigValidator())->validate_btcpay_url('', true));
        $this->assertSame([], \WC_Admin_Settings::$errors);
    }
}
