<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BtcpayLightningProcessor;
use PayCryptoMe\WooCommerce\LightningInvoiceServiceContract;
use PayCryptoMe\WooCommerce\LightningInvoiceResponse;
use PayCryptoMe\WooCommerce\PayCryptoMeLightningDBStatementsService;
use PayCryptoMe\WooCommerce\PayCryptoMePaymentException;

// WC_Payment_Gateway fallback lives in tests/_support/wp-helpers.php (loaded by
// bootstrap.php before any test file).

class AbstractLightningProcessorTest extends TestCase
{
    // Constructor injection (audit Fase 3+, DI nos processors) replaced the previous
    // disableOriginalConstructor() + reflection setup — these tests now also exercise
    // the concrete Lightning processor's injected service/db seam end-to-end.
    private function make_processor(\WC_Payment_Gateway $gateway, $service, $db): BtcpayLightningProcessor
    {
        return new BtcpayLightningProcessor($gateway, $service, $db);
    }

    public function test_resolves_payment_request_when_initially_empty_before_db_insert(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(42);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv1', '', 'New', null));
        $service->method('resolve_payment_request')->willReturnOnConsecutiveCalls('', 'lnbc1resolved');

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->expects($this->once())
            ->method('insert_invoice')
            ->with(42, 'btcpay', 'inv1', 'lnbc1resolved', $this->anything(), null)
            ->willReturn(true);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $result    = $processor->process($order, []);

        $this->assertSame('lnbc1resolved', $result['payment_request']);
        $this->assertSame('lightning:lnbc1resolved', $result['payment_uri']);
    }

    public function test_skips_resolution_when_payment_request_already_present(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(43);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv2', 'lnbc1direct', 'OPEN', null));
        $service->expects($this->never())->method('resolve_payment_request');

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->expects($this->once())
            ->method('insert_invoice')
            ->with(43, 'btcpay', 'inv2', 'lnbc1direct', $this->anything(), null)
            ->willReturn(true);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $result    = $processor->process($order, []);

        $this->assertSame('lnbc1direct', $result['payment_request']);
        $this->assertSame('lightning:lnbc1direct', $result['payment_uri']);
    }

    public function test_btcpay_invoice_args_include_order_total_and_currency(): void
    {
        $order = new class extends \WC_Order {
            public function get_id() { return 45; }
            public function get_total() { return '150.50'; }
            public function get_currency() { return 'BRL'; }
        };

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->expects($this->once())
            ->method('create_invoice')
            ->with($this->callback(fn($args) => $args['amount'] === '150.50' && $args['currency'] === 'BRL'))
            ->willReturn(new LightningInvoiceResponse('inv4', 'lnbc1test', 'New', null));

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->method('insert_invoice')->willReturn(true);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $processor->process($order, []);
    }

    public function test_throws_paycrypto_me_payment_exception_when_resolution_exhausted(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(44);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv3', '', 'New', null));
        // Exactly RESOLVE_MAX_ATTEMPTS calls, no more/fewer — proves the loop bound.
        $service->expects($this->exactly(2))->method('resolve_payment_request')->willReturn('');

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->expects($this->never())->method('insert_invoice');

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);

        $this->expectException(PayCryptoMePaymentException::class);
        $processor->process($order, []);
    }

    public function test_process_encodes_node_type_into_crypto_network(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(46);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv5', 'lnbc1test', 'OPEN', null));

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->method('insert_invoice')->willReturn(true);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $result    = $processor->process($order, []);

        $this->assertSame('lightning:btcpay', $result['crypto_network'], 'node_type is folded into crypto_network so PaymentProcessor::register_payment_log() and order meta surface it without adding a bare node_type key that On-Chain orders would show as N-A');
    }

    public function test_reuses_valid_existing_invoice_without_creating_a_new_one(): void
    {
        // Regression test for C2: a second process_payment() call for the same order (checkout
        // retry / order-pay) must reuse the still-valid invoice instead of creating another one
        // at the node, which would otherwise diverge the order meta from the DB row.
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(50);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->expects($this->never())->method('create_invoice');

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->method('get_by_order_id')->willReturn([
            'order_id'        => 50,
            'node_type'       => 'btcpay',
            'invoice_id'      => 'inv_existing',
            'payment_request' => 'lnbc1existing',
            'status'          => 'New',
            'expires_at'      => gmdate('Y-m-d H:i:s', time() + 3600),
            'amount_sats'     => null,
        ]);
        $db->expects($this->never())->method('insert_invoice');
        $db->expects($this->never())->method('replace_invoice');

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $result    = $processor->process($order, []);

        $this->assertSame('lnbc1existing', $result['payment_request']);
        $this->assertSame('lightning:lnbc1existing', $result['payment_uri']);
    }

    public function test_replaces_expired_invoice_instead_of_diverging_meta_and_db(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(51);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv_new', 'lnbc1new', 'New', null));

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->method('get_by_order_id')->willReturn([
            'order_id'        => 51,
            'node_type'       => 'btcpay',
            'invoice_id'      => 'inv_old',
            'payment_request' => 'lnbc1old',
            'status'          => 'New',
            'expires_at'      => gmdate('Y-m-d H:i:s', time() - 3600),
            'amount_sats'     => null,
        ]);
        $db->expects($this->never())->method('insert_invoice');
        $db->expects($this->once())
            ->method('replace_invoice')
            ->with(51, 'btcpay', 'inv_new', 'lnbc1new', $this->anything(), null)
            ->willReturn(true);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);
        $result    = $processor->process($order, []);

        $this->assertSame('lnbc1new', $result['payment_request']);
    }

    public function test_throws_when_invoice_persistence_fails(): void
    {
        // insert_invoice() can return false on a race (UNIQUE KEY unique_order hit between the
        // get_by_order_id() check and the insert) — this must never be swallowed silently.
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_id')->willReturn(52);

        $service = $this->createMock(LightningInvoiceServiceContract::class);
        $service->method('create_invoice')->willReturn(new LightningInvoiceResponse('inv6', 'lnbc1six', 'New', null));

        $db = $this->createMock(PayCryptoMeLightningDBStatementsService::class);
        $db->method('get_by_order_id')->willReturn(null);
        $db->method('insert_invoice')->willReturn(false);

        $processor = $this->make_processor(new \WC_Payment_Gateway(), $service, $db);

        $this->expectException(PayCryptoMePaymentException::class);
        $processor->process($order, []);
    }

    public function test_retry_constants_are_two_attempts_750ms_apart(): void
    {
        // Asserted via reflection rather than a live-timed retry: usleep()-ing the
        // real 750ms in a unit test would make the suite slow without adding
        // confidence beyond what the exhaustion test above already proves.
        $rc = new \ReflectionClass(\PayCryptoMe\WooCommerce\AbstractLightningProcessor::class);

        $this->assertSame(2, $rc->getConstant('RESOLVE_MAX_ATTEMPTS'));
        $this->assertSame(750, $rc->getConstant('RESOLVE_DELAY_MS'));
    }
}
