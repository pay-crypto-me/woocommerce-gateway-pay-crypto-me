<?php

use PHPUnit\Framework\TestCase;
use PayCryptoMe\WooCommerce\EnvironmentRequirements;

class EnvironmentRequirementsTest extends TestCase
{
    public function test_missing_reports_only_extensions_that_are_absent()
    {
        // 'json' is always compiled in since PHP 8.0; the other name cannot exist.
        $missing = EnvironmentRequirements::missing(['json', 'paycrypto_not_a_real_extension']);

        $this->assertSame(['paycrypto_not_a_real_extension'], $missing);
    }

    public function test_missing_returns_an_empty_list_when_everything_is_loaded()
    {
        $this->assertSame([], EnvironmentRequirements::missing(['json']));
    }

    public function test_missing_returns_a_zero_indexed_list()
    {
        // array_filter() preserves keys; a caller printing this with a list-shaped
        // expectation would otherwise get a gap where the loaded extension was.
        $missing = EnvironmentRequirements::missing(['paycrypto_absent_one', 'json', 'paycrypto_absent_two']);

        $this->assertSame([0, 1], array_keys($missing));
    }

    public function test_onchain_and_qr_requirements_are_the_documented_ones()
    {
        $this->assertSame(['gmp'], EnvironmentRequirements::ONCHAIN_EXTENSIONS);
        $this->assertSame(['gd', 'iconv', 'fileinfo'], EnvironmentRequirements::QR_EXTENSIONS);
    }

    public function test_missing_onchain_extensions_uses_the_onchain_list()
    {
        $expected = extension_loaded('gmp') ? [] : ['gmp'];

        $this->assertSame($expected, EnvironmentRequirements::missing_onchain_extensions());
    }

    public function test_describe_uses_canonical_extension_casing()
    {
        $this->assertSame('GMP', EnvironmentRequirements::describe(['gmp']));
        $this->assertSame('GD', EnvironmentRequirements::describe(['gd']));
    }

    public function test_describe_joins_multiple_extensions_readably()
    {
        $this->assertSame('GD, iconv and fileinfo', EnvironmentRequirements::describe(['gd', 'iconv', 'fileinfo']));
        $this->assertSame('GD and iconv', EnvironmentRequirements::describe(['gd', 'iconv']));
    }

    public function test_describe_of_an_empty_list_is_an_empty_string()
    {
        $this->assertSame('', EnvironmentRequirements::describe([]));
    }

    public function test_describe_passes_through_unknown_extension_names()
    {
        $this->assertSame('sodium', EnvironmentRequirements::describe(['sodium']));
    }
}
