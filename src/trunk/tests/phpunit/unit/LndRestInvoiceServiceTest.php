<?php

namespace PayCryptoMe\WooCommerce {
    // All service classes are loaded via Composer autoloader
}

namespace {

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\LndRestInvoiceService;
use PayCryptoMe\WooCommerce\PayCryptoMePaymentException;

// FakeHttpClient/http_ok()/http_error() live in tests/_support/fake-http-client.php
// (loaded by bootstrap.php before any test file).

class LndRestInvoiceServiceTest extends TestCase
{
    private function make_gateway(array $opts): \WC_Payment_Gateway
    {
        return new class($opts) extends \WC_Payment_Gateway {
            private array $opts;
            public function __construct(array $opts) { $this->opts = $opts; }
            public function get_option($key, $empty_value = null) { return $this->opts[$key] ?? $empty_value; }
        };
    }

    private function default_gateway(): \WC_Payment_Gateway
    {
        return $this->make_gateway([
            'lnd_rest_url'    => 'https://lnd.example.com',
            'lnd_macaroon_hex' => 'deadbeef',
            'lnd_certificate' => '',
            'lnd_verify_ssl'  => 'yes',
        ]);
    }

    public function test_create_invoice_returns_correct_payment_request_and_invoice_id(): void
    {
        // r_hash as URL-safe base64 of known bytes: 0xdeadbeef → base64url = '3q2-7w=='
        $r_hash_bytes = "\xde\xad\xbe\xef";
        $r_hash_b64   = rtrim(strtr(base64_encode($r_hash_bytes), '+/', '-_'), '=');
        $expected_id  = bin2hex($r_hash_bytes); // 'deadbeef'

        $http = FakeHttpClient::respondingToPost(http_ok([
            'payment_request' => 'lnbc1test',
            'r_hash'          => $r_hash_b64,
        ]));

        $service  = new LndRestInvoiceService($http, $this->default_gateway());
        $response = $service->create_invoice(['memo' => 'test order', 'expiry' => 3600]);

        $this->assertSame('lnbc1test', $response->payment_request);
        $this->assertSame($expected_id, $response->invoice_id);
    }

    public function test_create_invoice_includes_value_in_sats_when_set(): void
    {
        // Pro fiat→sats seam: when the invoice-args filter provides `value`, the lnd
        // invoice must request that amount (sats) so the payment is enforced, not zero-amount.
        $http = FakeHttpClient::respondingToPost(http_ok(['payment_request' => 'lnbc1', 'r_hash' => 'aGVsbG8']));

        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600, 'value' => 5000]);

