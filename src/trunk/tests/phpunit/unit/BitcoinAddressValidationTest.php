<?php
use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\BitcoinAddressService;
use BitWasp\Bitcoin\Network\NetworkFactory;

class BitcoinAddressValidationTest extends TestCase
{
    private const MAINNET_XPUB = 'xpub6BmGNiA6M7CTF1nDvz7muM4HrK4dYGu3V36jsUDZTnqo7tCyyVRoVYz6nhhC2HHGXoTcZzEWC7KLAykkTutVFq3r3zHktaoRgQ4PyZyBULh';
    private const MAINNET_ZPUB = 'zpub6qRnz3VveUHQwcATbhh2KXFJCFMXRWt3KG9BSG1LDobZE5qSUokvjgJNq7cN26b7M5hE4wRd7S2RwYysuJiWrJR3nfgc4QSQDrBgkg6VVFZ';
    private const TESTNET_TPUB = 'tpubDCbMks4NTuatj9Hu8quz2tiCcKxH7Pa6sEfEMio175z2d2uvRwB9SErJS6BZJ7ndWj9adLNihLhyfhAyXSivBWPiTuQqMwkUbvw6SrTrZoT';

    private const MAINNET_P2PKH_ADDRESS = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';
    private const MAINNET_BECH32_ADDRESS = 'bc1qw79xn4m4le2f5k9evfhvrhpqkunpywtxr552gz';
    private const TESTNET_BECH32_ADDRESS = 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx';
    private const TESTNET_P2PKH_ADDRESS = 'msohpncHRZ77VqoVmw8M4sNoM5zkfZCJNX';

    private BitcoinAddressService $svc;
    private int $originalErrorReporting = 0;

    protected function setUp(): void
    {
        $this->svc = new BitcoinAddressService();
        $this->originalErrorReporting = error_reporting();
    }

    protected function tearDown(): void
    {
        // The suppress_vendor_deprecations() tests below set error_reporting(); never leak it.
        error_reporting($this->originalErrorReporting);
    }

    private function invokeSuppress(callable $fn)
    {
        $method = new \ReflectionMethod(BitcoinAddressService::class, 'suppress_vendor_deprecations');
        $method->setAccessible(true);

        return $method->invoke(null, $fn);
    }

    // --- validate_extended_pubkey() ---------------------------------------------------

    public function test_validate_extended_pubkey_accepts_mainnet_xpub_on_mainnet()
    {
        $this->assertTrue($this->svc->validate_extended_pubkey(self::MAINNET_XPUB, NetworkFactory::bitcoin()));
    }

    public function test_validate_extended_pubkey_accepts_mainnet_zpub_on_mainnet()
    {
        $this->assertTrue($this->svc->validate_extended_pubkey(self::MAINNET_ZPUB, NetworkFactory::bitcoin()));
    }

    public function test_validate_extended_pubkey_accepts_testnet_tpub_on_testnet()
    {
        $this->assertTrue($this->svc->validate_extended_pubkey(self::TESTNET_TPUB, NetworkFactory::bitcoinTestnet()));
    }

    /**
     * Regression: this exact key was reported as "not valid for the selected network" on a host
     * without the GMP extension. It is a structurally valid mainnet zpub (checksum OK, version
     * bytes 04b24746, depth 3, public key on the secp256k1 curve) and must validate.
     */
    public function test_validate_extended_pubkey_accepts_the_reported_mainnet_zpub()
    {
        $reported = 'zpub6r7r5ebZiA4YzDmWjh9ic7nqwLrfTvFrnGRM7bLaB8vNYbbftcKy9Q5t8sjFXmGUEhT7toijdbJqMGcb3Nqk4dZy1xPJCGZJ35zGSGHmhzN';

        $this->assertTrue($this->svc->validate_extended_pubkey($reported, NetworkFactory::bitcoin()));
    }

