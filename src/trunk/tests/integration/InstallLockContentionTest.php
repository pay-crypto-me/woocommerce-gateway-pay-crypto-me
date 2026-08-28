<?php

use PayCryptoMe\WooCommerce\DbInstaller;

/**
 * DbInstallerTest (unit) proves install()'s lock logic against a mocked $wpdb that always answers
 * GET_LOCK/RELEASE_LOCK however the test tells it to — it cannot prove the lock actually excludes a
 * SEPARATE connection, because a mock has no connections. This test opens a real second MySQL
 * connection (bypassing $wpdb entirely) and holds the advisory lock from there, so install() has to
 * contend with genuine cross-connection mutual exclusion, the same as two concurrent PHP-FPM
 * workers would.
 */
class InstallLockContentionTest extends SchemaTestCase
{
    public function test_install_is_refused_while_a_separate_connection_holds_the_lock()
    {
        $prefix = $this->reserve_prefix();
        $other  = $this->open_second_connection();

        $got = $other->query("SELECT GET_LOCK('" . DbInstaller::INSTALL_LOCK . "', 5)")->fetch_row();
        $this->assertSame('1', $got[0], 'Test setup: the second connection must actually hold the lock');

        try {
            $installed = $this->with_prefix($prefix, fn(): bool => DbInstaller::install());

            $this->assertFalse($installed, 'install() must refuse to run while another connection holds the advisory lock');
            $this->assertSame(
                [],
                get_option(DbInstaller::ERRORS_OPTION, []),
                'Losing the lock race is not a failure — no error should be recorded, and no admin notice should fire'
            );

            global $wpdb;
            $this->assertSame(
                [],
                (array) $wpdb->get_col("SHOW TABLES LIKE '{$prefix}%'"),
                'Losing the lock race must not leave any table behind'
            );
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . DbInstaller::INSTALL_LOCK . "')");
            $other->close();
        }

        // The lock is free now — the same prefix installs cleanly on retry, proving this was
        // specifically the OTHER connection's lock being respected, not some unrelated failure.
        $installed_after_release = $this->with_prefix($prefix, fn(): bool => DbInstaller::install());
        $this->assertTrue($installed_after_release);
    }

    private function open_second_connection(): \mysqli
    {
        $host = DB_HOST;
        $port = 3306;

        if (str_contains($host, ':')) {
            [$host, $port_string] = explode(':', $host, 2);
            $port = (int) $port_string;
        }

        $connection = new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);

        $this->assertSame(0, $connection->connect_errno, "Could not open a second MySQL connection: {$connection->connect_error}");

        return $connection;
    }
}
