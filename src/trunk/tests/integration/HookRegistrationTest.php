<?php

use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * Proves the schema check is wired to the hooks A.2 moved it to, and NOT to plugins_loaded any
 * more — the whole point of that change was to stop an ALTER on a growing table from landing in a
 * shopper's page load. This can only be observed against a real hook registry built by a real
 * WordPress bootstrap; the unit suite calls DbInstaller's methods directly and never fires a real
 * plugins_loaded/admin_init request lifecycle, so a regression here (someone re-adding the direct
 * call to the constructor) would pass every unit test unnoticed.
 */
class HookRegistrationTest extends \PHPUnit\Framework\TestCase
{
    public function test_maybe_upgrade_is_hooked_on_admin_init()
    {
        $this->assertNotFalse(
            has_action('admin_init', [DbInstaller::class, 'maybe_upgrade']),
            'DbInstaller::maybe_upgrade() must run on admin_init'
        );
    }

    public function test_maybe_upgrade_after_update_is_hooked_on_upgrader_process_complete()
    {
        $this->assertNotFalse(
            has_action('upgrader_process_complete', [DbInstaller::class, 'maybe_upgrade_after_update']),
            'DbInstaller::maybe_upgrade_after_update() must run right after the plugin itself updates'
        );
    }

    public function test_maybe_upgrade_is_not_hooked_on_plugins_loaded()
    {
        $this->assertFalse(
            has_action('plugins_loaded', [DbInstaller::class, 'maybe_upgrade']),
            'The schema check must not run on every request — see admin_init/upgrader_process_complete instead'
        );
    }
}
