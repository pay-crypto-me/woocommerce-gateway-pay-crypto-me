<?php

use PHPUnit\Framework\TestCase;

/**
 * Mirrors ActivateDbDeltaTest for the Lightning invoices table — see that file's docblock
 * for why "IF NOT EXISTS" breaks dbDelta()'s table-name extraction (H1).
 */
class LightningActivateDbDeltaTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;

        $wpdb = new class {
            public $prefix = 'wp_';
            public $charset = 'utf8mb4';
            public $collate = 'utf8mb4_unicode_ci';
            public $last_error = '';

            public function get_charset_collate()
            {
                return "DEFAULT CHARACTER SET {$this->charset} COLLATE {$this->collate}";
            }
        };

        // get_option() is already shimmed process-wide in tests/_support/wp-helpers.php.
        // update_option() captures every call so H1's error-recording path is verifiable.
        if (!isset($GLOBALS['__update_option_calls'])) {
            $GLOBALS['__update_option_calls'] = [];
        }
        if (!function_exists('update_option')) {
            function update_option($key, $value) {
                $GLOBALS['__update_option_calls'][] = [$key, $value];
                return true;
            }
        }
        $GLOBALS['__update_option_calls'] = [];

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/var/www/html/');
        }

        if (!isset($GLOBALS['__dbdelta_captured'])) {
            $GLOBALS['__dbdelta_captured'] = [];
        }
        unset($GLOBALS['__dbdelta_dry_run_result']);

        if (!function_exists('dbDelta')) {
            // $execute = false is DbDeltaRunner's post-condition dry run — see ActivateDbDeltaTest.
            function dbDelta($queries, $execute = true)
            {
                if (!$execute) {
                    return $GLOBALS['__dbdelta_dry_run_result'] ?? [];
                }

                if (is_string($queries)) {
                    $q = $queries;
                } elseif (is_array($queries)) {
                    $q = implode("\n", $queries);
                } else {
                    $q = '';
                }
                $GLOBALS['__dbdelta_captured'][] = $q;
                return true;
            }
        }

        $upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (!file_exists($upgrade_path)) {
            $upgrade_dir = dirname($upgrade_path);
            if (!is_dir($upgrade_dir)) {
                @mkdir($upgrade_dir, 0777, true);
            }
            $stub = "<?php\nif (!function_exists('dbDelta')) { function dbDelta(\$queries, \$execute = true) { global \$__dbdelta_captured; if (!\$execute) { return []; } if (is_string(\$queries)) { \$q=\$queries; } elseif (is_array(\$queries)) { \$q=implode(\"\\n\", \$queries); } else { \$q=''; } \$GLOBALS['__dbdelta_captured'][] = \$q; return true; } }\n";
            @file_put_contents($upgrade_path, $stub);
        }

        $activate_path = __DIR__ . '/../../../includes/services/class-paycrypto-me-lightning-gateway-activate.php';
        if (file_exists($activate_path)) {
            require_once $activate_path;
        }
    }

    public function test_activate_creates_expected_table_without_if_not_exists()
    {
        $GLOBALS['__dbdelta_captured'] = [];

        $fqcn = '\\PayCryptoMe\\WooCommerce\\PayCryptoMeLightningGatewayActivate';
        if (!class_exists($fqcn)) {
            $this->fail('Classe ' . $fqcn . ' não encontrada');
        }

        $fqcn::activate();

        $this->assertNotEmpty($GLOBALS['__dbdelta_captured'], 'Nenhum SQL foi passado para dbDelta');
        $all_sql = implode("\n", $GLOBALS['__dbdelta_captured']);

        $this->assertStringContainsString('CREATE TABLE', $all_sql);
        $this->assertStringContainsString('paycrypto_me_lightning_invoices', $all_sql);
        $this->assertStringContainsString('UNIQUE KEY unique_order', $all_sql);

        // H1: "IF NOT EXISTS" breaks dbDelta()'s table-name extraction (it would capture the
        // literal word "IF"), silently turning every future schema change into a no-op.
        $this->assertStringNotContainsString('IF NOT EXISTS', $all_sql, '"IF NOT EXISTS" breaks dbDelta table-name detection');

        // A successful run must not record an activation error.
        $this->assertEmpty($GLOBALS['__update_option_calls']);
    }

    public function test_activate_records_activation_error_when_dbdelta_fails_silently()
    {
        global $wpdb;
        $wpdb->last_error = 'Specified key was too long; max key length is 767 bytes';

        // dbDelta() itself never inspects $wpdb->last_error — without this explicit check the
        // failure would otherwise be reported as a successful activation (H1).
        \PayCryptoMe\WooCommerce\PayCryptoMeLightningGatewayActivate::activate();

        $this->assertCount(1, $GLOBALS['__update_option_calls']);
        [$key, $errors] = $GLOBALS['__update_option_calls'][0];
        $this->assertSame('paycrypto_me_db_activation_errors', $key);
        $this->assertStringContainsString('too long', $errors[0]);
    }
}
