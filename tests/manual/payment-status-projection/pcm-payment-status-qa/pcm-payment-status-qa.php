<?php
/**
 * Plugin Name: PCM Payment Status Projection QA
 * Description: Disposable browser harness for the Base payment-status projection contract.
 * Version: 1.0.0
 * Requires Plugins: paycrypto-me-for-woocommerce
 */

defined('ABSPATH') || exit;

add_action('admin_menu', 'pcm_projection_qa_admin_menu');
add_filter('pre_http_request', 'pcm_projection_qa_btcpay_fixture', 10, 3);

/**
 * Replaces only the reserved QA hostname. Checkout still crosses the real gateway, processor,
 * HTTP adapter and persistence boundaries; only the remote BTCPay server is a deterministic fixture.
 */
function pcm_projection_qa_btcpay_fixture($preempt, array $arguments, string $url)
{
    if (wp_parse_url($url, PHP_URL_HOST) !== 'qa-btcpay.invalid') {
        return $preempt;
    }

    $response = static function (array $body): array {
        return [
            'headers' => [],
            'body' => wp_json_encode($body),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    };

    if (str_ends_with($url, '/payment-methods')) {
        return $response([[
            'paymentMethodId' => 'BTC-LN',
            'destination' => 'lnbc1pcmprojectionqafixture',
        ]]);
    }

    if (($arguments['method'] ?? 'GET') === 'POST' && str_ends_with($url, '/invoices')) {
        $request = json_decode((string) ($arguments['body'] ?? ''), true);
        $order_id = sanitize_key((string) ($request['metadata']['orderId'] ?? 'unknown'));
        return $response([
            'id' => 'qa-btcpay-' . $order_id,
            'checkoutLink' => home_url('/qa-btcpay-checkout/' . $order_id),
            'status' => 'New',
        ]);
    }

    return $response(['status' => 'New']);
}

function pcm_projection_qa_admin_menu(): void
{
    add_management_page(
        'Payment Status Projection QA',
        'Payment Projection QA',
        'manage_options',
        'pcm-payment-status-qa',
        'pcm_projection_qa_render_page'
    );
}

function pcm_projection_qa_render_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.'));
    }

    $expected = get_option('pcm_projection_qa_expected', 'candidate');
    $results = null;
    $executed_profile = null;
    if (isset($_POST['pcm_projection_qa_run'])) {
        check_admin_referer('pcm_projection_qa_run');
        $requested_profile = sanitize_key(wp_unslash($_POST['pcm_projection_qa_run']));
        $executed_profile = $requested_profile === 'baseline' ? 'baseline' : 'candidate';
        $results = $executed_profile === 'baseline'
            ? pcm_projection_qa_run_baseline()
            : pcm_projection_qa_run_candidate();
    }

    $base_file = WP_PLUGIN_DIR . '/paycrypto-me-for-woocommerce/paycrypto-me-for-woocommerce.php';
    $base_data = file_exists($base_file) ? get_plugin_data($base_file, false, false) : [];
    ?>
    <div class="wrap">
        <h1>Payment Status Projection QA</h1>
        <p><strong>Perfil provisionado:</strong> <?php echo esc_html($expected); ?></p>
        <p><strong>Base instalado:</strong> <?php echo esc_html($base_data['Version'] ?? 'não encontrado'); ?></p>
        <p>Este harness usa somente pedidos fictícios 990001–990099 e remove suas fixtures ao terminar.</p>
        <p>O hostname reservado <code>qa-btcpay.invalid</code> é interceptado para permitir checkout
            Lightning determinístico; nenhuma outra requisição HTTP é alterada.</p>
        <form method="post">
            <?php wp_nonce_field('pcm_projection_qa_run'); ?>
            <button class="button button-primary" name="pcm_projection_qa_run" value="<?php echo esc_attr($expected); ?>">
                Executar matriz <?php echo esc_html($expected); ?>
            </button>
            <button class="button" name="pcm_projection_qa_run" value="<?php echo esc_attr($expected === 'baseline' ? 'candidate' : 'baseline'); ?>">
                Executar matriz <?php echo esc_html($expected === 'baseline' ? 'candidate' : 'baseline'); ?>
            </button>
        </form>
        <?php if (is_array($results)) : ?>
            <h2>Resultado — <?php echo esc_html($executed_profile); ?></h2>
            <table class="widefat striped" style="max-width: 1100px">
                <thead><tr><th>Caso</th><th>Estado</th><th>Evidência</th></tr></thead>
                <tbody>
                <?php foreach ($results as $result) : ?>
                    <tr>
                        <td><?php echo esc_html($result['case']); ?></td>
                        <td><strong style="color: <?php echo $result['pass'] ? '#008a20' : '#b32d2e'; ?>">
                            <?php echo $result['pass'] ? 'PASS' : 'FAIL'; ?>
                        </strong></td>
                        <td><code><?php echo esc_html($result['evidence']); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