    /**
     * A missing PHP extension surfaces as an \Error, not an \Exception. Swallowing it into a
     * plain `false` is what made the valid key above be reported as the user's mistake, so the
     * service must let it through for the caller to name the real cause.
     */
    public function test_validate_extended_pubkey_lets_internal_errors_propagate()
    {
        $svc = new class extends BitcoinAddressService {
            public function convert_extended_pubkey_prefix(string $xPub, ?\BitWasp\Bitcoin\Network\NetworkInterface $network = null): string
            {
                throw new \Error('Call to undefined function BitWasp\\Bitcoin\\gmp_init()');
            }
        };

        $this->expectException(\Error::class);

        $svc->validate_extended_pubkey(self::MAINNET_XPUB, NetworkFactory::bitcoin());
    }

    public function test_validate_bitcoin_address_lets_internal_errors_propagate()
    {
        $creator = $this->createMock(\BitWasp\Bitcoin\Address\AddressCreator::class);
        $creator->method('fromString')->willThrowException(new \Error('Call to undefined function gmp_init()'));

        $svc = new BitcoinAddressService(null, $creator);

        $this->expectException(\Error::class);

        $svc->validate_bitcoin_address(self::MAINNET_P2PKH_ADDRESS, NetworkFactory::bitcoin());
    }

    public function test_validate_bitcoin_address_still_returns_false_for_parse_exceptions()
    {
        $creator = $this->createMock(\BitWasp\Bitcoin\Address\AddressCreator::class);
        $creator->method('fromString')->willThrowException(new \InvalidArgumentException('not an address'));

        $svc = new BitcoinAddressService(null, $creator);

        $this->assertFalse($svc->validate_bitcoin_address('whatever', NetworkFactory::bitcoin()));
    }

    /**
     * Regression: this path built `new HierarchicalKeyFactory()` internally and dropped the injected
     * one on the floor — the same defect already fixed in validate_bitcoin_address(). A mock that is
     * never consulted made the method look tested while it was talking to the real library.
     */
    public function test_validate_extended_pubkey_uses_the_injected_hd_factory()
    {
        $hdFactory = $this->createMock(\BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory::class);
        $hdFactory->expects($this->once())
            ->method('fromExtended')
            ->willThrowException(new \RuntimeException('injected factory reached'));

        $svc = new BitcoinAddressService($hdFactory);

        $this->assertFalse($svc->validate_extended_pubkey(self::MAINNET_XPUB, NetworkFactory::bitcoin()));
    }

    public function test_validate_extended_pubkey_rejects_unsupported_prefix()
    {
        $this->assertFalse($this->svc->validate_extended_pubkey('foo1NotARealPrefixKey', NetworkFactory::bitcoin()));
    }

    public function test_validate_extended_pubkey_rejects_corrupted_checksum()
    {
        $corrupted = substr(self::MAINNET_XPUB, 0, -1) . (self::MAINNET_XPUB[-1] === 'a' ? 'b' : 'a');

        $this->assertFalse($this->svc->validate_extended_pubkey($corrupted, NetworkFactory::bitcoin()));
    }

    public function test_validate_extended_pubkey_rejects_garbage_string()
    {
        $this->assertFalse($this->svc->validate_extended_pubkey('not-an-xpub-at-all', NetworkFactory::bitcoin()));
    }

    public function test_validate_extended_pubkey_invokes_logger_on_failure()
    {
        $logged = [];
        $logger = function ($message, $level) use (&$logged) {
            $logged[] = [$message, $level];
        };

        $this->svc->validate_extended_pubkey('not-an-xpub-at-all', NetworkFactory::bitcoin(), $logger);

        $this->assertCount(1, $logged);
        $this->assertSame('debug', $logged[0][1]);
    }

    // --- prefix_matches_network() ------------------------------------------------------

    public function test_prefix_matches_network_accepts_matching_mainnet_xpub()
    {
        $this->assertTrue($this->svc->prefix_matches_network(self::MAINNET_XPUB, 'mainnet'));
    }

