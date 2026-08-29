<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\PayCryptoMeLightningDBStatementsService;

// esc_sql()/wp_cache_*()/ARRAY_A fallbacks live in tests/_support/paycryptome-shims.php
// and tests/_support/wp-helpers.php.

class FakeWPDBLightningInvoices
{
    public $prefix = 'wp_';
    public array $rows = [];
    public int $get_row_calls = 0;

    public function prepare($query, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function () use (&$i, $args) {
            $value = $args[$i++];
            return is_string($value) ? "'" . $value . "'" : (string) $value;
        }, $query);
    }

    public function get_row($query, $output = ARRAY_A)
    {
        $this->get_row_calls++;

        if (preg_match("/order_id = '?(\d+)'?/", $query, $m)) {
            $order_id = (int) $m[1];
            return $this->rows[$order_id] ?? null;
        }

        if (preg_match("/invoice_id = '([^']+)'/", $query, $m)) {
            $invoice_id = $m[1];
            foreach ($this->rows as $row) {
                if (($row['invoice_id'] ?? null) === $invoice_id) {
                    return $row;
                }
            }
            return null;
        }

        return null;
    }

    public function insert($table, $data, $formats = null)
    {
        $this->rows[$data['order_id']] = $data;
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $order_id = $where['order_id'];
        if (!isset($this->rows[$order_id])) {
            return false;
        }
        if (isset($where['invoice_id']) && $this->rows[$order_id]['invoice_id'] !== $where['invoice_id']) {
            return 0;
        }
        $this->rows[$order_id] = array_merge($this->rows[$order_id], $data);
        return 1;
    }
}

class PayCryptoMeLightningDBStatementsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new FakeWPDBLightningInvoices();
        $GLOBALS['__wp_cache_store'] = [];
        hook_spy_reset();
    }

    public function test_get_by_order_id_returns_null_when_missing()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();

        $this->assertNull($svc->get_by_order_id(1));
    }

    public function test_exists_for_order_reflects_get_by_order_id()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();

        $this->assertFalse($svc->exists_for_order(1));

        $svc->insert_invoice(1, 'btcpay', 'inv_1', 'lnbc1', '2026-01-01 00:00:00');

        $this->assertTrue($svc->exists_for_order(1));
    }

    public function test_insert_invoice_persists_row_and_returns_true()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();

        $result = $svc->insert_invoice(42, 'lnd_rest', 'inv_42', 'lnbc42', '2026-02-01 00:00:00', 5000);

        $this->assertTrue($result);
        $this->assertSame([
            'order_id'        => 42,
            'node_type'       => 'lnd_rest',
            'invoice_id'      => 'inv_42',
            'payment_request' => 'lnbc42',
            'expires_at'      => '2026-02-01 00:00:00',
            'status'          => 'New',
            'amount_sats'     => 5000,
        ], $wpdb->rows[42]);
    }

    public function test_insert_invoice_omits_amount_sats_when_null()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();

        $svc->insert_invoice(7, 'btcpay', 'inv_7', 'lnbc7', '2026-02-01 00:00:00');

        $this->assertArrayNotHasKey('amount_sats', $wpdb->rows[7]);
    }

    public function test_insert_invoice_returns_false_and_skips_write_when_already_exists()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();

        $svc->insert_invoice(9, 'btcpay', 'inv_9', 'lnbc9', '2026-02-01 00:00:00');
        $wpdb->rows[9]['invoice_id'] = 'unchanged';

        $result = $svc->insert_invoice(9, 'btcpay', 'inv_9_duplicate', 'lnbc9dup', '2026-02-01 00:00:00');

        $this->assertFalse($result);
        $this->assertSame('unchanged', $wpdb->rows[9]['invoice_id']);
    }

    public function test_update_status_updates_existing_row_and_returns_true()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(3, 'btcpay', 'inv_3', 'lnbc3', '2026-02-01 00:00:00');

        $result = $svc->update_status(3, 'Settled');

        $this->assertTrue($result);
        $this->assertSame('Settled', $wpdb->rows[3]['status']);
    }

    public function test_update_status_returns_false_when_order_missing()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();

        $this->assertFalse($svc->update_status(999, 'Settled'));
    }

    public function test_get_by_order_id_serves_stale_cache_until_invalidated()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(5, 'btcpay', 'inv_5', 'lnbc5', '2026-02-01 00:00:00');

        $first = $svc->get_by_order_id(5);
        $this->assertSame('New', $first['status']);

        // Mutate the underlying row directly, bypassing the service, to prove a
        // second read comes from cache rather than re-querying $wpdb.
        $wpdb->rows[5]['status'] = 'Settled';
        $cached = $svc->get_by_order_id(5);
        $this->assertSame('New', $cached['status'], 'Expected a cached (stale) read');

        // update_status() must invalidate the cache so the next read is fresh.
        $svc->update_status(5, 'Settled');
        $fresh = $svc->get_by_order_id(5);
        $this->assertSame('Settled', $fresh['status']);
    }

    public function test_get_by_order_id_never_caches_a_miss()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();

        $svc->get_by_order_id(123);
        $svc->get_by_order_id(123);

        $this->assertSame(2, $wpdb->get_row_calls);
        $this->assertArrayNotHasKey('paycrypto_me:paycrypto_lightning_order_123', $GLOBALS['__wp_cache_store']);
    }

    public function test_get_by_invoice_id_returns_matching_row()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(11, 'btcpay', 'inv_11', 'lnbc11', '2026-03-01 00:00:00');

        $row = $svc->get_by_invoice_id('inv_11');

        $this->assertNotNull($row);
        $this->assertSame(11, $row['order_id']);
    }

    public function test_get_by_invoice_id_returns_null_when_missing()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();

        $this->assertNull($svc->get_by_invoice_id('does_not_exist'));
    }

    public function test_update_status_fires_status_changed_action_with_old_and_new_status()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(4, 'btcpay', 'inv_4', 'lnbc4', '2026-02-01 00:00:00');

        $svc->update_status(4, 'Settled');

        $calls = hook_spy_calls('paycryptome_lightning_status_changed');
        $this->assertCount(1, $calls);
        $this->assertSame([4, 'New', 'Settled'], $calls[0]['args']);
    }

    public function test_update_status_does_not_fire_action_when_status_unchanged()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(6, 'btcpay', 'inv_6', 'lnbc6', '2026-02-01 00:00:00');

        $svc->update_status(6, 'New');

        $this->assertCount(0, hook_spy_calls('paycryptome_lightning_status_changed'));
    }

    public function test_update_status_does_not_fire_action_when_order_missing()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();

        $svc->update_status(999, 'Settled');

        $this->assertCount(0, hook_spy_calls('paycryptome_lightning_status_changed'));
    }

    public function test_replace_invoice_overwrites_existing_row_and_returns_true()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(20, 'btcpay', 'inv_old', 'lnbc_old', '2026-01-01 00:00:00', 1000);

        $result = $svc->replace_invoice(20, 'btcpay', 'inv_new', 'lnbc_new', '2026-02-01 00:00:00', 2000, 'inv_old');

        $this->assertTrue($result);
        $this->assertSame([
            'order_id'        => 20,
            'node_type'       => 'btcpay',
            'invoice_id'      => 'inv_new',
            'payment_request' => 'lnbc_new',
            'expires_at'      => '2026-02-01 00:00:00',
            'status'          => 'New',
            'amount_sats'     => 2000,
        ], $wpdb->rows[20]);
    }

    public function test_replace_invoice_returns_false_when_order_missing()
    {
        $svc = new PayCryptoMeLightningDBStatementsService();

        $this->assertFalse($svc->replace_invoice(999, 'btcpay', 'inv_new', 'lnbc_new', '2026-02-01 00:00:00', null, 'inv_old'));
    }

    public function test_replace_invoice_returns_false_when_another_request_already_replaced_it()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(22, 'btcpay', 'inv_old', 'lnbc_old', '2026-01-01 00:00:00');
        $wpdb->rows[22]['invoice_id'] = 'inv_winner';

        $result = $svc->replace_invoice(22, 'btcpay', 'inv_loser', 'lnbc_loser', '2026-02-01 00:00:00', null, 'inv_old');

        $this->assertFalse($result);
        $this->assertSame('inv_winner', $wpdb->rows[22]['invoice_id']);
    }

    public function test_replace_invoice_invalidates_cache()
    {
        global $wpdb;
        $svc = new PayCryptoMeLightningDBStatementsService();
        $svc->insert_invoice(21, 'btcpay', 'inv_old', 'lnbc_old', '2026-01-01 00:00:00');
        $svc->get_by_order_id(21); // warm the cache

        $svc->replace_invoice(21, 'btcpay', 'inv_new', 'lnbc_new', '2026-02-01 00:00:00', null, 'inv_old');

        $fresh = $svc->get_by_order_id(21);
        $this->assertSame('inv_new', $fresh['invoice_id']);
    }
}
