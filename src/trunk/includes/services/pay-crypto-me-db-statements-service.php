<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PayCryptoMeDBStatementsService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class PayCryptoMeDBStatementsService
{
	private string $table_name;
	private string $indexes_table;
	private string $wallet_xpubkeys_table;

	public function __construct()
	{
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'paycrypto_me_bitcoin_transactions_data';
		$this->indexes_table = $wpdb->prefix . 'paycrypto_me_bitcoin_derivation_indexes';
		$this->wallet_xpubkeys_table = $wpdb->prefix . 'paycrypto_me_bitcoin_wallet_xpubkeys';
	}

	public function get_table_name(): string
	{
		return $this->table_name;
	}

	public function get_by_order_id(int $order_id): ?array
	{
		global $wpdb;

		// Try cache first to avoid repeated DB calls
		$cache_key = 'paycrypto_order_' . (int) $order_id;
		$cached = function_exists('wp_cache_get') ? wp_cache_get( $cache_key, 'paycrypto_me' ) : false;
		if ($cached !== false && $cached !== null) {
			return $cached;
		}

		// Table names are derived from $wpdb->prefix in the constructor and
		// are considered safe for interpolation after escaping.
		$table = esc_sql( $this->table_name );
		$indexes = esc_sql( $this->indexes_table );
		$wallets = esc_sql( $this->wallet_xpubkeys_table );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Table names are derived from $wpdb->prefix and are escaped above; this query is prepared for the dynamic value.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.*, i.derivation_index AS derivation_index, w.xpub AS xpub, w.network AS network
				FROM {$table} t
				INNER JOIN {$indexes} i ON t.derivation_index_id = i.derivation_index AND t.wallet_xpubkeys_id = i.wallet_xpubkeys_id
				INNER JOIN {$wallets} w ON i.wallet_xpubkeys_id = w.id
				WHERE t.order_id = %d
				LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		$row = $row ?: null;
		if (function_exists('wp_cache_set')) {
			wp_cache_set( $cache_key, $row, 'paycrypto_me', 300 );
		}

		return $row;
	}

	public function get_wallet_xpubkey_id(string $xpub, string $network): ?int
	{
		global $wpdb;

		$cache_key = 'paycrypto_wallet_' . md5($xpub . '|' . $network);
		$cached = function_exists('wp_cache_get') ? wp_cache_get( $cache_key, 'paycrypto_me' ) : false;
		if ($cached !== false && $cached !== null) {
			return $cached;
		}

		$wallets = esc_sql( $this->wallet_xpubkeys_table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Table name is escaped and this is a simple prepared lookup; caching is applied by caller.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wallets} WHERE xpub = %s AND network = %s LIMIT 1",
				$xpub,
				$network
			),
			ARRAY_A
		);

		$result = $row ? (int) $row['id'] : null;
		if (function_exists('wp_cache_set')) {
			wp_cache_set( $cache_key, $result, 'paycrypto_me', 300 );
		}

		return $result;
	}

	public function exists_for_order(int $order_id): bool
	{
		return $this->get_by_order_id($order_id) !== null;
	}

	public function insert_wallet_xpubkey(string $xpub, string $network): int|false
	{
		global $wpdb;

		// Build a concrete, escaped table name for the insert to satisfy static checks.
		$wallets_table = esc_sql( $this->wallet_xpubkeys_table );

		$inserted = $wpdb->insert(
			$wallets_table,
			['xpub' => $xpub, 'network' => $network],
			['%s', '%s']
		);

		if ($inserted === false) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	public function reserve_derivation_index_for_wallet(int $wallet_xpubkeys_id, int $lock_timeout = 10)
	{
		global $wpdb;

		$lock_name = 'paycrypto_wallet_' . (int) $wallet_xpubkeys_id;

		$got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, $lock_timeout));

		if ((int) $got !== 1) {
			throw new \RuntimeException('Could not obtain DB lock for wallet.');
		}

		try {
					$indexes = esc_sql( $this->indexes_table );
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
						// MAX(...) lookup on an indexes table; table fragment escaped above. This operation cannot be cached safely due to locking.
			$max = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(derivation_index) FROM {$indexes} WHERE wallet_xpubkeys_id = %d",
					$wallet_xpubkeys_id
				)
			);

			$next = ($max === null) ? 0 : ((int) $max + 1);

			$inserted = $wpdb->insert(
				$indexes,
				[
					'derivation_index' => $next,
					'wallet_xpubkeys_id' => $wallet_xpubkeys_id,
				],
				[
					'%d',
					'%d',
					]
			);

			if ($inserted === false) {
				throw new \RuntimeException('Failed to insert derivation index.');
			}

			return $next;
		} finally {
			$wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
		}
	}

	/**
	 * Releases a derivation index reserved by reserve_derivation_index_for_wallet() when a
	 * failure happens between reservation and persistence (address derivation/insert_address),
	 * so the index isn't burned without a corresponding order. Left unreleased, systemic
	 * failures (missing GMP, invalid xpub, a write failure) would consume 20 consecutive
	 * indexes and blow past the wallet's BIP-44 gap limit.
	 */
	public function release_derivation_index(int $wallet_xpubkeys_id, int $derivation_index): bool
	{
		global $wpdb;

		$indexes = esc_sql( $this->indexes_table );

		$deleted = $wpdb->delete(
			$indexes,
			[
				'derivation_index'   => $derivation_index,
				'wallet_xpubkeys_id' => $wallet_xpubkeys_id,
			],
			['%d', '%d']
		);

		return $deleted !== false;
	}

	public function insert_address(int $order_id, int $derivation_index, string $payment_address, int $wallet_xpub_id): bool
	{
		global $wpdb;

		if ($this->exists_for_order($order_id)) {
			return false;
		}

		// Use escaped concrete table name for insert to satisfy static analysis checks.
		$table = esc_sql( $this->table_name );

		$inserted = $wpdb->insert(
			$table,
			[
				'order_id' => $order_id,
				'payment_address' => $payment_address,
				'derivation_index_id' => $derivation_index,
				'wallet_xpubkeys_id' => $wallet_xpub_id,
			],
			['%d', '%s', '%d', '%d']
		);

		return $inserted !== false;
	}

	/**
	 * Persist on-chain confirmation progress for an order and fire a domain action on change.
	 *
	 * Pro add-on seam: the free plugin never calls this (on-chain confirmations are verified
	 * manually). The Pro confirmation poller drives it, and consumers react to
	 * `paycryptome_bitcoin_status_changed` (e.g. call $order->payment_complete() once the required
	 * confirmations are reached) — mirroring the Lightning precedent
	 * PayCryptoMeLightningDBStatementsService::update_status()/paycryptome_lightning_status_changed.
	 *
	 * @param string $amount_received BTC amount (matches the DECIMAL(16,8) column), not sats.
	 */
	public function update_transaction_confirmations(
		int $order_id,
		int $num_confirmations,
		string $amount_received,
		string $tx_hash
	): bool {
		global $wpdb;

		$table = esc_sql( $this->table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Table fragment escaped above; dynamic value is prepared. Read the old count to detect a real transition.
		$old_confirmations = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT num_confirmations FROM {$table} WHERE order_id = %d LIMIT 1",
				$order_id
			)
		);

		$updated = $wpdb->update(
			$table,
			[
				'num_confirmations' => $num_confirmations,
				'amount_received'   => $amount_received,
				'tx_hash'           => $tx_hash,
			],
			['order_id' => $order_id],
			['%d', '%s', '%s'],
			['%d']
		);

		if (function_exists('wp_cache_delete')) {
			wp_cache_delete( 'paycrypto_order_' . $order_id, 'paycrypto_me' );
		}

		if ($updated !== false && $old_confirmations !== null && (int) $old_confirmations !== $num_confirmations) {
			do_action('paycryptome_bitcoin_status_changed', $order_id, (int) $old_confirmations, $num_confirmations);
		}

		return $updated !== false;
	}

	public function reset_derivation_indexes(): bool
	{
		global $wpdb;

		// Table name is constructed from $wpdb->prefix in the constructor.
		// Use explicit prefix concat in-place to reduce variable interpolation heuristics.
		$table_name = esc_sql( $wpdb->prefix . 'paycrypto_me_bitcoin_derivation_indexes' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// TRUNCATE operates on the concrete table name; we escape the fragment above.
		// Table name is constructed from $wpdb->prefix and escaped with esc_sql() above.
		// This is a structural statement that cannot be prepared; the variable is safe.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( 'TRUNCATE TABLE ' . $table_name );

		return $result !== false;
	}
}

// phpcs:enable