    public function test_prefix_matches_network_accepts_matching_testnet_tpub()
    {
        $this->assertTrue($this->svc->prefix_matches_network(self::TESTNET_TPUB, 'testnet'));
    }

    public function test_prefix_matches_network_rejects_testnet_tpub_on_mainnet()
    {
        $this->assertFalse($this->svc->prefix_matches_network(self::TESTNET_TPUB, 'mainnet'));
    }

    public function test_prefix_matches_network_rejects_mainnet_xpub_on_testnet()
    {
        $this->assertFalse($this->svc->prefix_matches_network(self::MAINNET_XPUB, 'testnet'));
    }

    public function test_prefix_matches_network_ignores_unknown_prefix()
    {
        $this->assertTrue($this->svc->prefix_matches_network('not-an-xpub-at-all', 'mainnet'));
        $this->assertTrue($this->svc->prefix_matches_network(self::MAINNET_P2PKH_ADDRESS, 'testnet'));
    }

    // --- validate_bitcoin_address() ---------------------------------------------------

    public function test_validate_bitcoin_address_accepts_mainnet_p2pkh_on_mainnet()
    {
        $this->assertTrue($this->svc->validate_bitcoin_address(self::MAINNET_P2PKH_ADDRESS, NetworkFactory::bitcoin()));
    }

    public function test_validate_bitcoin_address_accepts_mainnet_bech32_on_mainnet()
    {
        $this->assertTrue($this->svc->validate_bitcoin_address(self::MAINNET_BECH32_ADDRESS, NetworkFactory::bitcoin()));
    }

    public function test_validate_bitcoin_address_accepts_testnet_p2pkh_on_testnet()
    {
        $this->assertTrue($this->svc->validate_bitcoin_address(self::TESTNET_P2PKH_ADDRESS, NetworkFactory::bitcoinTestnet()));
    }

    public function test_validate_bitcoin_address_rejects_mainnet_address_on_testnet_network()
    {
        $this->assertFalse($this->svc->validate_bitcoin_address(self::MAINNET_P2PKH_ADDRESS, NetworkFactory::bitcoinTestnet()));
    }

    public function test_validate_bitcoin_address_rejects_testnet_address_on_mainnet_network()
    {
        $this->assertFalse($this->svc->validate_bitcoin_address(self::TESTNET_P2PKH_ADDRESS, NetworkFactory::bitcoin()));
    }

    public function test_validate_bitcoin_address_rejects_garbage_string()
    {
        $this->assertFalse($this->svc->validate_bitcoin_address('not-a-real-address', NetworkFactory::bitcoin()));
    }

    public function test_validate_bitcoin_address_invokes_logger_on_failure()
    {
        $logged = [];
        $logger = function ($message, $level) use (&$logged) {
            $logged[] = [$message, $level];
        };

        $this->svc->validate_bitcoin_address('not-a-real-address', NetworkFactory::bitcoin(), $logger);

        $this->assertCount(1, $logged);
        $this->assertSame('debug', $logged[0][1]);
    }

    // --- build_bitcoin_payment_uri() ---------------------------------------------------

    public function test_build_bitcoin_payment_uri_address_only()
    {
        $this->assertSame(
            'bitcoin:' . self::MAINNET_P2PKH_ADDRESS,
            $this->svc->build_bitcoin_payment_uri(self::MAINNET_P2PKH_ADDRESS)
        );
    }

    public function test_build_bitcoin_payment_uri_with_amount_formats_eight_decimals()
    {
        $uri = $this->svc->build_bitcoin_payment_uri(self::MAINNET_P2PKH_ADDRESS, 0.001);

        $this->assertSame('bitcoin:' . self::MAINNET_P2PKH_ADDRESS . '?amount=0.00100000', $uri);
    }

    public function test_build_bitcoin_payment_uri_with_label_and_message_are_url_encoded()
    {
        $uri = $this->svc->build_bitcoin_payment_uri(self::MAINNET_P2PKH_ADDRESS, null, 'John Doe', 'Order #1 & 2');

        $this->assertSame(
            'bitcoin:' . self::MAINNET_P2PKH_ADDRESS . '?label=John+Doe&message=Order+%231+%26+2',
            $uri
        );
    }

