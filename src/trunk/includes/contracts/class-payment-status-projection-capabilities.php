<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       PaymentStatusProjectionCapabilities
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Versioned discovery surface for status projections supported by the Base plugin.
 */
final class PaymentStatusProjectionCapabilities
{
    public const CONTRACT_VERSION = 1;
    public const LIGHTNING_INVOICE_STATUS_CAS = 1;
    public const ONCHAIN_CONFIRMATION_PROGRESS = 0;

    /**
     * @return array{contract_version: int, lightning_invoice_status_cas: int, onchain_confirmation_progress: int}
     */
    public static function all(): array
    {
        return [
            'contract_version'                  => self::CONTRACT_VERSION,
            'lightning_invoice_status_cas'      => self::LIGHTNING_INVOICE_STATUS_CAS,
            'onchain_confirmation_progress'     => self::ONCHAIN_CONFIRMATION_PROGRESS,
        ];
    }
}
