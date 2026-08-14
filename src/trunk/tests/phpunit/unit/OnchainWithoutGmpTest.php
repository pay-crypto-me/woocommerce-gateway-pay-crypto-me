<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe;

/**
 * A host without the GMP extension cannot derive addresses from an xPub — but it can still take
 * on-chain payments to a single fixed bech32 address, because bech32 needs no big-integer math.
 *
 * The GMP guard used to be coarser than the actual dependency: it disabled the whole gateway, so a
 * store willing to accept the privacy trade-off of one fixed address was blocked for no technical
 * reason. These tests pin the finer-grained rule.
 */
class OnchainWithoutGmpTest extends TestCase
{
    private const BECH32 = 'bc1qw79xn4m4le2f5k9evfhvrhpqkunpywtxr552gz';
    private const XPUB = 'xpub6BmGNiA6M7CTF1nDvz7muM4HrK4dYGu3V36jsUDZTnqo7tCyyVRoVYz6nhhC2HHGXoTcZzEWC7KLAykkTutVFq3r3zHktaoRgQ4PyZyBULh';

    /** @param array<string,string> $options */
    private function make_gateway(array $options): WC_Gateway_PayCryptoMe
    {
        $gateway = $this->getMockBuilder(WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_option', 'register_paycrypto_me_log'])
            ->getMock();

        $gateway->method('get_option')->willReturnCallback(
            fn($key, $default = null) => $options[$key] ?? $default
        );

        return $gateway;
    }

    private function reasons(WC_Gateway_PayCryptoMe $gateway): array
    {
        $m = new \ReflectionMethod(WC_Gateway_PayCryptoMe::class, 'unavailability_reasons');
        $m->setAccessible(true);

        return $m->invoke($gateway);
    }

    private function requires_gmp(WC_Gateway_PayCryptoMe $gateway): bool
    {
        $m = new \ReflectionMethod(WC_Gateway_PayCryptoMe::class, 'configured_identifier_requires_gmp');
        $m->setAccessible(true);

        return $m->invoke($gateway);
    }

    public function test_a_fixed_bech32_address_does_not_require_gmp()
    {
        $gateway = $this->make_gateway([
            'selected_network'   => 'mainnet',
            'network_identifier' => self::BECH32,
        ]);

        $this->assertFalse($this->requires_gmp($gateway));
    }

    public function test_an_xpub_requires_gmp()
    {
        $gateway = $this->make_gateway([
            'selected_network'   => 'mainnet',
            'network_identifier' => self::XPUB,
        ]);

        $this->assertTrue($this->requires_gmp($gateway));
    }

    public function test_a_testnet_bech32_address_does_not_require_gmp_on_testnet()
    {
        $gateway = $this->make_gateway([
            'selected_network'   => 'testnet',
            'network_identifier' => 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx',
        ]);

        $this->assertFalse($this->requires_gmp($gateway));
    }

    public function test_no_environment_reason_is_reported_for_a_fixed_bech32_address()
    {
        // On a host WITH gmp there is no environment reason either way, so this asserts the rule
        // that decides it rather than the host's own extension list.
        $gateway = $this->make_gateway([
            'selected_network'   => 'mainnet',
            'network_identifier' => self::BECH32,
        ]);

        $reasons = $this->reasons($gateway);

        $this->assertSame([], $reasons['environment']);
        $this->assertSame([], $reasons['configuration']);
    }

    public function test_environment_reason_names_the_fixed_address_alternative_when_gmp_is_absent()
    {
        if (extension_loaded('gmp')) {
            $this->markTestSkipped('Only observable on a host without the GMP extension.');
        }

        $gateway = $this->make_gateway([
            'selected_network'   => 'mainnet',
            'network_identifier' => self::XPUB,
        ]);

        $reasons = $this->reasons($gateway);

        $this->assertCount(1, $reasons['environment']);
        $this->assertStringContainsString('bc1', $reasons['environment'][0]);
    }

    public function test_gateway_is_available_with_a_fixed_bech32_address_even_without_gmp()
    {
        $gateway = $this->getMockBuilder(WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_option', 'register_paycrypto_me_log'])
            ->getMock();

        $options = [
            'enabled'            => 'yes',
            'selected_network'   => 'mainnet',
            'network_identifier' => self::BECH32,
        ];
        $gateway->method('get_option')->willReturnCallback(
            fn($key, $default = null) => $options[$key] ?? $default
        );

        // is_available() reads $this->enabled, which the real constructor sets from the option.
        $gateway->enabled = 'yes';

        $this->assertTrue($gateway->is_available());
    }

    /**
     * End-to-end through the processor with the REAL BitcoinAddressService — the existing
     * static-address test in BitcoinPaymentProcessorTest mocks the service, so it cannot catch a
     * GMP dependency. Run this file on a PHP without the extension and it still has to pass.
     */
    public function test_processor_produces_a_payment_for_a_fixed_bech32_address()
    {
        $gateway = $this->createMock(\WC_Payment_Gateway::class);
        $gateway->method('get_option')->willReturnCallback(fn($key, $default = null) => match ($key) {
            'network_identifier'           => self::BECH32,
            'selected_network'             => 'mainnet',
            'payment_number_confirmations' => 2,
            default                        => $default,
        });

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(7);
        $order->method('get_billing_first_name')->willReturn('Alice');
        $order->method('get_order_number')->willReturn(7);

        $db = $this->createMock(\PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService::class);
        // The static-address branch returns before any derivation index is reserved.
        $db->expects($this->never())->method('reserve_derivation_index_for_wallet');

        $processor = new \PayCryptoMe\WooCommerce\BitcoinPaymentProcessor(
            $gateway,
            new \PayCryptoMe\WooCommerce\BitcoinAddressService(),
            $db
        );

        $out = $processor->process($order, ['crypto_amount' => null]);

        $this->assertSame(self::BECH32, $out['payment_address']);
        $this->assertStringStartsWith('bitcoin:' . self::BECH32, $out['payment_uri']);
        $this->assertArrayNotHasKey('derivation_index', $out);
    }

    public function test_settings_screen_notice_offers_the_fixed_address_route_when_gmp_is_absent()
    {
        if (extension_loaded('gmp')) {
            $this->markTestSkipped('Only observable on a host without the GMP extension.');
        }

        $gateway = $this->make_gateway(['selected_network' => 'mainnet']);

        ob_start();
        $gateway->render_missing_extension_notice();
        $html = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('GMP', $html);
        $this->assertStringContainsString('bc1', $html);
        $this->assertStringContainsString('Lightning payments are unaffected', $html);
    }

    public function test_settings_screen_notice_is_silent_when_the_extension_is_present()
    {
        if (!extension_loaded('gmp')) {
            $this->markTestSkipped('Only observable on a host with the GMP extension.');
        }

        $gateway = $this->make_gateway(['selected_network' => 'mainnet']);

        ob_start();
        $gateway->render_missing_extension_notice();

        $this->assertSame('', ob_get_clean());
    }

    public function test_settings_screen_notice_uses_the_testnet_prefix_on_testnet()
    {
        if (extension_loaded('gmp')) {
            $this->markTestSkipped('Only observable on a host without the GMP extension.');
        }

        $gateway = $this->make_gateway(['selected_network' => 'testnet']);

        ob_start();
        $gateway->render_missing_extension_notice();
        $html = ob_get_clean();

        $this->assertStringContainsString('tb1', $html);
    }
}