    public function test_build_bitcoin_payment_uri_with_amount_label_and_message()
    {
        $uri = $this->svc->build_bitcoin_payment_uri(self::MAINNET_P2PKH_ADDRESS, 1.5, 'Alice', 'Thanks');

        $this->assertSame(
            'bitcoin:' . self::MAINNET_P2PKH_ADDRESS . '?amount=1.50000000&label=Alice&message=Thanks',
            $uri
        );
    }

    // --- requires_gmp_math() / segwit-without-GMP -------------------------------------

    public function test_requires_gmp_math_is_false_for_a_mainnet_bech32_address()
    {
        // The whole point: a fixed bc1 address needs no big-integer math, so a host without the
        // GMP extension can still take on-chain payments to it.
        $this->assertFalse($this->svc->requires_gmp_math(self::MAINNET_BECH32_ADDRESS, NetworkFactory::bitcoin()));
    }

    public function test_requires_gmp_math_is_true_for_xpubs_and_base58_addresses()
    {
        $this->assertTrue($this->svc->requires_gmp_math(self::MAINNET_XPUB, NetworkFactory::bitcoin()));
        $this->assertTrue($this->svc->requires_gmp_math(self::MAINNET_P2PKH_ADDRESS, NetworkFactory::bitcoin()));
    }

    public function test_requires_gmp_math_is_true_for_an_empty_identifier()
    {
        // Nothing configured yet is not an opt-in to the fixed-address route.
        $this->assertTrue($this->svc->requires_gmp_math('', NetworkFactory::bitcoin()));
    }

    public function test_requires_gmp_math_respects_the_network_prefix()
    {
        // A bc1 address is not a testnet segwit address, so it does not get the pure-PHP path.
        $this->assertTrue($this->svc->requires_gmp_math(self::MAINNET_BECH32_ADDRESS, NetworkFactory::bitcoinTestnet()));
        $this->assertFalse($this->svc->requires_gmp_math(self::TESTNET_BECH32_ADDRESS, NetworkFactory::bitcoinTestnet()));
    }

    public function test_requires_gmp_math_is_case_insensitive()
    {
        // bech32 addresses are valid in upper case too.
        $this->assertFalse($this->svc->requires_gmp_math(strtoupper(self::MAINNET_BECH32_ADDRESS), NetworkFactory::bitcoin()));
    }

    public function test_validate_segwit_address_accepts_a_valid_bech32_address()
    {
        $this->assertTrue($this->svc->validate_segwit_address(self::MAINNET_BECH32_ADDRESS, NetworkFactory::bitcoin()));
    }

    public function test_validate_segwit_address_rejects_a_broken_checksum()
    {
        $broken = substr(self::MAINNET_BECH32_ADDRESS, 0, -1) . 'q';

        $this->assertFalse($this->svc->validate_segwit_address($broken, NetworkFactory::bitcoin()));
    }

    public function test_validate_segwit_address_rejects_the_wrong_network()
    {
        $this->assertFalse($this->svc->validate_segwit_address(self::MAINNET_BECH32_ADDRESS, NetworkFactory::bitcoinTestnet()));
    }

    public function test_validate_segwit_address_rejects_an_invalid_program_length()
    {
        // Valid bech32 encoding, but a v0 program that is neither 20 nor 32 bytes — the same
        // rejection AddressCreator applies via WitnessProgram::v0().
        $this->assertFalse($this->svc->validate_segwit_address('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kygt080', NetworkFactory::bitcoin()));
    }

    public function test_validate_bitcoin_address_routes_bech32_without_the_address_creator()
    {
        // Injected AddressCreator would throw if it were consulted, proving the bech32 path
        // never reaches base58 — which is what fatals on a host without GMP.
        $creator = $this->createMock(\BitWasp\Bitcoin\Address\AddressCreator::class);
        $creator->method('fromString')->willThrowException(new \Error('Call to undefined function gmp_init()'));

        $svc = new BitcoinAddressService(null, $creator);

        $this->assertTrue($svc->validate_bitcoin_address(self::MAINNET_BECH32_ADDRESS, NetworkFactory::bitcoin()));
    }

