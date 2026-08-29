<?php

use PHPUnit\Framework\TestCase;

/**
 * Verifica que a rotina de ativação chama dbDelta com SQL contendo as tabelas esperadas.
 * Este teste finge o $wpdb e implementa uma função dbDelta que captura o SQL.
 */
class ActivateDbDeltaTest extends TestCase
{
    protected function setUp(): void
    {
        // Criar um $wpdb falso mínimo
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
        // update_option() captures every call so H1's error-recording path is verifiable
        // (shared shim — see LightningActivateDbDeltaTest, whichever test file's setUp() runs
        // first in the process wins the actual function_exists() declaration).
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

        // Garantir ABSPATH para includes do WP
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/var/www/html/');
        }

        // stub para get_charset_collate se usado
        if (!function_exists('get_charset_collate')) {
            function get_charset_collate()
            {
                global $wpdb;
                return "DEFAULT CHARACTER SET {$wpdb->charset} COLLATE {$wpdb->collate}";
            }
        }

        // Captura queries passadas para dbDelta
        if (!isset($GLOBALS['__dbdelta_captured'])) {
            $GLOBALS['__dbdelta_captured'] = [];
        }
        unset($GLOBALS['__dbdelta_dry_run_result']);

        // Define dbDelta se não existir
        if (!function_exists('dbDelta')) {
            // $execute = false is DbDeltaRunner's post-condition dry run: it must return an empty
            // "nothing pending" list here, or every activate() call in this suite would report a
            // phantom failure the moment Front B's dry-run check runs.
            function dbDelta($queries, $execute = true)
            {
                if (!$execute) {
                    // DbDeltaRunnerTest overrides this to exercise the "masked failure" path;
                    // every other test leaves it unset and gets the "nothing pending" default.
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

        // Garantir que o arquivo upgrade.php exista no caminho resolvido por ABSPATH
        $upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (!file_exists($upgrade_path)) {
            $upgrade_dir = dirname($upgrade_path);
            if (!is_dir($upgrade_dir)) {
                @mkdir($upgrade_dir, 0777, true);
            }
            // cria um stub mínimo que define dbDelta quando incluído
                $stub = "<?php\nif (!function_exists('dbDelta')) { function dbDelta(\$queries, \$execute = true) { global \$__dbdelta_captured; if (!\$execute) { return []; } if (is_string(\$queries)) { \$q=\$queries; } elseif (is_array(\$queries)) { \$q=implode(\"\\n\", \$queries); } else { \$q=''; } \$GLOBALS['__dbdelta_captured'][] = \$q; return true; } }\n";
            @file_put_contents($upgrade_path, $stub);
        }

        // load the activation class file
        $activate_path = __DIR__ . '/../../../includes/services/class-paycrypto-me-bitcoin-gateway-activate.php';
        if (file_exists($activate_path)) {
            require_once $activate_path;
        }
    }

    public function test_activate_creates_expected_tables()
    {
        // Garantir ambiente limpo
        $GLOBALS['__dbdelta_captured'] = [];

        // Chamar o método de ativação na classe namespaced
        $fqcn = '\\PayCryptoMe\\WooCommerce\\PayCryptoMeBitcoinGatewayActivate';
        if (class_exists($fqcn)) {
            // método estático
            $fqcn::activate();
        } else {
            $this->fail('Classe ' . $fqcn . ' não encontrada');
        }

        $this->assertNotEmpty($GLOBALS['__dbdelta_captured'], 'Nenhum SQL foi passado para dbDelta');

        $all_sql = implode("\n", $GLOBALS['__dbdelta_captured']);

        // Verificações essenciais nas CREATE TABLEs
        $this->assertStringContainsString('CREATE TABLE', $all_sql);

        // wallet_xpubkeys table
        $this->assertStringContainsString('paycrypto_me_bitcoin_wallet_xpubkeys', $all_sql, 'Tabela wallet_xpubkeys ausente');
        $this->assertStringContainsString('AUTO_INCREMENT', $all_sql, 'AUTO_INCREMENT esperado para id na criação das tabelas');
        $this->assertStringContainsString('xpub', $all_sql, 'Coluna xpub não encontrada na definição');

        // derivation_indexes table
        $this->assertStringContainsString('paycrypto_me_bitcoin_derivation_indexes', $all_sql, 'Tabela derivation_indexes ausente');
        $this->assertStringContainsString('PRIMARY KEY', $all_sql, 'PRIMARY KEY esperado na tabela derivation_indexes');
        $this->assertStringContainsString('derivation_index', $all_sql, 'Coluna derivation_index não encontrada');
        $this->assertStringContainsString('wallet_xpubkeys_id', $all_sql, 'Coluna wallet_xpubkeys_id não encontrada');

        // transactions table
        $this->assertStringContainsString('paycrypto_me_bitcoin_transactions_data', $all_sql, 'Tabela transactions_data ausente');
        $this->assertStringContainsString('order_id', $all_sql, 'Coluna order_id não encontrada');
        $this->assertStringContainsString('payment_address', $all_sql, 'Coluna payment_address não encontrada');

        // H1: dbDelta() doesn't manage FOREIGN KEY constraints (silently dropped on MyISAM),
        // and it isn't dropped here for lack of trying — composite PK (derivation_index,
        // wallet_xpubkeys_id) already enforces the integrity the FK used to.
        $this->assertStringNotContainsString('FOREIGN KEY', $all_sql, 'FOREIGN KEY should not appear in dbDelta-managed DDL');
        $this->assertStringNotContainsString('REFERENCES', $all_sql, 'REFERENCES should not appear in dbDelta-managed DDL');

        // H1: "IF NOT EXISTS" breaks dbDelta()'s table-name extraction (it would capture the
        // literal word "IF"), silently turning every future schema change into a no-op.
        $this->assertStringNotContainsString('IF NOT EXISTS', $all_sql, '"IF NOT EXISTS" breaks dbDelta table-name detection');

        // H1: xpub must be VARCHAR(191), not VARCHAR(255) — utf8mb4 VARCHAR(255) in the
        // unique_xpub_network key blows past InnoDB's 767-byte index-key limit on older
        // MySQL/MariaDB (COMPACT row format), the same reason WordPress core uses VARCHAR(191).
        $this->assertStringContainsString('xpub VARCHAR(191)', $all_sql, 'xpub column must be VARCHAR(191)');

        // A successful run must not record an activation error.
        $this->assertEmpty($GLOBALS['__update_option_calls']);
    }

    public function test_activate_records_activation_error_when_dbdelta_fails_silently()
    {
        global $wpdb;
        $wpdb->last_error = 'Specified key was too long; max key length is 767 bytes';

        // dbDelta() itself never inspects $wpdb->last_error — without H1's explicit check
        // this failure would otherwise be reported as a successful activation.
        \PayCryptoMe\WooCommerce\PayCryptoMeBitcoinGatewayActivate::activate();

        // One dbDelta() call per table (3), so the error is recorded 3 times over.
        $this->assertCount(3, $GLOBALS['__update_option_calls']);
        foreach ($GLOBALS['__update_option_calls'] as [$key, $errors]) {
            $this->assertSame('paycrypto_me_db_activation_errors', $key);
            $this->assertStringContainsString('too long', end($errors));
        }
    }
}
