<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\PaymentProcessor;
use PayCryptoMe\WooCommerce\PaymentOrderValidator;

// WC_Order/WC_Payment_Gateway/apply_filters/do_action/etc. fallbacks live in
// tests/_support/wp-helpers.php (loaded by bootstrap.php before any test file).

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id)
    {
        // Sentinel: order id 0 simulates wc_get_order() returning false for a
        // stale/invalid order id (WooCommerce's real return type when no order matches).
        if ($order_id === 0) {
            return false;
        }

        // $TEST_ORDER_PAYMENT_METHOD lets tests exercise the express payment-method
        // variant (`{gateway_id}_express`) without a second order stub.
        global $TEST_ORDER_PAYMENT_METHOD;

        $order = new class ($order_id, $TEST_ORDER_PAYMENT_METHOD ?? 'paycrypto_me') extends WC_Order {
            private $id;
            private $payment_method;
            public array $meta = [];
            public array $notes = [];
            public array $status_updates = [];
            public function __construct($id, $payment_method)
            {
                $this->id = $id;
                $this->payment_method = $payment_method;
            }
            public function get_total($context = 'view')
            {
                return 100.0;
            }
            public function get_id()
            {
                return $this->id;
            }
            public function get_currency($context = 'view')
            {
                return 'USD';
            }
            public function needs_payment()
            {
                return true;
            }
            public function get_payment_method($context = 'view')
            {
                return $this->payment_method;
            }
            public function add_meta_data($k, $v, $single = true)
            {
                $this->meta[$k] = $v;
            }
            public function save_meta_data()
            {
                return true;
            }
            public function add_order_note($note, $is_customer_note = 0, $added_by_user = false, $meta_data = array())
            {
                $this->notes[] = $note;
                return $note;
            }
            public function update_status($status, $note = '', $manual = false)
            {
                $this->status_updates[] = [$status, $note];
                return true;
            }
            public function get_checkout_order_received_url()
            {
                return 'https://example.org/received';
            }
        };

        // Lets tests retrieve the exact instance process_payment() operated on,
        // since PaymentProcessor calls this function internally.
        $GLOBALS['__test_last_order'] = $order;

        return $order;
    }
}

// process_payment() dispatches to the real PayCryptoMe\WooCommerce\ProcessorStrategiesFactory
// (it's a hardcoded static call inside PaymentProcessor, not injectable). Rather than
// class_alias-ing that FQCN to a local fake — which, tried once, turned out to leak
// process-wide: PHPUnit includes every test file during discovery, so the alias fired
// before any test ran and corrupted ProcessorStrategiesFactoryTest's view of the real
// class for the rest of the suite — end-to-end tests here go through the real Bitcoin
// strategy, configured with a static payment address so it needs no derivation/DB writes.
const TEST_STATIC_BITCOIN_ADDRESS = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';

class GatewayStub extends WC_Payment_Gateway
{
    public $id = 'paycrypto_me';
    public $logged = false;
    public $last_log_message = null;
    public $last_log_backtrace = null;
    public function get_option($k, $empty_value = null)
    {
        return match ($k) {
            'selected_network' => 'mainnet',
            'payment_timeout_hours' => 1,
            'payment_number_confirmations' => 1,
            'network_identifier' => TEST_STATIC_BITCOIN_ADDRESS,
            default => null,
        };
    }
    public function is_available()
    {
        return true;
    }
    public function register_paycrypto_me_log($message, $level = 'info')
    {
        $this->logged = true;
        $this->last_log_message = $message;
        $this->last_log_backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    }
    public function check_cryptocurrency_support($currency, $network)
    {
        return true;
    }
}

