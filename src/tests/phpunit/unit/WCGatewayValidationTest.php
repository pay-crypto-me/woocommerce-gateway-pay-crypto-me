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
        $svc->method('validate_extended_pubkey')->willReturn(false);
        $svc->method('validate_bitcoin_address')->willReturn(false);

        $this->setPrivateProperty($gateway, 'bitcoin_address_service', $svc);

        $m = new \ReflectionMethod(\PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe::class, 'validate_network_identifier');
        $m->setAccessible(true);

        $ok = $m->invoke($gateway, 'mainnet', 'notavalidid');
        $this->assertFalse($ok, 'Expected invalid identifier to be rejected');
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

        $lightning = 'user@example.com';
        if (!function_exists('is_email')) {
            function is_email($v) { return filter_var($v, FILTER_VALIDATE_EMAIL) !== false; }
        }
        $masked2 = $m->invoke($gateway, 'lightning', $lightning);
        $this->assertStringContainsString('@', $masked2);
        $this->assertStringContainsString('***', $masked2);
    }
}
