<?php

use PayCryptoMe\WooCommerce\PayCryptoMeDBStatementsService;

/**
 * Closes the one gap the unit suite structurally cannot: get_by_order_id()'s INNER -> LEFT JOIN
 * change (fix/schema-upgrade-and-static-records) has never been read back against real MySQL. The
 * unit suite's FakeWPDB returns null for every transactions-table query regardless of the SQL
 * text, so it cannot tell a LEFT JOIN's actual result shape from an INNER JOIN's.
 *
 * Two things this proves that no other test does:
 * - A fixed-address row (WALLET_ID_STATIC_ADDRESS sentinel, no matching wallet/index row) comes
 *   back readable, with derivation_index/xpub/network genuinely NULL rather than the row being
 *   dropped (what an INNER JOIN would do — this is the bug the LEFT JOIN change fixes).
 * - A derived-address row (real wallet + index rows) still comes back with every field populated —
 *   the regression check that the join change didn't break the path that already worked.
 */
class SchemaFixedAddressReadTest extends SchemaTestCase
{
    public function test_a_fixed_address_row_reads_back_with_null_derivation_fields()
    {
        $prefix = $this->fresh_install();

        $row = $this->with_prefix($prefix, function (): ?array {
            $db = new PayCryptoMeDBStatementsService();

            $this->assertTrue($db->insert_static_address(4001, 'bc1qintegrationstaticaddressxxxxxxxxxxxxxx'));

            return $db->get_by_order_id(4001);
        });

        $this->assertNotNull($row, 'A LEFT JOIN must not drop a row with no matching wallet/index');
        $this->assertSame('bc1qintegrationstaticaddressxxxxxxxxxxxxxx', $row['payment_address']);
        $this->assertSame(0, (int) $row['wallet_xpubkeys_id']);
        $this->assertSame(0, (int) $row['derivation_index_id']);
        $this->assertNull($row['derivation_index'], 'No matching index row for the sentinel — must be null, not 0 or absent');
        $this->assertNull($row['xpub']);
        $this->assertNull($row['network']);
    }

    public function test_a_derived_address_row_still_reads_back_fully_populated()
    {
        $prefix = $this->fresh_install();

        $row = $this->with_prefix($prefix, function (): ?array {
            $db = new PayCryptoMeDBStatementsService();

            $wallet_id = $db->insert_wallet_xpubkey('zpub6jftahH18ngZxsVFVpJMzZUKwXJZjQXTAjfSuFRcTDDT', 'mainnet');
            $this->assertIsInt($wallet_id);

            $index = $db->reserve_derivation_index_for_wallet($wallet_id);
            $this->assertSame(0, $index);

            $this->assertTrue($db->insert_address(4002, $index, '1DerivedIntegrationTestAddress', $wallet_id));

            return $db->get_by_order_id(4002);
        });

        $this->assertNotNull($row);
        $this->assertSame('1DerivedIntegrationTestAddress', $row['payment_address']);
        $this->assertSame(0, (int) $row['derivation_index']);
        $this->assertSame('zpub6jftahH18ngZxsVFVpJMzZUKwXJZjQXTAjfSuFRcTDDT', $row['xpub']);
        $this->assertSame('mainnet', $row['network']);
    }
}