function pcm_projection_qa_result(string $case, bool $pass, string $evidence): array
{
    return ['case' => $case, 'pass' => $pass, 'evidence' => $evidence];
}

function pcm_projection_qa_run_baseline(): array
{
    $capability_class = 'PayCryptoMe\\WooCommerce\\PaymentStatusProjectionCapabilities';
    $available = class_exists($capability_class);
    $writer_called = false;

    // This is the consumer fallback rule: an absent capability ends projection without touching
    // either the new writer or deprecated update_status().
    if ($available) {
        $capabilities = $capability_class::all();
        $writer_called = (int) ($capabilities['lightning_invoice_status_cas'] ?? 0) >= 1;
    }

    return [
        pcm_projection_qa_result(
            'B01 — capability ausente no Base 0.2.2',
            !$available,
            $available ? 'classe encontrada indevidamente' : 'class_exists=false'
        ),
        pcm_projection_qa_result(
            'B02 — fallback não seleciona writer legado',
            !$writer_called,
            $writer_called ? 'writer seria chamado' : 'retorno antecipado sem writer'
        ),
    ];
}

function pcm_projection_qa_run_candidate(): array
{
    global $wpdb;

    $capability_class = 'PayCryptoMe\\WooCommerce\\PaymentStatusProjectionCapabilities';
    $service_class = 'PayCryptoMe\\WooCommerce\\PayCryptoMeLightningDBStatementsService';
    $table = $wpdb->prefix . 'paycrypto_me_lightning_invoices';
    $order_ids = range(990001, 990099);
    $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
    $delete_sql = $wpdb->prepare("DELETE FROM {$table} WHERE order_id IN ({$placeholders})", ...$order_ids);
    $wpdb->query($delete_sql);

    $results = [];
    try {
        if (!class_exists($capability_class) || !class_exists($service_class)) {
            return [pcm_projection_qa_result('C01 — classes públicas carregáveis', false, 'classe pública ausente')];
        }

        $capabilities = $capability_class::all();
        $results[] = pcm_projection_qa_result(
            'C01 — capability v1 publicada',
            $capabilities === [
                'contract_version' => 1,
                'lightning_invoice_status_cas' => 1,
                'onchain_confirmation_progress' => 0,
            ],
            wp_json_encode($capabilities)
        );

        $service = new $service_class();
        $actions = [];
        $listener = static function ($order_id, $old_status, $new_status, $invoice_id) use (&$actions): void {
            $actions[] = [$order_id, $old_status, $new_status, $invoice_id];
        };
        add_action('paycryptome_lightning_status_changed', $listener, 999, 4);

        pcm_projection_qa_insert(990001, 'qa-applied', 'New');
        $first = $service->transition_status(990001, 'qa-applied', 'New', 'Settled');
        $second = $service->transition_status(990001, 'qa-applied', 'New', 'Settled');
        $stored = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE order_id = %d", 990001));
        $results[] = pcm_projection_qa_result(
            'C02 — applied + retry idempotente + uma action',
            $first->outcome === 'applied'
                && $second->outcome === 'already_applied'
                && $stored === 'Settled'
                && $actions === [[990001, 'New', 'Settled', 'qa-applied']],
            "first={$first->outcome}; retry={$second->outcome}; stored={$stored}; actions=" . count($actions)
        );
        remove_action('paycryptome_lightning_status_changed', $listener, 999);

        pcm_projection_qa_insert(990002, 'qa-new-invoice', 'New');
        $replaced = $service->transition_status(990002, 'qa-old-invoice', 'New', 'Settled');
        $replaced_row = $wpdb->get_row($wpdb->prepare(
            "SELECT invoice_id, status FROM {$table} WHERE order_id = %d",
            990002
        ), ARRAY_A);
        $results[] = pcm_projection_qa_result(
            'C03 — evento atrasado não liquida invoice substituta',
            $replaced->outcome === 'conflict'
                && $replaced_row === ['invoice_id' => 'qa-new-invoice', 'status' => 'New'],
            "outcome={$replaced->outcome}; row=" . wp_json_encode($replaced_row)
        );

        pcm_projection_qa_insert(990003, 'qa-status-conflict', 'Expired');
        $status_conflict = $service->transition_status(990003, 'qa-status-conflict', 'New', 'Settled');
        $results[] = pcm_projection_qa_result(
            'C04 — estado inesperado retorna conflict',
            $status_conflict->outcome === 'conflict' && $status_conflict->current_status === 'Expired',
            "outcome={$status_conflict->outcome}; current={$status_conflict->current_status}"
        );

        $missing = $service->transition_status(990004, 'qa-missing', 'New', 'Settled');
        $results[] = pcm_projection_qa_result(
            'C05 — pedido ausente retorna not_found',
            $missing->outcome === 'not_found',
            "outcome={$missing->outcome}"
        );

        $limit_invoice = str_repeat('i', 255);
        $limit_expected = str_repeat('e', 30);
        $limit_new = str_repeat('n', 30);
        pcm_projection_qa_insert(990005, $limit_invoice, $limit_expected);
        $limit = $service->transition_status(990005, $limit_invoice, $limit_expected, $limit_new);
        $results[] = pcm_projection_qa_result(
            'C06 — limites exatos 255/30 são aceitos',
            $limit->outcome === 'applied' && $limit->current_status === $limit_new,
            "outcome={$limit->outcome}; invoice_bytes=" . strlen($limit_invoice) . '; status_bytes=' . strlen($limit_new)
        );

        $invalid_messages = [];
        foreach ([
            [0, 'qa-invalid', 'New', 'Settled'],
            [990006, str_repeat('i', 256), 'New', 'Settled'],
            [990006, 'qa-invalid', str_repeat('s', 31), 'Settled'],
            [990006, 'qa-invalid', 'New', str_repeat('s', 31)],
        ] as $arguments) {
            try {
                $service->transition_status(...$arguments);
            } catch (InvalidArgumentException $exception) {
                $invalid_messages[] = $exception->getMessage();
            }
        }
        $results[] = pcm_projection_qa_result(
            'C07 — entradas fora do schema falham antes do SQL',
            count($invalid_messages) === 4
                && $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE order_id = %d", 990006)) === '0',
            wp_json_encode($invalid_messages)
        );

        $reflection = new ReflectionProperty($service_class, 'table_name');
        $original_table = $reflection->getValue($service);
        $reflection->setValue($service, $wpdb->prefix . 'pcm_projection_qa_missing');
        $db_error = $service->transition_status(990007, 'qa-db-error', 'New', 'Settled');
        $reflection->setValue($service, $original_table);
        $results[] = pcm_projection_qa_result(
            'C08 — erro SQL retorna outcome error sem exception',
            $db_error->outcome === 'error' && $db_error->error_message !== null,
            "outcome={$db_error->outcome}; diagnostic_present=" . ($db_error->error_message !== null ? 'yes' : 'no')
        );
    } catch (Throwable $throwable) {
        $results[] = pcm_projection_qa_result(
            'C99 — execução completa sem fatal',
            false,
            get_class($throwable) . ': ' . $throwable->getMessage()
        );
    } finally {
        $wpdb->query($delete_sql);
    }

    return $results;
}

function pcm_projection_qa_insert(int $order_id, string $invoice_id, string $status): void
{
    global $wpdb;

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'paycrypto_me_lightning_invoices',
        [
            'order_id' => $order_id,
            'node_type' => 'btcpay',
            'invoice_id' => $invoice_id,
            'payment_request' => 'lnbc-qa-' . $order_id,
            'status' => $status,
            'expires_at' => '2030-01-01 00:00:00',
            'amount_sats' => 1000,
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%d']
    );

    if ($inserted !== 1) {
        throw new RuntimeException('Could not create QA fixture: ' . $wpdb->last_error);
    }
}
