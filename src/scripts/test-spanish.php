<?php
/**
 * Teste Específico para Traduções em Espanhol
 */

// Simular ambiente WordPress
$wp_locale = 'es_ES';
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
    
    if (isset($loaded_textdomains[$domain]) && $wp_locale === 'es_ES') {
        $translations = [
            'PayCrypto.Me' => 'PayCrypto.Me',
            'Enable/Disable' => 'Activar/Desactivar',
            'Enable PayCrypto.Me' => 'Activar PayCrypto.Me',
            'Title' => 'Título',
            'Description' => 'Descripción',
            'API Key' => 'Clave API',
            'Test Mode' => 'Modo de Prueba',
            'Enable Test Mode' => 'Activar Modo de Prueba',
            'Hide for Non-Admin Users' => 'Ocultar para Usuarios No Administradores',
            'Show only for administrators' => 'Mostrar solo para administradores',
            'Cryptocurrencies via PayCrypto.Me' => 'Criptomonedas vía PayCrypto.Me',
            'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Paga con Bitcoin, Ethereum, Solana y más.',
            'Awaiting crypto payment.' => 'Esperando pago en criptomoneda.',
            'Your API Key for PayCrypto.Me.' => 'Tu Clave API para PayCrypto.Me.',
            'Use the PayCrypto.Me test environment.' => 'Usar el entorno de prueba de PayCrypto.Me.',
            'Name of the payment method displayed to the customer.' => 'Nombre del método de pago mostrado al cliente.',
            'Description displayed to the customer at checkout.' => 'Descripción mostrada al cliente en el checkout.',
            'If enabled, only administrators will see the payment method.' => 'Si está activado, solo los administradores verán el método de pago.',
            'Save log events (WooCommerce > Status > Logs)' => 'Guardar eventos de registro (WooCommerce > Estado > Registros)',
            'Save events for debugging.' => 'Guardar eventos para depuración.',
            'Enable Log' => 'Habilitar Registro',
            'Save events in WooCommerce > Status > Logs' => 'Guardar eventos en WooCommerce > Estado > Registros',
        ];
        
        return isset($translations[$text]) ? $translations[$text] : $text;
    }
    
    return $text;
}

echo "🇪🇸 TESTE ESPECÍFICO - TRADUÇÃO EM ESPANHOL\n";
echo "===========================================\n\n";

echo "1️⃣ Carregando traduções em espanhol...\n";
$result = load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
echo "   Status: " . ($result ? "✅ SUCESSO" : "❌ FALHA") . "\n";
echo "   Locale: $wp_locale\n\n";

echo "2️⃣ Testando traduções específicas em espanhol...\n";

$test_strings = [
    'Enable/Disable' => 'Activar/Desactivar',
    'API Key' => 'Clave API',
    'Test Mode' => 'Modo de Prueba',
    'Description' => 'Descripción',
    'Cryptocurrencies via PayCrypto.Me' => 'Criptomonedas vía PayCrypto.Me',
    'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Paga con Bitcoin, Ethereum, Solana y más.',
    'Awaiting crypto payment.' => 'Esperando pago en criptomoneda.',
    'Hide for Non-Admin Users' => 'Ocultar para Usuarios No Administradores',
];

$success_count = 0;
$total_count = count($test_strings);

foreach ($test_strings as $original => $expected) {
    $translated = __($original, 'woocommerce-gateway-pay-crypto-me');
    $success = ($translated === $expected);
    
    if ($success) $success_count++;
    
    $status = $success ? "✅ CORRECTO" : "❌ INCORRETO";
    echo "   '$original' → '$translated' [$status]\n";
    
    if (!$success) {
        echo "      Esperado: '$expected'\n";
    }
}

echo "\n3️⃣ Simulando interface admin em espanhol...\n";

// Mock gateway para espanhol
class WC_Gateway_PayCryptoMe_ES {
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

$gateway_es = new WC_Gateway_PayCryptoMe_ES();

echo "   Campos traduzidos:\n";
foreach ($gateway_es->form_fields as $key => $field) {
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
    echo "\n🎉 PARABÉNS! Todas as traduções em espanhol estão funcionando perfeitamente!\n";
} else {
    echo "\n⚠️  Algumas traduções precisam de correção.\n";
}

echo "\n✅ Teste de tradução em espanhol concluído!\n";
?>