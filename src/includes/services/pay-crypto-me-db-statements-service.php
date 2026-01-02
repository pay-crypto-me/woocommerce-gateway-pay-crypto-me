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

	public function __construct()
	{
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'paycrypto_me_bitcoin_transactions_data';
	}

	public function get_table_name(): string
	{
		return $this->table_name;
	}

	public function get_by_order_id(int $order_id): ?array
	{
		global $wpdb;

		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE order_id = %d LIMIT 1", $order_id), ARRAY_A);

		return $row ?: null;
	}

	public function exists_for_order(int $order_id): bool
	{
		return $this->get_by_order_id($order_id) !== null;
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
				'xpub' => $xpub,
				'network' => $network,
				'derivation_index' => $derivation_index,
				'payment_address' => $payment_address,
			],
			['%d', '%s', '%s', '%d', '%s']
		);

		return $inserted !== false;
	}
}