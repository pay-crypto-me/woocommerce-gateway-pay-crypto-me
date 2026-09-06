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
	/**
	 * wallet_xpubkeys_id used for a payment to a fixed address: there is no extended public key and
	 * no derivation index involved. Zero can never collide with a real row — wallet_xpubkeys.id is
	 * AUTO_INCREMENT and starts at 1 — so `WHERE wallet_xpubkeys_id = 0` selects exactly the
	 * fixed-address payments.
	 *
	 * A sentinel instead of NULL because dbDelta() does not apply NOT NULL -> NULL (verified against
	 * MySQL 8), so making those columns nullable would silently leave already-published installs
	 * unchanged while working on fresh ones.
	 */
	public const WALLET_ID_STATIC_ADDRESS = 0;

	private string $table_name;
	private string $indexes_table;
	private string $wallet_xpubkeys_table;

	public function __construct()
	{
		global $wpdb;
		$this->table_name = $wpdb->prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_TRANSACTIONS;
		$this->indexes_table = $wpdb->prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_DERIVATION_INDEXES;
		$this->wallet_xpubkeys_table = $wpdb->prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_WALLETS;
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
		// LEFT JOIN, not INNER: a payment to a fixed address has no wallet key and no derivation
		// index (wallet_xpubkeys_id = WALLET_ID_STATIC_ADDRESS), and an INNER JOIN would silently
		// drop its row. Derived payments always have matching rows, so their result is unchanged.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.*, i.derivation_index AS derivation_index, w.xpub AS xpub, w.network AS network
				FROM {$table} t
				LEFT JOIN {$indexes} i ON t.derivation_index_id = i.derivation_index AND t.wallet_xpubkeys_id = i.wallet_xpubkeys_id
				LEFT JOIN {$wallets} w ON i.wallet_xpubkeys_id = w.id
				WHERE t.order_id = %d
				LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		$row = $row ?: null;

		// Only cache a positive hit. The read guard above already treats a cached null as a miss
		// (`$cached !== false && $cached !== null`), so caching null here is a no-op today — but a
		// later "tidy" of that guard to `$cached !== false` would silently turn it into a real
		// 300-second stale negative cache, defeating the re-read a caller does after losing an
		// insert race (see BitcoinPaymentProcessor::resolve_static_address()/resolve_derived_address()).
		if ($row !== null && function_exists('wp_cache_set')) {
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

		// A database error must become our controlled checkout failure, not be printed into the
		// Store API JSON response when WP_DEBUG_DISPLAY is enabled. Restore the site's prior wpdb
		// setting immediately after this expected-to-be-handled write.
		$can_suppress_errors = \method_exists($wpdb, 'suppress_errors');
		$previous_suppress_errors = $can_suppress_errors ? $wpdb->suppress_errors() : false;
		try {
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
		} finally {
			if ($can_suppress_errors) {
				$wpdb->suppress_errors($previous_suppress_errors);
			}
		}

		return $inserted !== false;
	}

	/**
	 * Records a payment to a fixed address — no derivation index, no wallet key.
	 *
	 * Delegates to insert_address() so both flows share one INSERT and the same
	 * exists_for_order() guard.
	 */
	public function insert_static_address(int $order_id, string $payment_address): bool
	{
		return $this->insert_address(
			$order_id,
			self::WALLET_ID_STATIC_ADDRESS,
			$payment_address,
			self::WALLET_ID_STATIC_ADDRESS
		);
	}

	public function reset_derivation_indexes(): bool
	{
		global $wpdb;

		// Table name is constructed from $wpdb->prefix in the constructor.
		// Use explicit prefix concat in-place to reduce variable interpolation heuristics.
		$table_name = esc_sql( $wpdb->prefix . PayCryptoMeBitcoinGatewayActivate::TABLE_DERIVATION_INDEXES );

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
