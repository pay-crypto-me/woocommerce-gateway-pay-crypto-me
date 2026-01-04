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
    echo "Usage: php derive_index.php <vpub> <index>\n";
    exit(1);
}
$vpub = $argv[1];
$index = (int)$argv[2];
$network = NetworkFactory::bitcoinTestnet();

$prefixMap = [
    'xpub' => ['hex' => '0488b21e', 'type' => 'p2pkh'],
    'ypub' => ['hex' => '049d7cb2', 'type' => 'p2sh-p2wpkh'],
    'zpub' => ['hex' => '04b24746', 'type' => 'p2wpkh'],
    'tpub' => ['hex' => '043587cf', 'type' => 'p2pkh'],
    'upub' => ['hex' => '044a5262', 'type' => 'p2sh-p2wpkh'],
    'vpub' => ['hex' => '045f1cf6', 'type' => 'p2wpkh'],
];
$currentPrefix = substr($vpub,0,4);
$meta = $prefixMap[$currentPrefix] ?? null;
if (!$meta) { echo "Unknown prefix\n"; exit(1); }

$buf = Base58::decodeCheck($vpub);
$hex = $buf->getHex();
$newHex = $network->getHDPubByte() . substr($hex, 8);
$newBuf = Buffer::hex($newHex);
$converted = Base58::encodeCheck($newBuf);

$hdFactory = new HierarchicalKeyFactory();
$hd = $hdFactory->fromExtended($converted, $network);

$addrCreator = new AddressCreator();
$type = $meta['type'];

$child = $hd->derivePath("0/{$index}");
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
}

echo "index={$index} => {$out}\n";

echo "child depth=" . $child->getDepth() . " sequence=" . $child->getChildNumber() . "\n";

exit(0);
