<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Lightning;

/**
 * Both gateways used to disappear from checkout with no explanation: the On-Chain one had a notice
 * for the missing-GMP case only, and the Lightning one had none at all — saving lnd_rest with only
 * the BTCPay fields filled reported "settings saved" and then silently vanished.
 *
 * unavailability_reasons() is now the single source both is_available() and the admin notice read,
 * so the reason shown can never drift from the reason applied.
 */
class UnavailabilityReasonsTest extends TestCase
{
    /** @param array<string,string> $options */
    private function make_gateway(string $class, array $options)
    {
        $gateway = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_option', 'register_paycrypto_me_log'])
            ->getMock();

        $gateway->method('get_option')->willReturnCallback(
            fn($key, $default = null) => $options[$key] ?? $default
        );

        return $gateway;
    }

    private function reasons(object $gateway, string $class): array
    {
        $m = new \ReflectionMethod($class, 'unavailability_reasons');
        $m->setAccessible(true);

        return $m->invoke($gateway);
    }

    public function test_onchain_reports_a_missing_wallet_key_as_a_configuration_reason()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe::class, ['selected_network' => 'mainnet']);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe::class);

        $this->assertCount(1, $reasons['configuration']);
        $this->assertStringContainsString('No wallet xPub', $reasons['configuration'][0]);
    }

    public function test_onchain_reports_a_missing_network_as_a_configuration_reason()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe::class, ['network_identifier' => 'bc1qexample']);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe::class);

        $this->assertCount(1, $reasons['configuration']);
        $this->assertStringContainsString('No network is selected', $reasons['configuration'][0]);
    }

    public function test_onchain_is_fully_configured_when_both_settings_are_present()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe::class, [
            'selected_network'   => 'mainnet',
            'network_identifier' => 'zpub6qRnz3VveUHQwcATbhh2KXFJCFMXRWt3KG9BSG1LDobZE5qSUokvjgJNq7cN26b7M5hE4wRd7S2RwYysuJiWrJR3nfgc4QSQDrBgkg6VVFZ',
        ]);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe::class);

        $this->assertSame([], $reasons['configuration']);
    }

    public function test_onchain_reports_a_missing_extension_as_an_environment_reason()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe::class, [
            'selected_network'   => 'mainnet',
            'network_identifier' => 'bc1qexample',
        ]);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe::class);

        // The suite needs GMP to run the derivation tests at all, so on this host the list is
        // empty; the assertion pins the classification, which is what the notice depends on.
        $expected = extension_loaded('gmp') ? 0 : 1;
        $this->assertCount($expected, $reasons['environment']);
    }

    public function test_lightning_names_each_missing_lnd_field()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe_Lightning::class, ['node_type' => 'lnd_rest']);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe_Lightning::class);

        $this->assertCount(2, $reasons['configuration']);
        $this->assertStringContainsString('lnd REST URL', $reasons['configuration'][0]);
        $this->assertStringContainsString('lnd Macaroon', $reasons['configuration'][1]);
    }

    public function test_lightning_ignores_btcpay_fields_when_lnd_is_selected()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe_Lightning::class, [
            'node_type'        => 'lnd_rest',
            'lnd_rest_url'     => 'https://localhost:8080',
            'lnd_macaroon_hex' => str_repeat('a', 100),
        ]);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe_Lightning::class);

        $this->assertSame([], $reasons['configuration']);
    }

    public function test_lightning_names_each_missing_btcpay_field()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe_Lightning::class, ['node_type' => 'btcpay']);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe_Lightning::class);

        $this->assertCount(3, $reasons['configuration']);
        $this->assertStringContainsString('BTCPay Server URL', $reasons['configuration'][0]);
        $this->assertStringContainsString('BTCPay API Key', $reasons['configuration'][1]);
        $this->assertStringContainsString('BTCPay Store ID', $reasons['configuration'][2]);
    }

    public function test_lightning_defaults_to_btcpay_when_no_node_type_is_saved()
    {
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe_Lightning::class, []);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe_Lightning::class);

        $this->assertCount(3, $reasons['configuration']);
        $this->assertStringContainsString('BTCPay', $reasons['configuration'][0]);
    }

    public function test_lightning_never_reports_an_environment_reason()
    {
        // Lightning talks HTTP to a node; it needs none of the crypto/QR extensions.
        $gateway = $this->make_gateway(WC_Gateway_PayCryptoMe_Lightning::class, ['node_type' => 'btcpay']);

        $reasons = $this->reasons($gateway, WC_Gateway_PayCryptoMe_Lightning::class);

        $this->assertSame([], $reasons['environment']);
    }
}
