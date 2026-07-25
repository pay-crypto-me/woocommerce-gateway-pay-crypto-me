<?php

namespace PayCryptoMe\WooCommerce {
    // All service classes are loaded via Composer autoloader
}

namespace {

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\LightningConnectionTester;

// FakeHttpClient/http_ok()/http_error() live in tests/_support/fake-http-client.php;
// WC_Payment_Gateway/current_user_can/check_ajax_referer/wp_send_json_* fallbacks
// live in tests/_support/wp-helpers.php (both loaded by bootstrap.php).

class LightningConnectionTesterTest extends TestCase
{
    protected function setUp(): void
    {
        global $TEST_CURRENT_USER_CAN, $TEST_CHECK_AJAX_REFERER;
        $TEST_CURRENT_USER_CAN = true;
        $TEST_CHECK_AJAX_REFERER = true;
        $_POST = [];
    }

    private function make_gateway(array $opts = []): \WC_Payment_Gateway
    {
        return new class ($opts) extends \WC_Payment_Gateway {
            public array $opts;
            public array $logs = [];
            public function __construct(array $opts)
            {
                $this->opts = $opts;
            }
            public function get_option($key, $empty_value = null)
            {
                return $this->opts[$key] ?? $empty_value;
            }
            public function register_paycrypto_me_log($message, $level = 'info')
            {
                $this->logs[] = [$message, $level];
            }
        };
    }

    private function expectJsonError(string $needle): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/^WP_JSON_ERROR:.*' . preg_quote($needle, '/') . '/');
    }

