<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       EnvironmentRequirements
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

\defined('ABSPATH') || exit;

/**
 * Which PHP extensions a given capability needs, and which of them this host is missing.
 *
 * Exists so a missing extension is reported as a host problem wherever it surfaces
 * (settings save, checkout availability, admin notice, QR rendering) instead of being
 * mistaken for bad user input — a missing GMP made a perfectly valid zpub fail with
 * "not valid for the selected network", because Base58::decode() throws an \Error that
 * validation used to swallow into a plain false.
 */
class EnvironmentRequirements
{
    /** Address derivation/validation goes through bitwasp/bitcoin's EC adapter. */
    public const ONCHAIN_EXTENSIONS = ['gmp'];

    // gd: PngWriter. iconv: hard requirement of bacon/bacon-qr-code. fileinfo:
    // mime_content_type() for the logo overlay.
    public const QR_EXTENSIONS = ['gd', 'iconv', 'fileinfo'];

    /** @return string[] */
    public static function missing_onchain_extensions(): array
    {
        return self::missing(self::ONCHAIN_EXTENSIONS);
    }

    /** @return string[] */
    public static function missing_qr_extensions(): array
    {
        return self::missing(self::QR_EXTENSIONS);
    }

    /**
     * @param string[] $extensions
     * @return string[]
     */
    public static function missing(array $extensions): array
    {
        return array_values(array_filter(
            $extensions,
            static fn(string $extension): bool => !\extension_loaded($extension)
        ));
    }

    /**
     * Human-readable, translated list for a user-facing message: "GMP" / "GD and iconv".
     *
     * @param string[] $missing
     */
    public static function describe(array $missing): string
    {
        $labels = array_map([self::class, 'label'], $missing);

        if (\count($labels) <= 1) {
            return (string) ($labels[0] ?? '');
        }

        $last = array_pop($labels);

        return \sprintf(
            '%1$s and %2$s',
            implode(', ', $labels),
            $last
        );
    }

    private static function label(string $extension): string
    {
        // Canonical casing as the extensions are documented by php.net, so a store owner
        // can hand the message to their host verbatim.
        $labels = ['gmp' => 'GMP', 'gd' => 'GD', 'iconv' => 'iconv', 'fileinfo' => 'fileinfo'];

        return $labels[$extension] ?? $extension;
    }
}