class PaymentProcessorTest extends TestCase
{
    public function test_process_payment_success_returns_success_and_redirect()
    {
        $gateway = new GatewayStub();

        // Set POST variables expected by apply_filter_payment_data
        $_POST['woocommerce-process-checkout-nonce'] = 'nonce';
        $_POST['paycrypto_me_crypto_currency'] = 'btc';

        // Step through internal methods via reflection to surface where failures occur
        $processor = new PaymentProcessor();
        $order = wc_get_order(123);

        try {
            $m1 = new \ReflectionMethod(PaymentProcessor::class, 'apply_filter_payment_amount');
            $m1->setAccessible(true);
            $final_amount = $m1->invoke($processor, $order, $gateway);

            $m2 = new \ReflectionMethod(PaymentProcessor::class, 'apply_filter_payment_data');
            $m2->setAccessible(true);
            $payment_data = $m2->invoke($processor, $order, $gateway, $final_amount);

            $validator = new PaymentOrderValidator();
            $validator->validate_order($order, $payment_data, $gateway);
            $validator->validate_gateway_config($gateway);
        } catch (\Throwable $e) {
            $this->fail('Failure during pre-check steps: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        // We won't execute the strategy factory (it constructs real processors with DB dependencies).
        // Instead assert the pre-check produced a valid payment_data array.
        $this->assertIsArray($payment_data);
        $this->assertArrayHasKey('fiat_amount', $payment_data);
        $this->assertArrayHasKey('fiat_currency', $payment_data);
        $this->assertArrayHasKey('payment_expires_at', $payment_data);

        // Strategy/integration tested separately in BitcoinPaymentProcessorTest

        // Assertions related to logging have been removed as we only assert pre-checks.
    }

    protected function setUp(): void
    {
        hook_spy_reset();
        $GLOBALS['__test_last_order'] = null;
        global $TEST_ORDER_PAYMENT_METHOD;
        $TEST_ORDER_PAYMENT_METHOD = null;
        $_POST = [
            'woocommerce-process-checkout-nonce' => 'nonce',
            'paycrypto_me_crypto_currency'       => 'btc',
        ];

        // BitcoinPaymentProcessor's PayCryptoMeDBStatementsService constructor reads
        // $wpdb->prefix even on the static-address path (no queries actually run there).
        if (!isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
            };
        }
    }

    public function test_process_payment_end_to_end_fires_hooks_updates_meta_and_returns_redirect()
    {
        $gateway = new GatewayStub();
        $processor = new PaymentProcessor();

        $result = $processor->process_payment(123, $gateway);

        $this->assertSame('success', $result['result']);
        $this->assertSame('https://example.org/received', $result['redirect'], 'no redirect_url in payment_data, so get_return_url() must fall back to the order URL');

        $before = hook_spy_calls('paycryptome_before_payment');
        $after = hook_spy_calls('paycryptome_after_payment');
        $this->assertCount(1, $before);
        $this->assertCount(1, $after);

        $order = $GLOBALS['__test_last_order'];
        [$before_order, $before_gateway, $before_payment_data] = $before[0]['args'];
        $this->assertSame($order, $before_order);
        $this->assertSame($gateway, $before_gateway);
        $this->assertArrayNotHasKey('payment_address', $before_payment_data, 'before-hook must fire ahead of the Bitcoin strategy adding payment_address');

        [, , $after_payment_data] = $after[0]['args'];
        $this->assertSame(TEST_STATIC_BITCOIN_ADDRESS, $after_payment_data['payment_address'], 'after-hook must see the strategy-populated payment_data');

        foreach (['fiat_amount', 'fiat_currency', 'payment_expires_at', 'crypto_currency', 'payment_address', 'payment_uri'] as $key) {
            $this->assertArrayHasKey("_paycrypto_me_{$key}", $order->meta, "Missing order meta for {$key}");
        }
        $this->assertSame(TEST_STATIC_BITCOIN_ADDRESS, $order->meta['_paycrypto_me_payment_address']);
        $this->assertStringStartsWith('bitcoin:', $order->meta['_paycrypto_me_payment_uri']);

        $this->assertNotEmpty($order->notes);
        $this->assertSame([['pending', 'Awaiting cryptocurrency payment']], $order->status_updates);
    }

    public function test_generic_filters_receive_gateway_for_third_party_branching()
    {
        $gateway = new GatewayStub();
        $processor = new PaymentProcessor();

        $processor->process_payment(123, $gateway);

        $amount_calls = hook_spy_calls('paycryptome_payment_amount');
        $this->assertCount(1, $amount_calls);
        // args = [value, order_id, gateway]
        $this->assertSame($gateway, $amount_calls[0]['args'][2], 'paycryptome_payment_amount must pass the gateway');

        $data_calls = hook_spy_calls('paycryptome_payment_data');
        $this->assertCount(1, $data_calls);
        // args = [payment_data, order_id, gateway]
        $this->assertSame($gateway, $data_calls[0]['args'][2], 'paycryptome_payment_data must pass the gateway');
    }

    public function test_process_payment_accepts_express_payment_method_variant()
    {
        global $TEST_ORDER_PAYMENT_METHOD;
        $TEST_ORDER_PAYMENT_METHOD = 'paycrypto_me_express';

        $gateway = new GatewayStub();
        $processor = new PaymentProcessor();

        $result = $processor->process_payment(123, $gateway);

        $this->assertSame('success', $result['result'], 'validate_order() must accept the {gateway_id}_express payment method');
    }

    public function test_process_payment_handles_wc_get_order_returning_false_without_fatal()
    {
        // Regression test for C5: wc_get_order() returning false used to fatal with
        // "Call to a member function get_total() on bool" — a \TypeError/\Error, not an
        // \Exception, so the old catch(\Exception) let it escape as an uncaught white screen.
        $gateway = new GatewayStub();
        $processor = new PaymentProcessor();

        $result = $processor->process_payment(0, $gateway);

        $this->assertSame('failure', $result['result']);
        $this->assertSame('https://example.org/checkout', $result['redirect']);
    }

    public function test_get_return_url_falls_back_to_checkout_received_url_without_redirect_key()
    {
        $processor = new PaymentProcessor();
        $order = wc_get_order(123);

        $m = new \ReflectionMethod(PaymentProcessor::class, 'get_return_url');
        $m->setAccessible(true);

        $this->assertSame('https://example.org/received', $m->invoke($processor, $order, ['crypto_amount' => 0.001]));
        $this->assertSame('https://example.org/redirect', $m->invoke($processor, $order, ['redirect_url' => 'https://example.org/redirect']));
    }
}