        $this->assertSame('5000', $http->lastPostBody()['value']);
    }

    public function test_create_invoice_omits_value_when_not_set(): void
    {
        // Free path is unchanged: no `value` → zero-amount invoice (no value key in the body).
        $http = FakeHttpClient::respondingToPost(http_ok(['payment_request' => 'lnbc1', 'r_hash' => 'aGVsbG8']));

        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);

        $this->assertArrayNotHasKey('value', $http->lastPostBody());
    }

    public function test_r_hash_base64url_to_hex_conversion(): void
    {
        // Verify the conversion with a known value:
        // base64url("hello") = "aGVsbG8" → hex = 68656c6c6f
        $r_hash_b64  = 'aGVsbG8';
        $expected_id = bin2hex(base64_decode('aGVsbG8='));

        $http = FakeHttpClient::respondingToPost(http_ok([
            'payment_request' => 'lnbc1hello',
            'r_hash'          => $r_hash_b64,
        ]));

        $service  = new LndRestInvoiceService($http, $this->default_gateway());
        $response = $service->create_invoice(['memo' => 'test', 'expiry' => 600]);

        $this->assertSame($expected_id, $response->invoice_id);
    }

    public function test_get_invoice_status_settled_returns_paid_true(): void
    {
        $http = FakeHttpClient::respondingToGet(http_ok(['state' => 'SETTLED']));

        $result = (new LndRestInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('deadbeef');

        $this->assertTrue($result->paid);
        $this->assertSame('SETTLED', $result->status);
    }

    public function test_resolve_payment_request_returns_empty_string(): void
    {
        $result = (new LndRestInvoiceService(new FakeHttpClient(), $this->default_gateway()))
            ->resolve_payment_request('deadbeef');

        $this->assertSame('', $result);
    }

    public function test_create_invoice_empty_body_throws_exception(): void
    {
        $http = FakeHttpClient::respondingToPost(['response' => ['code' => 200, 'message' => 'OK'], 'body' => '']);

        $this->expectException(PayCryptoMePaymentException::class);
        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);
    }

    // --- HTTP error matrix -------------------------------------------------------------
    // parse_response() treats every status >= 400 identically; this data provider proves
    // that holds across representative codes, for both create_invoice() (POST) and
    // get_invoice_status() (GET).

    public static function http_error_status_provider(): array
    {
        return [
            'bad request'         => [400, 'Bad Request'],
            'forbidden'           => [403, 'Forbidden'],
            'not found'           => [404, 'Not Found'],
            'too many requests'   => [429, 'Too Many Requests'],
            'service unavailable' => [503, 'Service Unavailable'],
        ];
    }

    /** @dataProvider http_error_status_provider */
    public function test_create_invoice_throws_on_http_error_status(int $code, string $message): void
    {
        $http = FakeHttpClient::respondingToPost(http_error($code, $message));

        $this->expectException(PayCryptoMePaymentException::class);
        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);
    }

    /** @dataProvider http_error_status_provider */
    public function test_get_invoice_status_throws_on_http_error_status(int $code, string $message): void
    {
        $http = FakeHttpClient::respondingToGet(http_error($code, $message));

        $this->expectException(PayCryptoMePaymentException::class);
        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->get_invoice_status('deadbeef');
    }

    public function test_create_invoice_throws_on_malformed_json(): void
    {
        $http = FakeHttpClient::respondingToPost([
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '{not valid json',
        ]);

        $this->expectException(PayCryptoMePaymentException::class);
        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);
    }

    public function test_create_invoice_throws_on_timeout(): void
    {
        // WpHttpClient (the real HttpClientContract adapter) turns a WP_Error — e.g. a
        // cURL timeout — into an empty array; simulate that directly here since fakes
        // implement the contract, not wp_remote_post()/wp_remote_get().
        $http = FakeHttpClient::respondingToPost([]);

        $this->expectException(PayCryptoMePaymentException::class);
        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);
    }

    // --- TLS certificate handling (request_with_cert helper) ---------------------------
    // Characterizes the branch shared by create_invoice()/get_invoice_status() after the
    // temp-cert dance was factored into request_with_cert() (audit Fase 3+, DRY invoice services).

    private function gateway_with_cert(string $certificate): \WC_Payment_Gateway
    {
        return $this->make_gateway([
            'lnd_rest_url'     => 'https://lnd.example.com',
            'lnd_macaroon_hex' => 'deadbeef',
            'lnd_certificate'  => $certificate,
            'lnd_verify_ssl'   => 'yes',
        ]);
    }

    public function test_create_invoice_writes_certificate_to_temp_file_and_cleans_up(): void
    {
        $http = FakeHttpClient::respondingToPost(http_ok(['payment_request' => 'lnbc1', 'r_hash' => 'aGVsbG8']));

        (new LndRestInvoiceService($http, $this->gateway_with_cert('CERT-PEM-DATA')))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);

        $temp_cert = $http->lastPostArgs['sslcertificates'] ?? null;
        $this->assertNotNull($temp_cert, 'sslcertificates should be set when a certificate is configured');
        $this->assertArrayNotHasKey('sslverify', $http->lastPostArgs);
        $this->assertFileDoesNotExist($temp_cert, 'temp cert file must be removed after the request');
    }

    public function test_create_invoice_without_certificate_toggles_sslverify(): void
    {
        $http = FakeHttpClient::respondingToPost(http_ok(['payment_request' => 'lnbc1', 'r_hash' => 'aGVsbG8']));

        (new LndRestInvoiceService($http, $this->default_gateway()))
            ->create_invoice(['memo' => 'test', 'expiry' => 3600]);

        $this->assertTrue($http->lastPostArgs['sslverify']);
        $this->assertArrayNotHasKey('sslcertificates', $http->lastPostArgs);
    }

    public function test_get_invoice_status_cleans_up_certificate_on_http_error(): void
    {
        // Error path still runs parse_response() inside the try, so the finally must fire.
        $http = FakeHttpClient::respondingToGet(http_error(500, 'Server Error'));
        $service = new LndRestInvoiceService($http, $this->gateway_with_cert('CERT-PEM-DATA'));

        try {
            $service->get_invoice_status('deadbeef');
            $this->fail('expected PayCryptoMePaymentException');
        } catch (PayCryptoMePaymentException $e) {
            // expected
        }

        $temp_cert = $http->lastGetArgs['sslcertificates'] ?? null;
        $this->assertNotNull($temp_cert);
        $this->assertFileDoesNotExist($temp_cert, 'temp cert file must be removed even when the request fails');
    }
}

} // end global namespace
