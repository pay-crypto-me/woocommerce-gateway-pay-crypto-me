<?php
/**
 * PayCrypto.Me Gateway for WooCommerce
 *
 * @package     WooCommerce\PayCryptoMe
 * @class       BitcoinAddressService
 * @author      PayCrypto.Me
 * @copyright   2025 PayCrypto.Me
 * @license     GNU General Public License v3.0
 */

namespace PayCryptoMe\WooCommerce;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Buffertools\Buffer;

\defined('ABSPATH') || exit;

class BitcoinAddressService
{
    private array $prefixMap = [
        // mainnet
        'xpub' => ['hex' => '0488b21e', 'type' => 'p2pkh'],
        'ypub' => ['hex' => '049d7cb2', 'type' => 'p2sh-p2wpkh'],
        'zpub' => ['hex' => '04b24746', 'type' => 'p2wpkh'],
        // testnet
        'tpub' => ['hex' => '043587cf', 'type' => 'p2pkh'],
        'upub' => ['hex' => '044a5262', 'type' => 'p2sh-p2wpkh'],
        'vpub' => ['hex' => '045f1cf6', 'type' => 'p2wpkh'],
    ];

    private $hdFactory;
    private $addressCreator;

    public function __construct(?HierarchicalKeyFactory $hdFactory = null, ?AddressCreator $addressCreator = null)
    {
        $this->hdFactory = $hdFactory ?? new HierarchicalKeyFactory();
        $this->addressCreator = $addressCreator ?? new AddressCreator();
    }

    /**
     * Generate an address from an extended public key (xpub/ypub/zpub...)
     *
     * This method is intentionally thin: it validates inputs, derives the
     * child public key and then delegates to small, testable generator helpers
     * which produce the final address string.
     *
     * @param string $xPub Extended public key
     * @param int $index Address index (>= 0)
     * @param NetworkInterface $network Network object
     * @param string|null $forceType Optional force address type (p2pkh|p2sh-p2wpkh|p2wpkh)
     * @return string
     */
    public function generate_address_from_xPub(string $xPub, int $index, NetworkInterface $network, ?string $forceType = null): string
    {
        if ($index < 0) {
            throw new \InvalidArgumentException('Derivation index must be a non-negative integer.');
        }

        $currentPrefix = $this->get_prefix_from_xpub($xPub);

        $converted = $this->convert_extended_pubkey_prefix($xPub, $network);
        $hdKey = $this->hdFactory->fromExtended($converted, $network);

        // Do NOT attempt to derive hardened paths (those with a trailing ').
        // Hardened derivation requires the private key; deriving hardened
        // children from an extended public key will fail. Instead, assume the
        // provided extended pubkey is at (or above) the account/external level
        // and derive the external chain child `0/{index}` non-hardened.
        $childKey = $hdKey->derivePath("0/{$index}");
        $publicKey = $childKey->getPublicKey();

        // Ensure the provided extended pubkey is an account-level key.
        // Account-level keys typically have depth >= 3 (e.g. m/84'/1'/0').

        // $depth = $hdKey->getDepth();
        // if ($depth < 3) {
        //     // Continue deriving from the provided node (external chain 0). This
        //     // allows using vpub/upub/etc. even when they are not account-level,
        //     // but wallets may not recognise these addresses as the same account.
        // }

        $publicKeyHash = $publicKey->getPubKeyHash();

        if ($forceType !== null) {
            $type = $forceType;
        } else {
            try {
                $meta = $this->get_prefix_meta($currentPrefix);
                $type = $meta['type'];
            } catch (\InvalidArgumentException $e) {
                $network->register_paycrypto_me_log(\sprintf('Unsupported extended public key prefix: %s. Falling back to bech32 address generation.', $currentPrefix), 'warning');
                $type = 'p2wpkh';
            }
        }

        switch ($type) {
            case 'p2pkh':
                return $this->generate_p2pkh_from_pubhash($publicKeyHash, $network);

            case 'p2sh-p2wpkh':
                return $this->generate_p2sh_p2wpkh_from_pubhash($publicKeyHash, $network);

            case 'p2wpkh':
            default:
                return $this->generate_p2wpkh_from_pubhash($publicKeyHash, $network);
        }
    }

    public function get_prefix_from_xpub(string $xPub): string
    {
        return substr($xPub, 0, 4);
    }

    public function get_prefix_map(): array
    {
        return $this->prefixMap;
    }

    private function generate_p2pkh_from_pubhash($publicKeyHash, NetworkInterface $network): string
    {
        $scriptPubKey = ScriptFactory::scriptPubKey()->payToPubKeyHash($publicKeyHash);
        $addr = $this->addressCreator->fromOutputScript($scriptPubKey, $network);
        return $addr->getAddress($network);
    }

    private function generate_p2wpkh_from_pubhash($publicKeyHash, NetworkInterface $network): string
    {
        $witnessProgram = WitnessProgram::v0($publicKeyHash);
        $address = new SegwitAddress($witnessProgram);
        return $address->getAddress($network);
    }

    private function generate_p2sh_p2wpkh_from_pubhash($publicKeyHash, NetworkInterface $network): string
    {
        $redeemScript = ScriptFactory::scriptPubKey()->witnessKeyHash($publicKeyHash);
        $redeemScriptHash = $redeemScript->getScriptHash();
        $p2shScript = ScriptFactory::scriptPubKey()->payToScriptHash($redeemScriptHash);
        $addr = $this->addressCreator->fromOutputScript($p2shScript, $network);
        return $addr->getAddress($network);
    }

    private function get_prefix_meta(string $prefix): array
    {
        if (!isset($this->prefixMap[$prefix])) {
            throw new \InvalidArgumentException("Unsupported extended public key prefix: {$prefix}");
        }

        return $this->prefixMap[$prefix];
    }

    public function convert_extended_pubkey_prefix(string $xPub, ?NetworkInterface $network = null): string
    {
        $currentPrefix = substr($xPub, 0, 4);

        $meta = $this->get_prefix_meta($currentPrefix);

        $newHex = $network !== null ? $network->getHDPubByte() : $meta['hex'];

        $buffer = Base58::decodeCheck($xPub);

        $hexData = $buffer->getHex();
        $newHexData = $newHex . substr($hexData, 8);
        $newBuffer = Buffer::hex($newHexData);
        $converted = Base58::encodeCheck($newBuffer);

        return $converted;
    }

    public function validate_bitcoin_address(string $address, NetworkInterface $network): bool
    {
        try {
            $addressCreator = new AddressCreator();
            $addressCreator->fromString($address, $network);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function validate_extended_pubkey(string $xPub, NetworkInterface $network): bool
    {

        try {
            $replaceHex = $this->convert_extended_pubkey_prefix($xPub, $network);

            $hdFactory = new HierarchicalKeyFactory();
            $hdFactory->fromExtended($replaceHex, $network);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function build_bitcoin_payment_uri(string $address, ?float $amount = null, ?string $label = null, ?string $message = null): string
    {
        $uri = "bitcoin:{$address}";

        $params = [];

        if ($amount !== null) {
            $params['amount'] = number_format($amount, 8, '.', '');
        }

        if ($label !== null) {
            $params['label'] = $label;
        }

        if ($message !== null) {
            $params['message'] = $message;
        }

        if (!empty($params)) {
            $uri .= '?' . http_build_query($params);
        }

        return $uri;
    }
}