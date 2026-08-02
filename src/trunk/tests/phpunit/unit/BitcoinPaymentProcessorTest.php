<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BitcoinPaymentProcessor;

// WC_Payment_Gateway/WC_Order/__/get_bloginfo/get_option fallbacks live in
// tests/_support/wp-helpers.php (loaded by bootstrap.php before any test file).
//
// Dependencies are now injected via the constructor (audit Fase 3+, DI nos processors):
// new BitcoinPaymentProcessor($gateway, $bitcoin_address_service, $db) — no more
// disableOriginalConstructor() + reflection to bypass hardcoded `new Service()`.

class BitcoinPaymentProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        hook_spy_reset();
    }

    public function test_process_uses_existing_address()
    {
        $gateway = $this->createMock(\WC_Payment_Gateway::class);
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty_value = null) => match ($key) {
            'network_identifier' => 'xpub_fake',
            'selected_network'   => 'mainnet',
            default              => $empty_value,
        });

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
        $btcSvc->method('validate_extended_pubkey')->willReturn(true);
        $btcSvc->method('build_bitcoin_payment_uri')->willReturn('bitcoin:1ExistingAddr?amount=0.123');

        $processor = new BitcoinPaymentProcessor($gateway, $btcSvc, $db);

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
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty_value = null) => match ($key) {
            'network_identifier' => 'xpub_fake',
            'selected_network'   => 'mainnet',
            default              => $empty_value,
        });
        // expect no error log when insert succeeds
        $gateway->expects($this->never())->method('register_paycrypto_me_log');

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(99);
        $order->method('get_billing_first_name')->willReturn('Bob');

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);
        $db->method('get_by_order_id')->with(99)->willReturn(null);
        $db->method('get_wallet_xpubkey_id')->willReturn(1);
        $db->method('insert_address')->with(
            $this->equalTo(99),
            $this->isType('int'),
            $this->equalTo('1NewAddr'),
            $this->equalTo(1)
        )->willReturn(true);

        $btcSvc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $btcSvc->method('generate_address_from_xPub')->with('xpub_fake', $this->isType('int'), $this->isInstanceOf(\BitWasp\Bitcoin\Network\NetworkInterface::class))->willReturn('1NewAddr');
        $btcSvc->method('validate_extended_pubkey')->willReturn(true);
        $btcSvc->method('build_bitcoin_payment_uri')->willReturn('bitcoin:1NewAddr?amount=0.123');

        $processor = new BitcoinPaymentProcessor($gateway, $btcSvc, $db);

        $input = ['crypto_amount' => 0.123];
        $out = $processor->process($order, $input);

        $this->assertArrayHasKey('payment_address', $out, 'processor output: ' . var_export($out, true));
        $this->assertEquals('1NewAddr', $out['payment_address']);
        $this->assertArrayHasKey('payment_uri', $out, 'processor output: ' . var_export($out, true));
        $this->assertEquals('bitcoin:1NewAddr?amount=0.123', $out['payment_uri']);
        $this->assertArrayHasKey('derivation_index', $out, 'processor output: ' . var_export($out, true));

        // F4: derived branch must expose the on-chain seams for third parties.
        $this->assertCount(1, hook_spy_calls('paycryptome_bitcoin_payment_uri'));
        $data_calls = hook_spy_calls('paycryptome_bitcoin_payment_data');
        $this->assertCount(1, $data_calls);
        $this->assertSame($order, $data_calls[0]['args'][1]);
        $this->assertSame($gateway, $data_calls[0]['args'][2]);
    }

    public function test_static_address_branch_fires_bitcoin_filters()
    {
        $gateway = $this->createMock(\WC_Payment_Gateway::class);
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty_value = null) => match ($key) {
            'network_identifier' => '1StaticAddr',
            'selected_network'   => 'mainnet',
            default              => $empty_value,
        });

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(11);
        $order->method('get_billing_first_name')->willReturn('Carol');
        $order->method('get_order_number')->willReturn('11');

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);

        $btcSvc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        // Static address path: validated as an address (not an xpub), no derivation/persistence.
        $btcSvc->method('validate_bitcoin_address')->willReturn(true);
        $btcSvc->expects($this->never())->method('generate_address_from_xPub');
        $btcSvc->method('build_bitcoin_payment_uri')->willReturn('bitcoin:1StaticAddr');

        $processor = new BitcoinPaymentProcessor($gateway, $btcSvc, $db);
        $out = $processor->process($order, ['crypto_amount' => 0.5]);

        $this->assertSame('1StaticAddr', $out['payment_address']);

        // F4: static branch must expose the same on-chain seams as the derived branch.
        $uri_calls = hook_spy_calls('paycryptome_bitcoin_payment_uri');
        $this->assertCount(1, $uri_calls);
        $data_calls = hook_spy_calls('paycryptome_bitcoin_payment_data');
        $this->assertCount(1, $data_calls);
        $this->assertSame($gateway, $data_calls[0]['args'][2]);
    }

    public function test_releases_derivation_index_when_persistence_fails()
    {
        // Regression test for C3: a failure between reserving the index and persisting the
        // address must release the index, or systemic failures burn indexes and eventually
        // blow past the wallet's BIP-44 gap limit.
        $gateway = $this->createMock(\WC_Payment_Gateway::class);
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty_value = null) => match ($key) {
            'network_identifier' => 'xpub_fake',
            'selected_network'   => 'mainnet',
            default              => $empty_value,
        });

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(77);

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);
        $db->method('get_by_order_id')->willReturn(null);
        $db->method('get_wallet_xpubkey_id')->willReturn(9);
        $db->method('reserve_derivation_index_for_wallet')->willReturn(3);
        $db->method('insert_address')->willReturn(false);
        $db->expects($this->once())
            ->method('release_derivation_index')
            ->with(9, 3)
            ->willReturn(true);

        $btcSvc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $btcSvc->method('validate_extended_pubkey')->willReturn(true);
        $btcSvc->method('generate_address_from_xPub')->willReturn('1NewAddr');

        $processor = new BitcoinPaymentProcessor($gateway, $btcSvc, $db);

        $this->expectException(\PayCryptoMe\WooCommerce\PayCryptoMeException::class);
        $processor->process($order, ['crypto_amount' => 0.1]);
    }

    public function test_releases_derivation_index_when_address_generation_throws_an_error()
    {
        // \Error (e.g. "Call to undefined function gmp_init()" on a host without GMP) is not
        // an \Exception — the outer catch must be \Throwable, or this propagates uncaught.
        $gateway = $this->createMock(\WC_Payment_Gateway::class);
        $gateway->method('get_option')->willReturnCallback(fn ($key, $empty_value = null) => match ($key) {
            'network_identifier' => 'xpub_fake',
            'selected_network'   => 'mainnet',
            default              => $empty_value,
        });

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(78);

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);
        $db->method('get_by_order_id')->willReturn(null);
        $db->method('get_wallet_xpubkey_id')->willReturn(9);
        $db->method('reserve_derivation_index_for_wallet')->willReturn(3);
        $db->expects($this->once())
            ->method('release_derivation_index')
            ->with(9, 3)
            ->willReturn(true);
        $db->expects($this->never())->method('insert_address');

        $btcSvc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $btcSvc->method('validate_extended_pubkey')->willReturn(true);
        $btcSvc->method('generate_address_from_xPub')->willThrowException(new \Error('Call to undefined function gmp_init()'));

        $processor = new BitcoinPaymentProcessor($gateway, $btcSvc, $db);

        $this->expectException(\PayCryptoMe\WooCommerce\PayCryptoMeException::class);
        $processor->process($order, ['crypto_amount' => 0.1]);
    }

    public function test_process_preserves_original_exception_as_previous()
    {
        $gateway = new class extends \WC_Payment_Gateway {
            private $opts = [
                'network_identifier' => 'xpub_fake',
                'selected_network'   => 'mainnet',
            ];
            public function get_option($key, $empty_value = null) { return $this->opts[$key] ?? $empty_value; }
        };

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(7);

        $original = new \RuntimeException('db exploded');

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);
        $db->method('get_by_order_id')->willThrowException($original);

        $btcSvc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $btcSvc->method('validate_extended_pubkey')->willReturn(true);

        $processor = new BitcoinPaymentProcessor($gateway, $btcSvc, $db);

        try {
            $processor->process($order, ['crypto_amount' => 0.1]);
            $this->fail('Expected PayCryptoMeException was not thrown.');
        } catch (\PayCryptoMe\WooCommerce\PayCryptoMeException $e) {
            $this->assertSame($original, $e->getPrevious());
        }
    }
}
