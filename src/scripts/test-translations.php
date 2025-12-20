<?php
/**
 * Script de teste para verificar carregamento de traduções
 */

// Simular algumas constantes e funções básicas do WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

// Função mock para plugin_basename
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        $file = str_replace('\\', '/', $file);
        $file = preg_replace('|^.*?/wp-content/plugins/|', '', $file);
        return $file;
    }
}

// Função mock para load_plugin_textdomain
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false) {
        $plugin_dir = dirname(__FILE__) . '/../';
        $locale = 'pt_BR'; // Simular idioma português
        
        $mo_file = $plugin_dir . $plugin_rel_path . $domain . '-' . $locale . '.mo';
        
        echo "🔍 Tentando carregar: $mo_file\n";
        echo "📁 Arquivo existe: " . (file_exists($mo_file) ? "✅ SIM" : "❌ NÃO") . "\n";
        
        if (file_exists($mo_file)) {
            echo "📄 Tamanho do arquivo: " . filesize($mo_file) . " bytes\n";
            return true;
        }
        
        return false;
    }
}

echo "🧪 TESTE DE CARREGAMENTO DE TRADUÇÕES\n";
echo "=====================================\n\n";

echo "🚀 Iniciando teste...\n\n";

echo "1️⃣ Testando load_textdomain():\n";
$result = load_plugin_textdomain('woocommerce-gateway-paycrypto-me', false, 'languages/');
echo "   Resultado: " . ($result ? "✅ SUCESSO" : "❌ FALHA") . "\n\n";

echo "2️⃣ Verificando arquivos necessários:\n";
$plugin_dir = dirname(__FILE__) . '/../';

$files_to_check = [
    'woocommerce-gateway-paycrypto-me.pot',
    'woocommerce-gateway-paycrypto-me-pt_BR.po',
    'woocommerce-gateway-paycrypto-me-pt_BR.mo',
    'woocommerce-gateway-paycrypto-me-en_US.po',
    'woocommerce-gateway-paycrypto-me-en_US.mo'
];

foreach ($files_to_check as $file) {
    $full_path = $plugin_dir . 'languages/' . $file;
    $exists = file_exists($full_path);
    $size = $exists ? filesize($full_path) : 0;
    
    echo "   📄 $file: " . ($exists ? "✅ ($size bytes)" : "❌ AUSENTE") . "\n";
}

echo "\n✅ Teste concluído!\n";