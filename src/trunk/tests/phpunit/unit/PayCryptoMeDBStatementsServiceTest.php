<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;

// __() fallback lives in tests/_support/wp-helpers.php.

class FakeWPDB
{
    public $insert_id = 0;
    public $prefix = 'wp_';
    public $last_query = '';
    public $release_lock_result = '1';
    public $release_lock_calls = 0;
    public bool $suppressing_errors = false;
    public array $suppress_errors_calls = [];
    public $insert_result = 1;

    public function prepare($query /*, ...$args */)
    {
        $args = array_slice(func_get_args(), 1);
        if (count($args) === 0) {
            return $query;
        }
        // Use vsprintf to expand %s/%d placeholders for test purposes
        return vsprintf($query, $args);
    }

    public ?array $order_row_result = null;

    public function get_row($query, $output = ARRAY_A)
    {
        $this->last_query = $query;

        // Emulate lookup by xpub/network
        if (stripos($query, 'FROM wp_paycrypto_me_bitcoin_wallet_xpubkeys') !== false) {
            return ['id' => 321];
        }

        if (stripos($query, 'FROM wp_paycrypto_me_bitcoin_transactions_data') !== false) {
            return $this->order_row_result;
        }

        // No matching row for other queries
        return null;
    }

    public function get_var($query)
    {
        $this->last_query = $query;

        if (stripos($query, 'GET_LOCK') !== false) {
            return '1';
        }

        if (stripos($query, 'MAX(derivation_index)') !== false) {
            return null; // simulate empty set on first reservation
        }

        if (stripos($query, 'RELEASE_LOCK') !== false) {
            $this->release_lock_calls++;
            return $this->release_lock_result;
        }

        return null;
    }

    public array $insert_calls = [];

    public function insert($table, $data, $formats = null)
    {
        $this->last_query = 'INSERT INTO ' . $table;
        $this->insert_calls[] = ['table' => $table, 'data' => $data];
        // return 1 on success
        return $this->insert_result;
    }

    public function suppress_errors($suppress = true)
    {
        $previous = $this->suppressing_errors;
        $this->suppressing_errors = (bool) $suppress;
        $this->suppress_errors_calls[] = (bool) $suppress;
        return $previous;
    }

    public array $delete_calls = [];

    public function delete($table, $where, $where_format = null)
    {
        $this->delete_calls[] = ['table' => $table, 'where' => $where];
        return 1;
    }

    public function query($query)
    {
        $this->last_query = $query;
        return true;
    }
}

class PayCryptoMeDBStatementsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new FakeWPDB();
        $GLOBALS['__wp_cache_store'] = [];
    }

    public function test_insert_wallet_xpubkey_returns_insert_id()
    {
        global $wpdb;
        $wpdb->insert_id = 123;

        $svc = new PayCryptoMeDBStatementsService();
        $id = $svc->insert_wallet_xpubkey('xpub_test', 'mainnet');

        $this->assertIsInt($id);
        $this->assertEquals(123, $id);
    }

    public function test_get_wallet_xpubkey_id_returns_id()
    {
        $svc = new PayCryptoMeDBStatementsService();
        $id = $svc->get_wallet_xpubkey_id('xpub_test', 'mainnet');

        $this->assertIsInt($id);
        $this->assertEquals(321, $id);
    }

    public function test_reserve_derivation_index_for_wallet_returns_zero_and_inserts()
    {
        $svc = new PayCryptoMeDBStatementsService();

        $next = $svc->reserve_derivation_index_for_wallet(1, 1);

        $this->assertSame(0, $next, 'First reserved derivation index should be 0');
    }

    public function test_reserve_derivation_index_for_wallet_ignores_release_lock_failure()
    {
        global $wpdb;
        $wpdb->release_lock_result = '0';

        $svc = new PayCryptoMeDBStatementsService();
        $next = $svc->reserve_derivation_index_for_wallet(1, 1);

        // RELEASE_LOCK runs in a `finally` block whose return value is never checked —
        // characterizing today's behavior: a failed release does not fail the
        // reservation, nor is it surfaced anywhere (no exception, no log).
        $this->assertSame(0, $next);
        $this->assertSame(1, $wpdb->release_lock_calls);
    }

    public function test_insert_address_returns_true_when_order_missing()
    {
        $svc = new PayCryptoMeDBStatementsService();

        // choose an order id that our FakeWPDB does not return
        $result = $svc->insert_address(1000, 0, 'tb1address', 1);

        $this->assertTrue($result);
    }

    public function test_insert_address_suppresses_database_output_and_restores_previous_setting()
    {
        global $wpdb;
        $wpdb->insert_result = false;
        $svc = new PayCryptoMeDBStatementsService();

        $this->assertFalse($svc->insert_address(1001, 0, 'tb1address', 1));
        $this->assertSame([true, false], $wpdb->suppress_errors_calls);
        $this->assertFalse($wpdb->suppressing_errors);
    }

    public function test_get_by_order_id_uses_left_join_not_inner_join()
    {
        global $wpdb;
        $svc = new PayCryptoMeDBStatementsService();

        $svc->get_by_order_id(3000);

        // FakeWPDB returns null regardless of the SQL text, so nothing else here would catch a
        // regression back to INNER JOIN — which would silently drop every fixed-address row. See
        // the real-MySQL read-path proof in tests/integration/SchemaFixedAddressReadTest.php.
        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $wpdb->last_query);
        $this->assertStringNotContainsStringIgnoringCase('INNER JOIN', $wpdb->last_query);
    }

    public function test_insert_static_address_uses_the_sentinel_wallet_id()
    {
        global $wpdb;
        $svc = new PayCryptoMeDBStatementsService();

        $this->assertTrue($svc->insert_static_address(2000, 'bc1qstatic'));

        $this->assertCount(1, $wpdb->insert_calls);
        $this->assertSame('wp_paycrypto_me_bitcoin_transactions_data', $wpdb->insert_calls[0]['table']);
        $this->assertSame(
            [
                'order_id' => 2000,
                'payment_address' => 'bc1qstatic',
                'derivation_index_id' => 0,
                'wallet_xpubkeys_id' => 0,
            ],
            $wpdb->insert_calls[0]['data']
        );
        // Zero can never collide with a real wallet row (wallet_xpubkeys.id is AUTO_INCREMENT,
        // starting at 1), so `WHERE wallet_xpubkeys_id = 0` selects exactly these payments.
        $this->assertSame(0, PayCryptoMeDBStatementsService::WALLET_ID_STATIC_ADDRESS);
    }

    public function test_reset_derivation_indexes_truncates()
    {
        $svc = new PayCryptoMeDBStatementsService();
        $res = $svc->reset_derivation_indexes();
        $this->assertTrue($res);
    }

    public function test_release_derivation_index_deletes_the_reserved_row()
    {
        global $wpdb;
        $svc = new PayCryptoMeDBStatementsService();

        $result = $svc->release_derivation_index(1, 5);

        $this->assertTrue($result);
        $this->assertCount(1, $wpdb->delete_calls);
        $this->assertSame(
            ['derivation_index' => 5, 'wallet_xpubkeys_id' => 1],
            $wpdb->delete_calls[0]['where']
        );
    }

    public function test_get_by_order_id_does_not_cache_a_miss()
    {
        // Front C3: the read guard already treats a cached null as a miss, so caching null is a
        // no-op today — but a live trap for whoever later "tidies" that guard. Only a positive row
        // may be cached, or a caller re-reading after losing an insert race (BitcoinPaymentProcessor)
        // would get a stale null for 300 seconds.
        $svc = new PayCryptoMeDBStatementsService();

        $result = $svc->get_by_order_id(4000);

        $this->assertNull($result);
        $this->assertArrayNotHasKey('paycrypto_me:paycrypto_order_4000', $GLOBALS['__wp_cache_store']);
    }

    public function test_get_by_order_id_caches_a_hit()
    {
        global $wpdb;
        $wpdb->order_row_result = ['payment_address' => '1CachedAddr'];

        $svc = new PayCryptoMeDBStatementsService();
        $result = $svc->get_by_order_id(4001);

        $this->assertSame(['payment_address' => '1CachedAddr'], $result);
        $this->assertSame(
            ['payment_address' => '1CachedAddr'],
            $GLOBALS['__wp_cache_store']['paycrypto_me:paycrypto_order_4001']
        );
    }

    public function test_db_installer_tables_are_disjoint_and_non_empty()
    {
        $this->assertNotEmpty(\PayCryptoMe\WooCommerce\PayCryptoMeBitcoinGatewayActivate::TABLES);
        $this->assertNotEmpty(\PayCryptoMe\WooCommerce\PayCryptoMeLightningGatewayActivate::TABLES);
        $this->assertSame(
            [],
            array_intersect(
                \PayCryptoMe\WooCommerce\PayCryptoMeBitcoinGatewayActivate::TABLES,
                \PayCryptoMe\WooCommerce\PayCryptoMeLightningGatewayActivate::TABLES
            )
        );
        $this->assertCount(4, \PayCryptoMe\WooCommerce\DbInstaller::tables());
    }
}