    private function expectJsonSuccess(string $needle): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/^WP_JSON_SUCCESS:.*' . preg_quote($needle, '/') . '/');
    }

    // --- permission -----------------------------------------------------------

    public function test_btcpay_permission_denied_short_circuits_before_http_call()
    {
        global $TEST_CURRENT_USER_CAN;
        $TEST_CURRENT_USER_CAN = false;

        $http = FakeHttpClient::respondingToGet(http_ok(['ok' => true]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonError('Permission denied.');
        $tester->test_btcpay_connection();
    }

    public function test_lnd_permission_denied_short_circuits_before_http_call()
    {
        global $TEST_CURRENT_USER_CAN;
        $TEST_CURRENT_USER_CAN = false;

        $http = FakeHttpClient::respondingToGet(http_ok(['ok' => true]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonError('Permission denied.');
        $tester->test_lnd_connection();
    }

    // --- btcpay -----------------------------------------------------------

    public function test_btcpay_missing_url_returns_error_without_http_call()
    {
        $http = FakeHttpClient::respondingToGet(http_ok(['ok' => true]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonError('BTCPay Server URL is required for test.');
        $tester->test_btcpay_connection();
    }

    public function test_btcpay_uses_unsaved_posted_values_instead_of_saved_options()
    {
        $_POST['btcpay_url'] = 'https://btcpay.example.com';
        $_POST['btcpay_api_key'] = 'sk_live_12345';
        $_POST['btcpay_store_id'] = 'store1';

        $http = FakeHttpClient::respondingToGet(http_ok(['id' => 'store1']));
        $gateway = $this->make_gateway(['btcpay_url' => 'https://saved-and-stale.example.com']);
        $tester = new LightningConnectionTester($http, $gateway);

        $this->expectJsonSuccess('Connection OK (HTTP 200)');
        try {
            $tester->test_btcpay_connection();
        } finally {
            $this->assertSame('https://btcpay.example.com/api/v1/stores/store1', $http->lastGetUrl);
        }
    }

    public function test_btcpay_success_uses_store_endpoint_and_auth_header_when_store_id_present()
    {
        $_POST['btcpay_url'] = 'https://btcpay.example.com';
        $_POST['btcpay_api_key'] = 'sk_live_12345';
        $_POST['btcpay_store_id'] = 'store1';

        $http = FakeHttpClient::respondingToGet(http_ok(['id' => 'store1']));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonSuccess('Connection OK (HTTP 200)');
        try {
            $tester->test_btcpay_connection();
        } finally {
            $this->assertSame('https://btcpay.example.com/api/v1/stores/store1', $http->lastGetUrl);
            $this->assertSame('token sk_live_12345', $http->lastGetArgs['headers']['Authorization']);
        }
    }

    public function test_btcpay_success_lists_stores_when_store_id_missing()
    {
        $_POST['btcpay_url'] = 'https://btcpay.example.com';

        $http = FakeHttpClient::respondingToGet(http_ok([]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonSuccess('Connection OK (HTTP 200)');
        try {
            $tester->test_btcpay_connection();
        } finally {
            $this->assertSame('https://btcpay.example.com/api/v1/stores', $http->lastGetUrl);
            $this->assertArrayNotHasKey('Authorization', $http->lastGetArgs['headers']);
        }
    }

    public function test_btcpay_http_error_logs_and_returns_trimmed_body_message()
    {
        $_POST['btcpay_url'] = 'https://btcpay.example.com';

        $http = FakeHttpClient::respondingToGet(http_error(404, 'Not Found', 'store not found'));
        $gateway = $this->make_gateway();
        $tester = new LightningConnectionTester($http, $gateway);

        $this->expectJsonError('Request failed (HTTP 404). store not found');
        try {
            $tester->test_btcpay_connection();
        } finally {
            $this->assertCount(1, $gateway->logs);
            $this->assertStringContainsString('BTCPay connection test failed: status=404', $gateway->logs[0][0]);
            $this->assertSame('error', $gateway->logs[0][1]);
        }
    }

    // --- lnd -----------------------------------------------------------

    public function test_lnd_missing_url_returns_error_without_http_call()
    {
        $http = FakeHttpClient::respondingToGet(http_ok(['ok' => true]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonError('lnd REST URL is required for test.');
        $tester->test_lnd_connection();
    }

    public function test_lnd_success_appends_node_alias_when_present()
    {
        $_POST['lnd_rest_url'] = 'https://lnd.example.com';
        $_POST['lnd_macaroon_hex'] = 'deadbeef';
        $_POST['lnd_verify_ssl'] = 'yes';

        $http = FakeHttpClient::respondingToGet(http_ok(['alias' => 'my-node']));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonSuccess('Connection OK (HTTP 200). - Node: my-node');
        try {
            $tester->test_lnd_connection();
        } finally {
            $this->assertSame('https://lnd.example.com/v1/getinfo', $http->lastGetUrl);
            $this->assertSame('deadbeef', $http->lastGetArgs['headers']['Grpc-Metadata-macaroon']);
        }
    }

    public function test_lnd_success_without_alias_has_plain_message()
    {
        $_POST['lnd_rest_url'] = 'https://lnd.example.com';

        $http = FakeHttpClient::respondingToGet(http_ok([]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('WP_JSON_SUCCESS:{"message":"Connection OK (HTTP 200)."}');
        $tester->test_lnd_connection();
    }

    public function test_lnd_verify_ssl_no_disables_ssl_verification_when_no_certificate()
    {
        $_POST['lnd_rest_url'] = 'https://lnd.example.com';
        $_POST['lnd_verify_ssl'] = 'no';

        $http = FakeHttpClient::respondingToGet(http_ok([]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonSuccess('Connection OK');
        try {
            $tester->test_lnd_connection();
        } finally {
            $this->assertFalse($http->lastGetArgs['sslverify']);
            $this->assertArrayNotHasKey('sslcertificates', $http->lastGetArgs);
        }
    }

    public function test_lnd_certificate_is_written_to_temp_file_and_cleaned_up_after_request()
    {
        $_POST['lnd_rest_url'] = 'https://lnd.example.com';
        $_POST['lnd_certificate'] = "-----BEGIN CERTIFICATE-----\nabc\n-----END CERTIFICATE-----";

        $http = FakeHttpClient::respondingToGet(http_ok([]));
        $tester = new LightningConnectionTester($http, $this->make_gateway());

        $this->expectJsonSuccess('Connection OK');
        try {
            $tester->test_lnd_connection();
        } finally {
            $this->assertArrayHasKey('sslcertificates', $http->lastGetArgs);
            $this->assertFileDoesNotExist($http->lastGetArgs['sslcertificates'], 'temp cert file must be removed after the request completes');
        }
    }

    public function test_lnd_http_error_logs_and_returns_trimmed_body_message()
    {
        $_POST['lnd_rest_url'] = 'https://lnd.example.com';

        $http = FakeHttpClient::respondingToGet(http_error(503, 'Unavailable', 'node syncing'));
        $gateway = $this->make_gateway();
        $tester = new LightningConnectionTester($http, $gateway);

        $this->expectJsonError('Request failed (HTTP 503). node syncing');
        try {
            $tester->test_lnd_connection();
        } finally {
            $this->assertCount(1, $gateway->logs);
            $this->assertStringContainsString('lnd REST connection test failed: status=503', $gateway->logs[0][0]);
        }
    }
}

}
