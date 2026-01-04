<?php
require_once __DIR__ . '/../vendor/autoload.php';

use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Script\WitnessProgram;
use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Base58;
use BitWasp\Buffertools\Buffer;

if ($argc < 3) {
    echo "Usage: php find_address_index.php <vpub> <address> [max=1000]\n";
    exit(1);
}

$vpub = $argv[1];
$target = $argv[2];
$max = isset($argv[3]) ? (int)$argv[3] : 1000;

$network = NetworkFactory::bitcoinTestnet();

$prefixMap = [
    'xpub' => ['hex' => '0488b21e', 'type' => 'p2pkh'],
    'ypub' => ['hex' => '049d7cb2', 'type' => 'p2sh-p2wpkh'],
    'zpub' => ['hex' => '04b24746', 'type' => 'p2wpkh'],
    'tpub' => ['hex' => '043587cf', 'type' => 'p2pkh'],
    'upub' => ['hex' => '044a5262', 'type' => 'p2sh-p2wpkh'],
    'vpub' => ['hex' => '045f1cf6', 'type' => 'p2wpkh'],
];

$currentPrefix = substr($vpub, 0, 4);
$meta = $prefixMap[$currentPrefix] ?? null;
if (!$meta) {
    echo "Unknown prefix: $currentPrefix\n";
    exit(1);
}

try {
    $buf = Base58::decodeCheck($vpub);
} catch (Exception $e) {
    echo "Base58 decode error: " . $e->getMessage() . "\n";
    exit(1);
}
$hex = $buf->getHex();
$newHex = $network->getHDPubByte() . substr($hex, 8);
$newBuf = Buffer::hex($newHex);
$converted = Base58::encodeCheck($newBuf);

$hdFactory = new HierarchicalKeyFactory();
try {
    $hd = $hdFactory->fromExtended($converted, $network);
} catch (Exception $e) {
    echo "fromExtended failed: " . $e->getMessage() . "\n";
    exit(1);
}

$addrCreator = new AddressCreator();
$type = $meta['type'];

for ($i = 0; $i <= $max; $i++) {
    try {
        $child = $hd->derivePath("0/{$i}");
        $pub = $child->getPublicKey();
        $pubHash = $pub->getPubKeyHash();

        switch ($type) {
            case 'p2pkh':
                $script = ScriptFactory::scriptPubKey()->payToPubKeyHash($pubHash);
                $addr = $addrCreator->fromOutputScript($script, $network);
                $out = $addr->getAddress($network);
                break;
            case 'p2sh-p2wpkh':
                $redeem = ScriptFactory::scriptPubKey()->witnessKeyHash($pubHash);
                $redeemHash = $redeem->getScriptHash();
                $script = ScriptFactory::scriptPubKey()->payToScriptHash($redeemHash);
                $addr = $addrCreator->fromOutputScript($script, $network);
                $out = $addr->getAddress($network);
                break;
            case 'p2wpkh':
            default:
                $w = WitnessProgram::v0($pubHash);
                $addr = new SegwitAddress($w);
                $out = $addr->getAddress($network);
                break;
        }

        if ($out === $target) {
            echo "FOUND index={$i}\n";
            exit(0);
        }

    } catch (Exception $e) {
        // ignore and continue
    }
}

echo "NOT FOUND in 0..{$max}\n";
exit(0);
