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
use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Buffertools\Buffer;

\defined('ABSPATH') || exit;

class BitcoinAddressService
{
    private $availablePrefixes = [
        'xpub' => '0488b21e',
        'ypub' => '049d7cb2',
        'zpub' => '04b24746',
        'tpub' => '043587cf',
        'upub' => '044a5262',
        'vpub' => '045f1cf6',
    ];

    public function generate_address_from_xPub(string $xPub, int $index, NetworkInterface $network): string
    {
        $hdFactory = new HierarchicalKeyFactory();

        $replaceHex = $this->convert_extended_pubkey_prefix($xPub);

        $hdKey = $hdFactory->fromExtended($replaceHex, $network);
        $childKey = $hdKey->derivePath("0/{$index}");

        $publicKey = $childKey->getPublicKey();
        $publicKeyHash = $publicKey->getPubKeyHash();

        $witnessProgram = WitnessProgram::v0($publicKeyHash);
        $address = new SegwitAddress($witnessProgram);

        return $address->getAddress($network);
    }

    public function convert_extended_pubkey_prefix(string $xPub): string
    {
        $currentPrefix = substr($xPub, 0, 4);

        $newHex = match ($currentPrefix) {
            'xpub', 'ypub', 'zpub' => $this->availablePrefixes['xpub'],
            'tpub', 'upub', 'vpub' => $this->availablePrefixes['tpub'],
            default => throw new \InvalidArgumentException("Unsupported extended public key prefix: {$currentPrefix}"),
        };

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
            $replaceHex = $this->convert_extended_pubkey_prefix($xPub);

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