<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BitcoinAddressService;
use BitWasp\Bitcoin\Network\NetworkFactory;

require_once __DIR__ . '/../../includes/services/class-bitcoin-address-service.php';

class BitcoinAddressVectorsTest extends TestCase
{
    public function test_vectors()
    {
        $json = file_get_contents(__DIR__ . '/../vectors/bitcoin_addresses.json');
        $vectors = json_decode($json, true);

        $svc = new BitcoinAddressService();

        foreach ($vectors as $entry) {
            $network = $entry['network'] === 'mainnet' ? NetworkFactory::bitcoin() : NetworkFactory::bitcoinTestnet();
            $xpub = $entry['xpub'];

            foreach ($entry['addresses'] as $addr) {
                $index = $addr['index'];
                $expected = $addr['address'];

                $got = $svc->generate_address_from_xPub($xpub, $index, $network, null);

                $this->assertEquals($expected, $got, sprintf('%s %s index %d', $entry['prefix'], $entry['network'], $index));
            }
        }
    }
}
