<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\WpHttpClient;

// WC_PayCryptoMe::log() is shimmed in tests/_support/paycryptome-shims.php.
// esc_url_raw() is shimmed in tests/_support/wp-helpers.php.

if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $message;
        public function __construct(string $code = '', string $message = '') { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args) {
        $GLOBALS['__wp_remote_post_calls'][] = [$url, $args];
        return $GLOBALS['__wp_remote_post_return'] ?? [];
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args) {
        $GLOBALS['__wp_remote_get_calls'][] = [$url, $args];
        return $GLOBALS['__wp_remote_get_return'] ?? [];
    }
}

class WpHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_remote_post_calls']  = [];
        $GLOBALS['__wp_remote_get_calls']   = [];
        $GLOBALS['__wp_remote_post_return'] = [];
        $GLOBALS['__wp_remote_get_return']  = [];
    }

    public function test_post_applies_default_timeout_when_not_specified()
    {
        // Regression test for H3: without an explicit timeout, WP's 5s default is too short
        // for a node behind Tor or a cold lnd/BTCPay instance.
        (new WpHttpClient())->post('https://example.com', ['headers' => ['X' => '1']]);

        $this->assertSame(15, $GLOBALS['__wp_remote_post_calls'][0][1]['timeout']);
    }

    public function test_get_applies_default_timeout_when_not_specified()
    {
        (new WpHttpClient())->get('https://example.com', []);

        $this->assertSame(15, $GLOBALS['__wp_remote_get_calls'][0][1]['timeout']);
    }

    public function test_post_lets_caller_override_the_default_timeout()
    {
        (new WpHttpClient())->post('https://example.com', ['timeout' => 30]);

        $this->assertSame(30, $GLOBALS['__wp_remote_post_calls'][0][1]['timeout']);
    }

    public function test_get_lets_caller_override_the_default_timeout()
    {
        (new WpHttpClient())->get('https://example.com', ['timeout' => 5]);

        $this->assertSame(5, $GLOBALS['__wp_remote_get_calls'][0][1]['timeout']);
    }

    public function test_post_returns_empty_array_on_wp_error_without_throwing()
    {
        $GLOBALS['__wp_remote_post_return'] = new WP_Error('http_request_failed', 'Connection timed out');

        $result = (new WpHttpClient())->post('https://example.com', []);

        $this->assertSame([], $result);
    }

    public function test_get_returns_the_raw_response_array_on_success()
    {
        $GLOBALS['__wp_remote_get_return'] = ['body' => '{"ok":true}', 'response' => ['code' => 200]];

        $result = (new WpHttpClient())->get('https://example.com', []);

        $this->assertSame(['body' => '{"ok":true}', 'response' => ['code' => 200]], $result);
    }
}
