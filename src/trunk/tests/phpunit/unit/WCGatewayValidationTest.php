<?php
use PHPUnit\Framework\TestCase;

class WCGatewayValidationTest extends TestCase
{
    private function setPrivateProperty(object $obj, string $name, $value): void
    {
        $rc = new \ReflectionObject($obj);
        while (!$rc->hasProperty($name) && $rc->getParentClass()) {
            $rc = $rc->getParentClass();
        }
        $prop = $rc->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($obj, $value);
    }

    public function test_validate_network_identifier_accepts_xpub()
    {
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        $svc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $svc->method('prefix_matches_network')->willReturn(true);
        $svc->method('validate_extended_pubkey')->willReturn(true);
        $svc->method('validate_bitcoin_address')->willReturn(false);

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', $svc);

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $ok = $m->invoke($gateway, 'mainnet', 'xpubFAKE');
        $this->assertTrue($ok, 'Expected xpub to be accepted');
    }

    public function test_validate_network_identifier_accepts_address()
    {
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        $svc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $svc->method('validate_extended_pubkey')->willReturn(false);
        $svc->method('validate_bitcoin_address')->willReturn(true);

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', $svc);

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $ok = $m->invoke($gateway, 'mainnet', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa');
        $this->assertTrue($ok, 'Expected P2PKH address to be accepted');
    }

    public function test_validate_network_identifier_rejects_and_logs()
    {
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        // Expect a log call when validation fails
        $gateway->expects($this->once())->method('register_paycrypto_me_log');

        $svc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $svc->method('prefix_matches_network')->willReturn(true);
        $svc->method('validate_extended_pubkey')->willReturn(false);
        $svc->method('validate_bitcoin_address')->willReturn(false);

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', $svc);

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $ok = $m->invoke($gateway, 'mainnet', 'notavalidid');
        $this->assertFalse($ok, 'Expected invalid identifier to be rejected');
    }

    public function test_validate_network_identifier_rejects_testnet_xpub_on_mainnet()
    {
        // Regression test for C1: convert_extended_pubkey_prefix() used to rewrite the
        // version bytes to the target network *before* validating, so a testnet tpub was
        // silently accepted as a valid mainnet key. prefix_matches_network() must catch this.
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', new \PayCryptoMe\WooCommerce\BitcoinAddressService());

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $tpub = 'tpubDCbMks4NTuatj9Hu8quz2tiCcKxH7Pa6sEfEMio175z2d2uvRwB9SErJS6BZJ7ndWj9adLNihLhyfhAyXSivBWPiTuQqMwkUbvw6SrTrZoT';
        $ok = $m->invoke($gateway, 'mainnet', $tpub);
        $this->assertFalse($ok, 'A testnet tpub must be rejected when Mainnet is the selected network');
    }

    public function test_validate_network_identifier_rejects_mainnet_xpub_on_testnet()
    {
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', new \PayCryptoMe\WooCommerce\BitcoinAddressService());

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $xpub = 'xpub6BmGNiA6M7CTF1nDvz7muM4HrK4dYGu3V36jsUDZTnqo7tCyyVRoVYz6nhhC2HHGXoTcZzEWC7KLAykkTutVFq3r3zHktaoRgQ4PyZyBULh';
        $ok = $m->invoke($gateway, 'testnet', $xpub);
        $this->assertFalse($ok, 'A mainnet xpub must be rejected when Testnet is the selected network');
    }

    public function test_is_xpub_network_mismatch_detects_wrong_network_only()
    {
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', new \PayCryptoMe\WooCommerce\BitcoinAddressService());

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'is_xpub_network_mismatch');
        $m->setAccessible(true);

        $tpub = 'tpubDCbMks4NTuatj9Hu8quz2tiCcKxH7Pa6sEfEMio175z2d2uvRwB9SErJS6BZJ7ndWj9adLNihLhyfhAyXSivBWPiTuQqMwkUbvw6SrTrZoT';
        $this->assertTrue($m->invoke($gateway, 'mainnet', $tpub));
        $this->assertFalse($m->invoke($gateway, 'testnet', $tpub));
        $this->assertFalse($m->invoke($gateway, 'mainnet', 'not-an-xpub-at-all'));
    }

    public function test_admin_enqueue_scripts_content_handles_null_screen_without_fatal()
    {
        // Regression test (Part 4 one-liner): `&&` binds tighter than `||`, so
        // `$screen && $screen->id === 'x' || $screen->id === 'y'` only guarded the first
        // half — a null $screen (a real case: admin hooks can fire outside a screen context)
        // still dereferenced $screen->id in the second half and fatal'd.
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->getMock();

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'admin_enqueue_scripts_content');
        $m->setAccessible(true);

        $m->invoke($gateway, null);
        $this->addToAssertionCount(1);
    }

    public function test_mask_identifier_for_log_behaviour()
    {
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->getMock();

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'mask_identifier_for_log');
        $m->setAccessible(true);

        $long = 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKp1s7tamS8W';
        $masked = $m->invoke($gateway, 'mainnet', $long);
        $this->assertStringContainsString('...', $masked);
        $this->assertLessThan(strlen($long), strlen($masked));
    }

    public function test_validate_network_identifier_converts_internal_errors_into_a_typed_exception()
    {
        // Regression for the reported bug: on a host without GMP, Base58::decode() throws
        // "Call to undefined function gmp_init()". That \Error used to be swallowed into `false`,
        // so the admin was told their (perfectly valid) xPub was invalid for the selected network.
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        $svc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $svc->method('prefix_matches_network')->willReturn(true);
        $svc->method('validate_extended_pubkey')
            ->willThrowException(new \Error('Call to undefined function BitWasp\\Bitcoin\\gmp_init()'));

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', $svc);

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $this->expectException(\PayCryptoMe\WooCommerce\PayCryptoMeException::class);
        $this->expectExceptionMessageMatches('/gmp_init/');

        $m->invoke($gateway, 'mainnet', 'zpubSOMETHING');
    }

    public function test_validate_network_identifier_converts_address_internal_errors_too()
    {
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        $svc = $this->createMock(\PayCryptoMe\WooCommerce\BitcoinAddressService::class);
        $svc->method('prefix_matches_network')->willReturn(true);
        $svc->method('validate_extended_pubkey')->willReturn(false);
        $svc->method('validate_bitcoin_address')->willThrowException(new \Error('Call to undefined function gmp_init()'));

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', $svc);

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        // '1...' matches a mainnet address prefix, so the static-address branch is reached.
        $this->expectException(\PayCryptoMe\WooCommerce\PayCryptoMeException::class);

        $m->invoke($gateway, 'mainnet', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa');
    }

    public function test_validate_network_identifier_still_returns_false_for_a_genuinely_invalid_key()
    {
        // The typed exception above must not swallow the ordinary rejection path.
        $gateway = $this->getMockBuilder(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['register_paycrypto_me_log'])
            ->getMock();

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', new \PayCryptoMe\WooCommerce\BitcoinAddressService());

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $this->assertFalse($m->invoke($gateway, 'mainnet', 'xpubTHISISNOTAVALIDKEY'));
    }
}
