<?php

use PayCryptoMe\WooCommerce\LightningStatusTransitionResult;
use PayCryptoMe\WooCommerce\PayCryptoMeLightningDBStatementsService;

class LightningStatusProjectionTest extends SchemaTestCase
{
    /** @var string[] */
    private array $actions_tables = [];

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->actions_tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->actions_tables = [];

        parent::tearDown();
    }

    public function test_two_real_processes_apply_one_transition_and_publish_one_action(): void
    {
        global $wpdb;

        $prefix = $this->fresh_install();
        $order_id = 91001;
        $invoice_id = 'concurrent-invoice';
        $actions_table = $prefix . 'status_projection_actions';
        $this->actions_tables[] = $actions_table;

        $wpdb->query(
            "CREATE TABLE `{$actions_table}` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT(20) UNSIGNED NOT NULL,
                invoice_id VARCHAR(255) NOT NULL,
                old_status VARCHAR(30) NOT NULL,
                new_status VARCHAR(30) NOT NULL,
                PRIMARY KEY (id)
            ) " . $wpdb->get_charset_collate()
        );
        $this->assertEmpty($wpdb->last_error, "Could not create shared action table: {$wpdb->last_error}");

        $this->with_prefix($prefix, function () use ($order_id, $invoice_id): void {
            $this->assertTrue((new PayCryptoMeLightningDBStatementsService())->insert_invoice(
                $order_id,
                'btcpay',
                $invoice_id,
                'ln-concurrent',
                '2030-01-01 00:00:00'
            ));
        });

        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $command = [
                PHP_BINARY,
                dirname(__DIR__) . '/bin/status-transition-worker.php',
                $prefix,
                (string) $order_id,
                $invoice_id,
                'New',
                'Settled',
            ];
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $this->assertSame("ready\n", fgets($pipes[1]), 'Both workers must reach the release barrier.');
        }
        foreach ($processes as [$process, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $outcomes = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output . $error);
            $decoded = json_decode($output, true);
            $this->assertIsArray($decoded, $output . $error);
            $this->assertNull($decoded['error']);
            $outcomes[] = $decoded['outcome'];
        }

        sort($outcomes);
        $this->assertSame([
            LightningStatusTransitionResult::ALREADY_APPLIED,
            LightningStatusTransitionResult::APPLIED,
        ], $outcomes);

        $invoices_table = $prefix . 'paycrypto_me_lightning_invoices';
        $this->assertSame('Settled', $wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$invoices_table} WHERE order_id = %d", $order_id)
        ));
        $this->assertSame('1', $wpdb->get_var("SELECT COUNT(*) FROM {$actions_table}"));
    }

    public function test_delayed_transition_cannot_settle_replacement_invoice(): void
    {
        $prefix = $this->fresh_install();

        $this->with_prefix($prefix, function (): void {
            $service = new PayCryptoMeLightningDBStatementsService();
            $this->assertTrue($service->insert_invoice(91002, 'btcpay', 'old', 'ln-old', '2029-01-01 00:00:00'));
            $this->assertTrue($service->replace_invoice(91002, 'btcpay', 'new', 'ln-new', '2030-01-01 00:00:00', null, 'old'));

            $actions = [];
            $listener = static function (...$args) use (&$actions): void {
                $actions[] = $args;
            };
            add_action('paycryptome_lightning_status_changed', $listener, 10, 4);
            try {
                $result = $service->transition_status(91002, 'old', 'New', 'Settled');
            } finally {
                remove_action('paycryptome_lightning_status_changed', $listener, 10);
            }

            $this->assertSame(LightningStatusTransitionResult::CONFLICT, $result->outcome);
            $this->assertSame('new', $result->stored_invoice_id);
            $this->assertSame('New', $service->get_by_order_id(91002)['status']);
            $this->assertSame([], $actions);
        });
    }

    public function test_two_real_processes_with_different_targets_leave_one_winner(): void
    {
        global $wpdb;

        $prefix = $this->fresh_install();
        $order_id = 91004;
        $invoice_id = 'conflicting-invoice';
        $actions_table = $prefix . 'status_projection_actions';
        $this->actions_tables[] = $actions_table;

        $wpdb->query(
            "CREATE TABLE `{$actions_table}` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT(20) UNSIGNED NOT NULL,
                invoice_id VARCHAR(255) NOT NULL,
                old_status VARCHAR(30) NOT NULL,
                new_status VARCHAR(30) NOT NULL,
                PRIMARY KEY (id)
            ) " . $wpdb->get_charset_collate()
        );
        $this->assertEmpty($wpdb->last_error, "Could not create shared action table: {$wpdb->last_error}");

        $this->with_prefix($prefix, function () use ($order_id, $invoice_id): void {
            $this->assertTrue((new PayCryptoMeLightningDBStatementsService())->insert_invoice(
                $order_id,
                'btcpay',
                $invoice_id,
                'ln-conflicting',
                '2030-01-01 00:00:00'
            ));
        });

        $processes = [];
        foreach (['Settled', 'Expired'] as $new_status) {
            $command = [
                PHP_BINARY,
                dirname(__DIR__) . '/bin/status-transition-worker.php',
                $prefix,
                (string) $order_id,
                $invoice_id,
                'New',
                $new_status,
            ];
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $this->assertSame("ready\n", fgets($pipes[1]), 'Both workers must reach the release barrier.');
        }
        foreach ($processes as [$process, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $outcomes = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output . $error);
            $decoded = json_decode($output, true);
            $this->assertIsArray($decoded, $output . $error);
            $this->assertNull($decoded['error']);
            $outcomes[] = $decoded['outcome'];
        }

        sort($outcomes);
        $this->assertSame([
            LightningStatusTransitionResult::APPLIED,
            LightningStatusTransitionResult::CONFLICT,
        ], $outcomes);

        $invoices_table = $prefix . 'paycrypto_me_lightning_invoices';
        $this->assertContains($wpdb->get_var(
            $wpdb->prepare("SELECT status FROM {$invoices_table} WHERE order_id = %d", $order_id)
        ), ['Settled', 'Expired']);
        $this->assertSame('1', $wpdb->get_var("SELECT COUNT(*) FROM {$actions_table}"));
    }

    public function test_missing_order_and_database_error_are_distinct(): void
    {
        $prefix = $this->fresh_install();

        $this->with_prefix($prefix, function () use ($prefix): void {
            $service = new PayCryptoMeLightningDBStatementsService();
            $missing = $service->transition_status(91003, 'missing', 'New', 'Settled');
            $this->assertSame(LightningStatusTransitionResult::NOT_FOUND, $missing->outcome);

            $break_update = static function (string $sql) use ($prefix): string {
                if (str_starts_with($sql, "UPDATE {$prefix}paycrypto_me_lightning_invoices SET status")) {
                    return 'THIS IS NOT VALID SQL';
                }

                return $sql;
            };
            add_filter('query', $break_update);
            try {
                $error = $service->transition_status(91003, 'missing', 'New', 'Settled');
            } finally {
                remove_filter('query', $break_update);
            }

            $this->assertSame(LightningStatusTransitionResult::ERROR, $error->outcome);
            $this->assertNotSame('', $error->error_message);
        });
    }
}
