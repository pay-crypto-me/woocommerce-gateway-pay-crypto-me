<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BitcoinPaymentProcessor;

// Minimal fallbacks for WordPress/WooCommerce classes in the test environment
if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway {
        public function get_option($k) { return null; }
        public function register_paycrypto_me_log($message, $level = 'info') { return null; }
    }
}
if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_id() { return 0; }
        public function get_billing_first_name() { return ''; }
    }
}

// Minimal WP function fallbacks used by the processor
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = null) { return 'TestSite'; }
}
if (!function_exists('get_option')) {
    function get_option($key) { return 'http://example.org'; }
}

class BitcoinPaymentProcessorTest extends TestCase
{
    private function setPrivateProperty(object $obj, string $name, $value): void
    {
        $rc = new \ReflectionObject($obj);
        while (!$rc->hasProperty($name) && $rc->getParentClass()) {
            $rc = $rc->getParentClass();
        }
        $prop = $rc->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    public function test_process_uses_existing_address()
    {
        $gateway = $this->createMock(\WC_Payment_Gateway::class);
        $gateway->method('get_option')->willReturnMap([
            ['network_identifier', 'xpub_fake'],
            ['selected_network', 'mainnet'],
        ]);

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(42);
        $order->method('get_billing_first_name')->willReturn('Alice');

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);
        $db->method('get_by_order_id')->with(42)->willReturn([
            'payment_address' => '1ExistingAddr',
            'derivation_index' => 5,
        ]);

        $btcSvc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        // ensure generate_address_from_xPub is not called
        $btcSvc->expects($this->never())->method('generate_address_from_xPub');
        $btcSvc->method('build_bitcoin_payment_uri')->willReturn('bitcoin:1ExistingAddr?amount=0.123');

        $processor = $this->getMockBuilder(BitcoinPaymentProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->setPrivateProperty($processor, 'gateway', $gateway);
        $this->setPrivateProperty($processor, 'db', $db);
        $this->setPrivateProperty($processor, 'bitcoin_address_service', $btcSvc);

        $input = ['crypto_amount' => 0.123];
        $out = $processor->process($order, $input);

        $this->assertArrayHasKey('payment_address', $out, 'processor output: ' . var_export($out, true));
        $this->assertEquals('1ExistingAddr', $out['payment_address']);
        $this->assertArrayHasKey('derivation_index', $out, 'processor output: ' . var_export($out, true));
        $this->assertEquals(5, $out['derivation_index']);
        $this->assertArrayHasKey('payment_uri', $out, 'processor output: ' . var_export($out, true));
    }

    public function test_process_generates_and_persists_when_missing()
    {
        $gateway = $this->createMock(\WC_Payment_Gateway::class);
        $gateway->method('get_option')->willReturnMap([
            ['network_identifier', 'xpub_fake'],
            ['selected_network', 'mainnet'],
        ]);
        // expect no error log when insert succeeds
        $gateway->expects($this->never())->method('register_paycrypto_me_log');

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(99);
        $order->method('get_billing_first_name')->willReturn('Bob');

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);
        $db->method('get_by_order_id')->with(99)->willReturn(null);
        $db->method('insert_address')->with(
            $this->equalTo(99),
            $this->equalTo('xpub_fake'),
            $this->equalTo('mainnet'),
            $this->isType('int'),
            $this->equalTo('1NewAddr')
        )->willReturn(true);

        $btcSvc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $btcSvc->method('generate_address_from_xPub')->with('xpub_fake', $this->isType('int'), $this->isInstanceOf(\BitWasp\Bitcoin\Network\NetworkInterface::class))->willReturn('1NewAddr');
        $btcSvc->method('build_bitcoin_payment_uri')->willReturn('bitcoin:1NewAddr?amount=0.123');

        $processor = $this->getMockBuilder(BitcoinPaymentProcessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->setPrivateProperty($processor, 'gateway', $gateway);
        $this->setPrivateProperty($processor, 'db', $db);
        $this->setPrivateProperty($processor, 'bitcoin_address_service', $btcSvc);

        $input = ['crypto_amount' => 0.123];
        $out = $processor->process($order, $input);

        $this->assertArrayHasKey('payment_address', $out, 'processor output: ' . var_export($out, true));
        $this->assertEquals('1NewAddr', $out['payment_address']);
        $this->assertArrayHasKey('payment_uri', $out, 'processor output: ' . var_export($out, true));
        $this->assertEquals('bitcoin:1NewAddr?amount=0.123', $out['payment_uri']);
        $this->assertArrayHasKey('derivation_index', $out, 'processor output: ' . var_export($out, true));
    }
}
