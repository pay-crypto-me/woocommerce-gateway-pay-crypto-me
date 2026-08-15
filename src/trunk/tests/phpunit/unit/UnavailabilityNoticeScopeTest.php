<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe_Lightning;

/**
 * Both gateways hook admin_notices, so every WooCommerce admin screen used to carry BOTH notices
 * at once: the On-Chain "no wallet xPub is configured" sat on top of the Lightning settings screen
 * and the three BTCPay reasons sat on top of the On-Chain one, neither actionable where it showed.
 *
 * Each notice now renders only on its own gateway's settings section.
 */
class UnavailabilityNoticeScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TEST_CURRENT_USER_CAN'] = true;
        $this->on_settings_section('');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TEST_CURRENT_SCREEN'], $GLOBALS['TEST_CURRENT_USER_CAN'], $_GET['section']);
    }

    private function on_settings_section(string $section): void
    {
        $GLOBALS['TEST_CURRENT_SCREEN'] = (object) ['id' => 'woocommerce_page_wc-settings'];
        $_GET['section'] = $section;
    }

    private function on_screen(string $screen_id): void
    {
        $GLOBALS['TEST_CURRENT_SCREEN'] = (object) ['id' => $screen_id];
        unset($_GET['section']);
    }

    /** @param array<string,string> $options */
    private function make_gateway(string $class, string $id, array $options = [])
    {
        $gateway = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_option', 'register_paycrypto_me_log'])
            ->getMock();

        $gateway->method('get_option')->willReturnCallback(
            fn($key, $default = null) => $options[$key] ?? $default
        );

        $gateway->id = $id;
        $gateway->method_title = 'Gateway ' . $id;
        // Set by the real constructor from the 'enabled' option, which is disabled here.
        $gateway->enabled = 'yes';

        return $gateway;
    }

    private function onchain(array $options = [])
    {
        return $this->make_gateway(WC_Gateway_PayCryptoMe::class, 'paycrypto_me', $options);
    }

    private function lightning(array $options = [])
    {
        return $this->make_gateway(WC_Gateway_PayCryptoMe_Lightning::class, 'paycrypto_me_lightning', $options);
    }

    private function render(object $gateway): string
    {
        ob_start();
        $gateway->render_unavailability_notice();

        return (string) ob_get_clean();
    }

    public function test_gateway_renders_its_own_reasons_on_its_own_settings_section()
    {
        $this->on_settings_section('paycrypto_me_lightning');

        $html = $this->render($this->lightning(['node_type' => 'btcpay']));

        $this->assertStringContainsString('BTCPay Server URL', $html);
        $this->assertStringContainsString('BTCPay Store ID', $html);
    }

    public function test_gateway_is_silent_on_the_other_gateways_settings_section()
    {
        $this->on_settings_section('paycrypto_me_lightning');

        $this->assertSame('', $this->render($this->onchain(['selected_network' => 'mainnet'])));
    }

    /**
     * 'paycrypto_me' is a prefix of 'paycrypto_me_lightning': a prefix match would put the
     * On-Chain notice back on the Lightning screen, which is the exact bug being fixed.
     */
    public function test_the_section_match_is_exact_not_a_prefix()
    {
        $this->on_settings_section('paycrypto_me');

        $this->assertSame('', $this->render($this->lightning(['node_type' => 'btcpay'])));
        $this->assertStringContainsString('No wallet xPub', $this->render($this->onchain(['selected_network' => 'mainnet'])));
    }

    public function test_no_notice_on_the_payments_list_where_no_section_is_selected()
    {
        $this->on_settings_section('');

        $this->assertSame('', $this->render($this->onchain(['selected_network' => 'mainnet'])));
        $this->assertSame('', $this->render($this->lightning(['node_type' => 'btcpay'])));
    }

    public function test_no_notice_on_an_unrelated_woocommerce_settings_tab()
    {
        $this->on_settings_section('shipping_zones');

        $this->assertSame('', $this->render($this->lightning(['node_type' => 'btcpay'])));
    }

    /** @dataProvider unrelated_screens */
    public function test_no_notice_outside_the_settings_screen(string $screen_id)
    {
        $this->on_screen($screen_id);

        $this->assertSame('', $this->render($this->onchain(['selected_network' => 'mainnet'])));
        $this->assertSame('', $this->render($this->lightning(['node_type' => 'btcpay'])));
    }

    public static function unrelated_screens(): array
    {
        return [
            'orders (HPOS)' => ['woocommerce_page_wc-orders'],
            'order edit'    => ['shop_order'],
            'plugins'       => ['plugins'],
            'dashboard'     => ['dashboard'],
        ];
    }

    public function test_a_disabled_gateway_reports_nothing()
    {
        // Absence from checkout is exactly what "disabled" means — not a problem to report. Read
        // from the instance at render time, since the notice is hooked once for whichever gateway
        // objects are current (WooCommerce rebuilds them after every settings save).
        $this->on_settings_section('paycrypto_me_lightning');

        $gateway = $this->lightning(['node_type' => 'btcpay']);
        $gateway->enabled = 'no';

        $this->assertSame('', $this->render($gateway));
    }

    public function test_nothing_renders_for_a_user_who_cannot_manage_options()
    {
        $this->on_settings_section('paycrypto_me_lightning');
        $GLOBALS['TEST_CURRENT_USER_CAN'] = false;

        $this->assertSame('', $this->render($this->lightning(['node_type' => 'btcpay'])));
    }

    public function test_a_fully_configured_gateway_renders_nothing_on_its_own_section()
    {
        $this->on_settings_section('paycrypto_me_lightning');

        $html = $this->render($this->lightning([
            'node_type'       => 'btcpay',
            'btcpay_url'      => 'https://btcpay.example.com',
            'btcpay_api_key'  => 'token',
            'btcpay_store_id' => 'store',
        ]));

        $this->assertSame('', $html);
    }

    /**
     * The On-Chain gateway prints the missing-extension warning itself from admin_options(), in a
     * longer form pointing at the field below it. Both render on this same screen, so the notice
     * must not repeat it — only the configuration gaps belong here.
     */
    public function test_onchain_leaves_environment_reasons_to_its_inline_notice()
    {
        if (extension_loaded('gmp')) {
            $this->markTestSkipped('Only observable on a host without the GMP extension.');
        }

        $this->on_settings_section('paycrypto_me');

        $gateway = $this->onchain([
            'selected_network'   => 'mainnet',
            'network_identifier' => 'xpub6BmGNiA6M7CTF1nDvz7muM4HrK4dYGu3V36jsUDZTnqo7tCyyVRoVYz6nhhC2HHGXoTcZzEWC7KLAykkTutVFq3r3zHktaoRgQ4PyZyBULh',
        ]);

        // The reason itself is still reported (is_available() hides the gateway because of it).
        $reasons = (new \ReflectionMethod(WC_Gateway_PayCryptoMe::class, 'unavailability_reasons'));
        $reasons->setAccessible(true);
        $this->assertCount(1, $reasons->invoke($gateway)['environment']);

        $this->assertSame('', $this->render($gateway), 'the inline admin_options() notice already says it');
    }
}
