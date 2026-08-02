<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe;
use PayCryptoMe\WooCommerce\PaymentDisplayDataBuilder;

// F1 — the order-details render path exposes two third-party seams:
// paycryptome_order_display_args (pre-build) and paycryptome_order_display_data (post-build).
// apply_filters is a recording no-op in tests (tests/_support/wp-helpers.php) — add_filter
// callbacks are NOT dispatched — so we assert the filters were CALLED with the right payload
// via hook_spy, mirroring the do_action assertions elsewhere. wc_get_template is shimmed no-op.

class OrderDisplayFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        hook_spy_reset();
    }

    private function make_gateway(): WC_Gateway_PayCryptoMe
    {
        // disableOriginalConstructor() skips the heavy gateway constructor (which also sets
        // display_data_builder); the id and builder are wired manually below.
        $gateway = $this->getMockBuilder(WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build_order_display_args'])
            ->getMock();
        $gateway->id = 'paycrypto_me';

        return $gateway;
    }

    private function set_builder(WC_Gateway_PayCryptoMe $gateway, PaymentDisplayDataBuilder $builder): void
    {
        $prop = new \ReflectionProperty(WC_Gateway_PayCryptoMe::class, 'display_data_builder');
        $prop->setAccessible(true);
        $prop->setValue($gateway, $builder);
    }

    public function test_render_applies_pre_and_post_display_filters()
    {
        $args  = ['payment_identifier' => 'addr', 'show_expiry' => false];
        $built = ['crypto_amount' => null, 'expires_at_formatted' => null];

        $gateway = $this->make_gateway();
        $gateway->method('build_order_display_args')->willReturn($args);

        $builder = $this->createMock(PaymentDisplayDataBuilder::class);
        $builder->method('build')->willReturn($built);
        $this->set_builder($gateway, $builder);

        $order = $this->createMock(\WC_Order::class);

        $gateway->render_checkout_order_details_section($order);

        // pre-build seam: args = [display_args, order, gateway]
        $pre = hook_spy_calls('paycryptome_order_display_args');
        $this->assertCount(1, $pre);
        $this->assertSame($args, $pre[0]['args'][0]);
        $this->assertSame($order, $pre[0]['args'][1]);
        $this->assertSame($gateway, $pre[0]['args'][2]);

        // post-build seam: args = [built_data, order, gateway]
        $post = hook_spy_calls('paycryptome_order_display_data');
        $this->assertCount(1, $post);
        $this->assertSame($built, $post[0]['args'][0]);
        $this->assertSame($order, $post[0]['args'][1]);
        $this->assertSame($gateway, $post[0]['args'][2]);
    }

    public function test_render_catches_throwable_and_logs_instead_of_fatal()
    {
        // Regression test for C6's final safety net: any failure while building/rendering the
        // order-details section must be caught and logged, never allowed to fatal the order
        // page. Using \Error (not \Exception) proves the catch is \Throwable-wide.
        $gateway = $this->getMockBuilder(WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build_order_display_args', 'register_paycrypto_me_log'])
            ->getMock();
        $gateway->id = 'paycrypto_me';
        $gateway->method('build_order_display_args')->willThrowException(new \Error('boom'));
        $gateway->expects($this->once())->method('register_paycrypto_me_log');

        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(99);

        $gateway->render_checkout_order_details_section($order);

        $this->assertCount(0, hook_spy_calls('paycryptome_order_display_args'));
    }

    public function test_render_bails_without_firing_filters_when_no_payment()
    {
        $gateway = $this->make_gateway();
        $gateway->method('build_order_display_args')->willReturn(null);

        $order = $this->createMock(\WC_Order::class);
        $gateway->render_checkout_order_details_section($order);

        $this->assertCount(0, hook_spy_calls('paycryptome_order_display_args'));
        $this->assertCount(0, hook_spy_calls('paycryptome_order_display_data'));
    }
}
