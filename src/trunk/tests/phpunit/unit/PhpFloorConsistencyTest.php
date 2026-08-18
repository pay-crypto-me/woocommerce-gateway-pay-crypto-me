<?php

use PHPUnit\Framework\TestCase;

/**
 * The plugin's PHP floor is written down in four places, and they only mean anything together:
 *
 *   1. `Requires PHP:` in the plugin header      — what WordPress enforces on activation/update
 *   2. `Requires PHP:` in readme.txt             — what WordPress.org shows and filters by
 *   3. `config.platform.php` in composer.json    — which PHP Composer resolves the vendor tree for
 *   4. `PHP_VERSION_ID < N` in the entrypoint    — the guard that turns a platform_check fatal into
 *                                                  an admin notice
 *
 * Drift between any two of them is a silent hazard rather than a visible error. (3) below (1) is the
 * blanket suppression that shipped `sodium_compat` a major behind — see docs/LEAN-VENDOR-TREE.md and
 * scripts/check-platform-pin.sh, which audits that pair from the shell side. (4) below (1) reopens
 * the site-wide fatal the guard exists to prevent; (4) above (1) blocks hosts the plugin actually
 * supports. (2) drifting from (1) misleads store owners before they ever install.
 *
 * Parsing the files instead of loading them, like VendorReplaceGuardTest: the entrypoint cannot be
 * included in the unit suite (no WordPress), and what needs pinning is the literal text a human will
 * edit.
 */
class PhpFloorConsistencyTest extends TestCase
{
    private function trunk(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        $path = $this->trunk() . '/' . $relative;
        $this->assertFileExists($path, "cannot audit the PHP floor without {$relative}");

        return (string) file_get_contents($path);
    }

    /** "8.1" => 80100, the same arithmetic PHP_VERSION_ID uses. */
    private function to_version_id(string $version): int
    {
        $parts = array_map('intval', explode('.', $version));

        return ($parts[0] ?? 0) * 10000 + ($parts[1] ?? 0) * 100 + ($parts[2] ?? 0);
    }

    private function plugin_header_floor(): string
    {
        preg_match(
            '/^\s*\*\s*Requires PHP:\s*([0-9.]+)/m',
            $this->read('paycrypto-me-for-woocommerce.php'),
            $m
        );

        $this->assertNotEmpty($m, 'the plugin header no longer declares "Requires PHP:"');

        return $m[1];
    }

    public function test_readme_declares_the_same_floor_as_the_plugin_header()
    {
        preg_match('/^Requires PHP:\s*([0-9.]+)/m', $this->read('readme.txt'), $m);

        $this->assertNotEmpty($m, 'readme.txt no longer declares "Requires PHP:"');
        $this->assertSame(
            $this->plugin_header_floor(),
            $m[1],
            'readme.txt and the plugin header disagree on the PHP floor — WordPress.org shows the '
                . 'readme value to store owners, WordPress enforces the header one'
        );
    }

    public function test_composer_platform_pin_states_the_declared_floor()
    {
        $composer = json_decode($this->read('composer.json'), true);

        $this->assertIsArray($composer, 'composer.json is not valid JSON');
        $pin = $composer['config']['platform']['php'] ?? null;

        $this->assertNotNull(
            $pin,
            'config.platform.php is gone: resolution now depends on the PHP of whatever machine runs '
                . 'composer install. See docs/LEAN-VENDOR-TREE.md before removing it.'
        );

        $this->assertSame(
            $this->plugin_header_floor(),
            $pin,
            'config.platform.php no longer matches the declared floor. Below it, the pin silences the '
                . 'PHP check for the whole tree (how sodium_compat shipped a major behind); above it, '
                . 'the tree can need a PHP the header promises is not required.'
        );
    }

    public function test_entrypoint_guard_matches_the_declared_floor()
    {
        $entrypoint = $this->read('paycrypto-me-for-woocommerce.php');

        preg_match('/PHP_VERSION_ID\s*<\s*(\d+)/', $entrypoint, $m);

        $this->assertNotEmpty(
            $m,
            'the PHP_VERSION_ID guard is gone from the entrypoint: on a host below the floor, '
                . "Composer's platform_check.php now fatals the whole site from vendor/autoload.php "
                . 'instead of showing an admin notice'
        );

        $floor = $this->plugin_header_floor();

        $this->assertSame(
            $this->to_version_id($floor),
            (int) $m[1],
            'the entrypoint guard and the declared floor disagree'
        );

        $this->assertStringContainsString(
            'requires PHP ' . $floor,
            $entrypoint,
            'the guard notice quotes a different version than it enforces'
        );
    }
}