    // --- suppress_vendor_deprecations() ------------------------------------------------
    //
    // Masks E_DEPRECATED around the bitwasp calls so the accepted "Use of parent in callables"
    // (and tentative return-type) notices stop printing mid-request and breaking the settings-save
    // redirect. Private static; tested via reflection (see invokeSuppress), like OnchainWithoutGmpTest.
    //
    // We assert the error_reporting() BIT STATE, not a captured notice: since PHP 8.0 a
    // set_error_handler() collector runs regardless of the error_reporting() mask (false negative),
    // and `& ~E_DEPRECATED` clears the engine E_DEPRECATED bit, not E_USER_DEPRECATED, so a
    // trigger_error(E_USER_DEPRECATED) probe would mislead. Real display suppression is covered by
    // the manual acceptance test in docs/CRYPTO-DEPRECATION-CONTINGENCY.md.

    public function test_suppress_vendor_deprecations_returns_the_callables_value()
    {
        $this->assertSame(42, $this->invokeSuppress(fn () => 42));

        $obj = new \stdClass();
        $this->assertSame($obj, $this->invokeSuppress(fn () => $obj));
    }

    public function test_suppress_vendor_deprecations_masks_only_e_deprecated_inside()
    {
        error_reporting(E_ALL);

        $seen = null;
        $this->invokeSuppress(function () use (&$seen) {
            $seen = error_reporting();
        });

        $this->assertSame(0, $seen & E_DEPRECATED, 'E_DEPRECATED must be cleared inside the callable');
        $this->assertNotSame(0, $seen & E_WARNING, 'E_WARNING must be preserved inside');
        $this->assertNotSame(0, $seen & E_NOTICE, 'E_NOTICE must be preserved inside');
    }

    public function test_suppress_vendor_deprecations_restores_after_normal_return()
    {
        error_reporting(E_ALL);
        $before = error_reporting();

        $this->invokeSuppress(fn () => null);

        $this->assertSame($before, error_reporting());
    }

    public function test_suppress_vendor_deprecations_restores_after_a_throw()
    {
        error_reporting(E_ALL);
        $before = error_reporting();

        try {
            $this->invokeSuppress(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            // expected — we only care that error_reporting was restored anyway.
        }

        $this->assertSame($before, error_reporting());
    }

    public function test_suppress_vendor_deprecations_does_not_catch_error()
    {
        // The GMP-missing contract: a missing extension surfaces as \Error and must propagate,
        // never be swallowed by the deprecation suppression.
        $this->expectException(\Error::class);

        $this->invokeSuppress(function () {
            throw new \Error('Call to undefined function gmp_init()');
        });
    }

    public function test_suppress_vendor_deprecations_does_not_catch_type_error()
    {
        $this->expectException(\TypeError::class);

        $this->invokeSuppress(function () {
            throw new \TypeError('bad type');
        });
    }

    public function test_suppress_vendor_deprecations_does_not_catch_exception()
    {
        $this->expectException(\RuntimeException::class);

        $this->invokeSuppress(function () {
            throw new \RuntimeException('parse failed');
        });
    }

    public function test_suppress_vendor_deprecations_nesting_restores_lifo()
    {
        error_reporting(E_ALL);
        $before = error_reporting();

        $innerSaw = null;
        $this->invokeSuppress(function () use (&$innerSaw) {
            $this->invokeSuppress(function () use (&$innerSaw) {
                $innerSaw = error_reporting();
            });
        });

        $this->assertSame(0, $innerSaw & E_DEPRECATED, 'nested inner call still masks E_DEPRECATED');
        $this->assertSame($before, error_reporting(), 'error_reporting restored to the original after nesting');
    }
}
