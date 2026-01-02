<?php
require __DIR__ . '/../../vendor/autoload.php';

use BitWasp\Buffertools\Buffer;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Address\AddressCreator;

// Deterministic entropy (not committed). We will NOT store this seed.
$seedHexMain = hash('sha256', 'paycrypto-me-vector-seed-main');
$seedHexTest = hash('sha256', 'paycrypto-me-vector-seed-test');

$factory = new HierarchicalKeyFactory();

$networks = [
    'mainnet' => NetworkFactory::bitcoin(),
    'testnet' => NetworkFactory::bitcoinTestnet(),
];

$prefixMap = [
    'xpub' => '0488b21e', 'ypub' => '049d7cb2', 'zpub' => '04b24746',
    'tpub' => '043587cf', 'upub' => '044a5262', 'vpub' => '045f1cf6',
];

$vectors = [];

foreach ($networks as $netName => $network) {
    $seedHex = $netName === 'mainnet' ? $seedHexMain : $seedHexTest;
    $entropy = Buffer::hex($seedHex);
    $master = $factory->fromEntropy($entropy);

    // Derive BIP44 account m/44'/0'/0' for mainnet, m/44'/1'/0' for testnet
    $coin = $netName === 'mainnet' ? 0 : 1;
    $accountPath = "44'/$coin'/0'";
    $account = $master->derivePath($accountPath);

    // Use external chain 0
    $change = '0';

    // Build base (standard xpub/tpub)
    $baseXpub = $account->withoutPrivateKey()->toExtendedPublicKey($network);

    // Prepare address creator and script factory
    $addressCreator = new AddressCreator();

    // For each variant prefix, produce an extended public key string by replacing version bytes
    foreach ($prefixMap as $prefix => $hex) {
        // take base xpub (which uses network's default version) and replace its version
        $buffer = \BitWasp\Bitcoin\Base58::decodeCheck($baseXpub);
        $hexData = $buffer->getHex();
        $newHexData = $hex . substr($hexData, 8);
        $convertedXpub = \BitWasp\Buffertools\Buffer::hex($newHexData);
        $converted = \BitWasp\Bitcoin\Base58::encodeCheck($convertedXpub);

        $entry = [
            'network' => $netName,
            'prefix' => $prefix,
            'xpub' => $converted,
            'addresses' => [],
        ];

        for ($i = 0; $i < 5; $i++) {
            $child = $account->withoutPrivateKey()->derivePath("{$change}/{$i}");
            $pubHash = $child->getPublicKey()->getPubKeyHash();

            // determine type from prefix
            $type = 'p2wpkh';
            if (in_array($prefix, ['xpub','tpub'])) $type = 'p2pkh';
            if (in_array($prefix, ['ypub','upub'])) $type = 'p2sh-p2wpkh';

            if ($type === 'p2pkh') {
                $script = ScriptFactory::scriptPubKey()->payToPubKeyHash($pubHash);
                $addr = $addressCreator->fromOutputScript($script, $network)->getAddress($network);
            } elseif ($type === 'p2sh-p2wpkh') {
                $redeem = ScriptFactory::scriptPubKey()->witnessKeyHash($pubHash);
                $p2sh = ScriptFactory::scriptPubKey()->payToScriptHash($redeem->getScriptHash());
                $addr = $addressCreator->fromOutputScript($p2sh, $network)->getAddress($network);
            } else {
                $witness = \BitWasp\Bitcoin\Script\WitnessProgram::v0($pubHash);
                $addr = (new \BitWasp\Bitcoin\Address\SegwitAddress($witness))->getAddress($network);
            }

            $entry['addresses'][] = [
                'index' => $i,
                'address' => $addr,
                'type' => $type,
            ];
        }

        $vectors[] = $entry;
    }
}

file_put_contents(__DIR__ . '/../vectors/bitcoin_addresses.json', json_encode($vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Vectors written to tests/vectors/bitcoin_addresses.json\n";
