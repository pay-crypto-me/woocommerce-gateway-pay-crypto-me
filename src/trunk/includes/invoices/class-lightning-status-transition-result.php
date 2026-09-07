<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       LightningStatusTransitionResult
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Immutable result of an attempted Lightning invoice status projection.
 */
final class LightningStatusTransitionResult
{
    public const APPLIED = 'applied';
    public const ALREADY_APPLIED = 'already_applied';
    public const CONFLICT = 'conflict';
    public const NOT_FOUND = 'not_found';
    public const ERROR = 'error';

    public function __construct(
        public readonly string $outcome,
        public readonly int $order_id,
        public readonly string $requested_invoice_id,
        public readonly ?string $stored_invoice_id,
        public readonly string $expected_status,
        public readonly string $requested_status,
        public readonly ?string $current_status = null,
        public readonly ?string $error_message = null,
    ) {}

    public function is_success(): bool
    {
        return $this->outcome === self::APPLIED || $this->outcome === self::ALREADY_APPLIED;
    }
}
