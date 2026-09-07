<?php

require dirname(__DIR__) . '/integration/bootstrap.php';

$prefix = $argv[1] ?? '';
$order_id = isset($argv[2]) ? (int) $argv[2] : 0;
$invoice_id = $argv[3] ?? '';
$expected_status = $argv[4] ?? '';
$new_status = $argv[5] ?? '';

if (!preg_match('/^pcmit\d+_$/', $prefix) || $order_id < 1) {
    exit(2);
}

$wpdb->prefix = $prefix;
$actions_table = $prefix . 'status_projection_actions';

add_action(
    'paycryptome_lightning_status_changed',
    static function ($changed_order_id, $old_status, $changed_status, $changed_invoice_id) use ($wpdb, $actions_table): void {
        $wpdb->insert(
            $actions_table,
            [
                'order_id'    => $changed_order_id,
                'invoice_id'  => $changed_invoice_id,
                'old_status'  => $old_status,
                'new_status'  => $changed_status,
            ],
            ['%d', '%s', '%s', '%s']
        );
    },
    10,
    4
);

echo "ready\n";
flush();
fgets(STDIN);

$result = (new \PayCryptoMe\WooCommerce\PayCryptoMeLightningDBStatementsService())->transition_status(
    $order_id,
    $invoice_id,
    $expected_status,
    $new_status
);

echo wp_json_encode([
    'outcome' => $result->outcome,
    'error'   => $result->error_message,
]);
