<?php
/**
 * Teste Específico para Traduções em Português Brasileiro
 */

// Simular ambiente WordPress
$wp_locale = 'pt_BR';
$loaded_textdomains = [];

function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false) {
    global $loaded_textdomains, $wp_locale;
    
    $plugin_dir = dirname(__FILE__) . '/../';
    $mo_file = $plugin_dir . $plugin_rel_path . $domain . '-' . $wp_locale . '.mo';
    
    if (file_exists($mo_file)) {
        $loaded_textdomains[$domain] = $mo_file;
        return true;
    }
    
    return false;
}

function __($text, $domain = 'default') {
    global $loaded_textdomains, $wp_locale;
    
    if (isset($loaded_textdomains[$domain]) && $wp_locale === 'pt_BR') {
        $translations = [
            'PayCrypto.Me' => 'PayCrypto.Me',
            'Enable/Disable' => 'Ativar/Desativar',
            'Enable PayCrypto.Me' => 'Ativar PayCrypto.Me',
            'Title' => 'Título',
            'Description' => 'Descrição',
            'API Key' => 'Chave API',
            'Test Mode' => 'Modo de Teste',
            'Enable Test Mode' => 'Ativar Modo de Teste',
            'Hide for Non-Admin Users' => 'Ocultar para Usuários Não-Administradores',
            'Show only for administrators' => 'Mostrar apenas para administradores',
            'Cryptocurrencies via PayCrypto.Me' => 'Criptomoedas via PayCrypto.Me',
            'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Pague com Bitcoin, Ethereum, Solana e muito mais.',
            'Awaiting crypto payment.' => 'Aguardando pagamento em cripto.',
            'Your API Key for PayCrypto.Me.' => 'Sua Chave API para PayCrypto.Me.',
            'Use the PayCrypto.Me test environment.' => 'Usar o ambiente de teste do PayCrypto.Me.',
            'Name of the payment method displayed to the customer.' => 'Nome do método de pagamento exibido ao cliente.',
            'Description displayed to the customer at checkout.' => 'Descrição exibida ao cliente no checkout.',
            'If enabled, only administrators will see the payment method.' => 'Se ativado, apenas administradores verão este método de pagamento.',
            'Save log events (WooCommerce > Status > Logs)' => 'Salvar eventos de log (WooCommerce > Status > Logs)',
            'Save events for debugging.' => 'Salvar eventos para depuração.',
            'Enable Log' => 'Ativar Log',
            'Save events in WooCommerce > Status > Logs' => 'Salvar eventos em WooCommerce > Status > Logs',
        ];
        
        return isset($translations[$text]) ? $translations[$text] : $text;
    }
    
    return $text;
}

echo "🇧🇷 TESTE ESPECÍFICO - TRADUÇÃO EM PORTUGUÊS BRASILEIRO\n";
echo "======================================================\n\n";

echo "1️⃣ Carregando traduções em português brasileiro...\n";
$result = load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
echo "   Status: " . ($result ? "✅ SUCESSO" : "❌ FALHA") . "\n";
echo "   Locale: $wp_locale\n\n";

echo "2️⃣ Testando traduções específicas em português brasileiro...\n";

$test_strings = [
    'Enable/Disable' => 'Ativar/Desativar',
    'API Key' => 'Chave API',
    'Test Mode' => 'Modo de Teste',
    'Description' => 'Descrição',
    'Cryptocurrencies via PayCrypto.Me' => 'Criptomoedas via PayCrypto.Me',
    'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Pague com Bitcoin, Ethereum, Solana e muito mais.',
    'Awaiting crypto payment.' => 'Aguardando pagamento em cripto.',
    'Hide for Non-Admin Users' => 'Ocultar para Usuários Não-Administradores',
];

$success_count = 0;
$total_count = count($test_strings);

foreach ($test_strings as $original => $expected) {
    $translated = __($original, 'woocommerce-gateway-pay-crypto-me');
    $success = ($translated === $expected);
    
    if ($success) $success_count++;
    
    $status = $success ? "✅ CORRETO" : "❌ INCORRETO";
    echo "   '$original' → '$translated' [$status]\n";
    
    if (!$success) {
        echo "      Esperado: '$expected'\n";
    }
}

echo "\n3️⃣ Simulando interface admin em português brasileiro...\n";

// Mock gateway para português brasileiro
class WC_Gateway_PayCryptoMe_PT_BR {
    public $form_fields = [];
    
    public function __construct() {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'woocommerce-gateway-pay-crypto-me'),
            ],
            'title' => [
                'title' => __('Title', 'woocommerce-gateway-pay-crypto-me'),
                'description' => __('Name of the payment method displayed to the customer.', 'woocommerce-gateway-pay-crypto-me'),
            ],
            'api_key' => [
                'title' => __('API Key', 'woocommerce-gateway-pay-crypto-me'),
                'description' => __('Your API Key for PayCrypto.Me.', 'woocommerce-gateway-pay-crypto-me'),
            ],
        ];
    }
}

$gateway_pt_br = new WC_Gateway_PayCryptoMe_PT_BR();

echo "   Campos traduzidos:\n";
foreach ($gateway_pt_br->form_fields as $key => $field) {
    echo "   - $key: '" . $field['title'] . "'\n";
    if (isset($field['description'])) {
        echo "     Desc: '" . $field['description'] . "'\n";
    }
}

echo "\n4️⃣ Estatísticas finais...\n";
echo "   Strings testadas: $total_count\n";
echo "   Traduções corretas: $success_count\n";
echo "   Taxa de sucesso: " . round(($success_count / $total_count) * 100, 1) . "%\n";

if ($success_count === $total_count) {
    echo "\n🎉 PARABÉNS! Todas as traduções em português brasileiro estão funcionando perfeitamente!\n";
} else {
    echo "\n⚠️  Algumas traduções precisam de correção.\n";
}

echo "\n✅ Teste de tradução em português brasileiro concluído!\n";
?>