<?php
// Minimal WP helper fallbacks for unit tests (global namespace)

// apply_filters()/do_action() stay no-ops (no real filter/action dispatch — deliberately
// out of scope), but every call is recorded so tests can assert a hook fired without a
// full WP hook system.
// Query via hook_spy_calls()/hook_spy_reset() below instead of poking the global.
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        $GLOBALS['__hook_spy'][] = ['type' => 'filter', 'tag' => $tag, 'args' => array_merge([$value], $args)];
        return $value;
    }
}
if (!function_exists('hook_spy_calls')) {
    function hook_spy_calls(?string $tag = null): array {
        $calls = $GLOBALS['__hook_spy'] ?? [];
        return $tag === null ? $calls : array_values(array_filter($calls, fn ($c) => $c['tag'] === $tag));
    }
}
if (!function_exists('hook_spy_reset')) {
    function hook_spy_reset(): void {
        $GLOBALS['__hook_spy'] = [];
    }
}
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) { return $text; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text) { return $text; }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('wp_trim_words')) {
    function wp_trim_words($text, $num_words = 55, $more = null) {
        $more = $more ?? '&hellip;';
        $words = preg_split('/[\n\r\t ]+/', trim(wp_strip_all_tags((string) $text)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) <= $num_words) {
            return implode(' ', $words);
        }
        return implode(' ', array_slice($words, 0, $num_words)) . $more;
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return $url; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return $text; }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') { return 'Test Blog'; }
}
if (!function_exists('get_option')) {
    function get_option($key) { return null; }
}
// Only the option/transient helpers that carry no read-back semantics for the code under test:
// delete_option() and the transients are pure writes here, recorded so tests can assert them.
// get_option() stays a null stub on purpose — making it stateful would change what every
// existing test sees.
if (!function_exists('delete_option')) {
    function delete_option($key) {
        $GLOBALS['__delete_option_calls'][] = $key;
        return true;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) {
        $GLOBALS['__transients'][$key] = $value;
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key) {
        return $GLOBALS['__transients'][$key] ?? false;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        unset($GLOBALS['__transients'][$key]);
        return true;
    }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null) { return gmdate((string) $format, $timestamp ?? 0); }
}
if (!function_exists('wp_delete_file')) {
    function wp_delete_file($file) { @unlink($file); }
}
if (!function_exists('absint')) {
    function absint($value) { return abs((int) $value); }
}
if (!function_exists('do_action')) {
    function do_action($tag, ...$args) {
        $GLOBALS['__hook_spy'][] = ['type' => 'action', 'tag' => $tag, 'args' => $args];
        return null;
    }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return true; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim((string) $str); }
}
if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $type = 'error') { return null; }
}
if (!function_exists('wc_get_checkout_url')) {
    function wc_get_checkout_url() { return 'https://example.org/checkout'; }
}
if (!function_exists('wc_price')) {
    function wc_price($amount, $args = []) {
        $currency = $args['currency'] ?? '';
        return trim($currency . ' ' . $amount);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('wc_get_template')) {
    // No-op: tests exercising render paths only assert on data/hooks, not template output.
    function wc_get_template($template_name, $args = [], $template_path = '', $default_path = '') { return null; }
}
// Test-controlled globals ($TEST_CURRENT_USER_CAN / $TEST_CHECK_AJAX_REFERER) let
// individual tests flip these without redeclaring the function.
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        global $TEST_CURRENT_USER_CAN;
        return isset($TEST_CURRENT_USER_CAN) ? (bool) $TEST_CURRENT_USER_CAN : true;
    }
}
// Admin code that only runs on certain screens (the unavailability notice, asset enqueue) reads
// this; tests place themselves on a screen with $TEST_CURRENT_SCREEN = (object) ['id' => '...'].
if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        global $TEST_CURRENT_SCREEN;
        return $TEST_CURRENT_SCREEN ?? null;
    }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $query_arg = false) {
        global $TEST_CHECK_AJAX_REFERER;
        return isset($TEST_CHECK_AJAX_REFERER) ? (bool) $TEST_CHECK_AJAX_REFERER : true;
    }
}
// Ajax handlers under test call these and expect execution to stop; tests catch
// the exception to assert on the payload instead of mocking wp_die()/exit.
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) { throw new \Exception('WP_JSON_SUCCESS:' . json_encode($data)); }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null) { throw new \Exception('WP_JSON_ERROR:' . json_encode($data)); }
}
// In-memory object-cache fake (keyed by "group:key") so tests can assert real
// caching/invalidation behavior instead of the calls being silent no-ops.
if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '') {
        return $GLOBALS['__wp_cache_store'][$group . ':' . $key] ?? false;
    }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        $GLOBALS['__wp_cache_store'][$group . ':' . $key] = $data;
        return true;
    }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        unset($GLOBALS['__wp_cache_store'][$group . ':' . $key]);
        return true;
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string) $url); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}
// Settings-field validators report errors here instead of wp_die()/redirecting;
// tests assert against WC_Admin_Settings::$errors and reset it between cases.
if (!class_exists('WC_Admin_Settings')) {
    class WC_Admin_Settings
    {
        public static $errors = [];
        public static function add_error($error) { self::$errors[] = $error; }
    }
}

// Basic stubs for WP constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// Single source of truth for WC_Payment_Gateway/WC_Order fallbacks — previously each
// test file declared its own guarded copy, and whichever file's autoloader position
// won the `class_exists()` race silently decided the arity every other file's mocks
// had to match (see BitcoinPaymentProcessorTest's get_option collision).
if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        public $id = '';
        public $plugin_id = 'woocommerce_';
        // Declared like WooCommerce does so tests whose gateway constructor is disabled can still
        // set/read it (the unavailability notice prints it) without a dynamic-property deprecation.
        public $method_title = '';
        // Declared like WooCommerce does: is_available() reads it, and an undeclared property
        // can't be set through reflection by tests that need a specific enabled state.
        public $enabled = 'no';
        public function get_option($key, $empty_value = null) { return $empty_value; }
        public function register_paycrypto_me_log($message, $level = 'info') { return null; }
        public function get_post_data() { return $_POST; }
        public function get_field_key($key) { return $this->plugin_id . $this->id . '_' . $key; }
    }
}
if (!class_exists('WC_Order')) {
    class WC_Order
    {
        public function get_id() { return 0; }
        public function get_order_number() { return $this->get_id(); }
        public function get_billing_first_name($context = 'view') { return ''; }
        public function get_total() { return '0'; }
        public function get_currency() { return 'USD'; }
        public function get_meta($key, $single = true, $context = 'view') { return ''; }
        public function get_date_created() { return null; }
        public function get_payment_method() { return ''; }
    }
}
