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

		$sql = $wpdb->prepare(
			"SELECT t.*, i.derivation_index AS derivation_index, w.xpub AS xpub, w.network AS network
			FROM {$this->table_name} t
			INNER JOIN {$this->indexes_table} i ON t.derivation_index_id = i.derivation_index
			INNER JOIN {$this->wallet_xpubkeys_table} w ON i.wallet_xpubkeys_id = w.id
			WHERE t.order_id = %d
			LIMIT 1",
			$order_id
		);

		$row = $wpdb->get_row($sql, ARRAY_A);

		return $row ?: null;
	}

	public function get_wallet_xpubkey_id(string $xpub, string $network): ?int
	{
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT id FROM {$this->wallet_xpubkeys_table} WHERE xpub = %s AND network = %s LIMIT 1",
			$xpub,
			$network
		);

		$row = $wpdb->get_row($sql, ARRAY_A);

		return $row ? (int) $row['id'] : null;
	}

	public function exists_for_order(int $order_id): bool
	{
		return $this->get_by_order_id($order_id) !== null;
	}

	public function insert_wallet_xpubkey(string $xpub, string $network): int|false
	{
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->wallet_xpubkeys_table,
			['xpub' => $xpub, 'network' => $network],
			['%s', '%s']
		);

		if ($inserted === false) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	public function insert_derivation_index(string $xpub, string $network): int|false
	{
		global $wpdb;

		if (!$inserted_wallet_xpub_id = $this->get_wallet_xpubkey_id($xpub, $network)) {
			$inserted_wallet_xpub_id = $this->insert_wallet_xpubkey($xpub, $network);
		}

		if (!$inserted_wallet_xpub_id) {
			return false;
		}

		$inserted = $wpdb->insert(
			$this->indexes_table,
			['wallet_xpubkeys_id' => $inserted_wallet_xpub_id],
			['%d']
		);

		if ($inserted === false) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	public function insert_address(int $order_id, string $xpub, string $network, int $derivation_index, string $payment_address): bool
	{
		global $wpdb;

		if ($this->exists_for_order($order_id)) {
			return false;
		}

		$inserted = $wpdb->insert(
			$this->table_name,
			[
				'order_id' => $order_id,
				'payment_address' => $payment_address,
				'derivation_index_id' => $derivation_index,
			],
			['%d', '%s', '%d']
		);

		return $inserted !== false;
	}
}