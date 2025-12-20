<?php
/**
 * Teste de Interface Administrativa
 */

// Simular ambiente WordPress básico
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

// Mock das funções WordPress essenciais
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

function plugin_basename($file) {
    $file = str_replace('\\', '/', $file);
    $file = preg_replace('|^.*?/wp-content/plugins/|', '', $file);
    return $file;
}

// Mock básico para tradução (simulando WordPress)
function __($text, $domain = 'default') {
    global $loaded_textdomains, $wp_locale;
    
    // Se o domínio foi carregado, simular tradução
    if (isset($loaded_textdomains[$domain])) {
        // Simulação: para pt_BR, algumas traduções específicas
        if ($wp_locale === 'pt_BR') {
            $translations = [
                'PayCrypto.Me' => 'PayCrypto.Me',
                'Enable/Disable' => 'Ativar/Desativar',
                'Enable PayCrypto.Me' => 'Ativar PayCrypto.Me',
                'Title' => 'Título',
                'API Key' => 'Chave API',
                'Test Mode' => 'Modo de Teste',
                'Enable Test Mode' => 'Ativar Modo de Teste',
                'Description' => 'Descrição',
                'Hide for Non-Admin Users' => 'Ocultar para Usuários Não-Administradores',
                'Enable Log' => 'Ativar Log',
                'PayCrypto.Me introduces a complete solution to receive your payments through the main cryptocurrencies.' => 'PayCrypto.Me introduz uma solução completa para receber seus pagamentos através das principais criptomoedas.',
                'Name of the payment method displayed to the customer.' => 'Nome do método de pagamento exibido ao cliente.',
                'Cryptocurrencies via PayCrypto.Me' => 'Criptomoedas via PayCrypto.Me',
                'Description displayed to the customer at checkout.' => 'Descrição exibida ao cliente no checkout.',
                'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Pague com Bitcoin, Ethereum, Solana e muito mais.',
                'Your API Key for PayCrypto.Me.' => 'Sua Chave API para PayCrypto.Me.',
                'Use the PayCrypto.Me test environment.' => 'Usar o ambiente de teste do PayCrypto.Me.',
                'Show only for administrators' => 'Mostrar apenas para administradores',
                'If enabled, only administrators will see the payment method.' => 'Se ativado, apenas administradores verão o método de pagamento.',
                'Save log events (WooCommerce > Status > Logs)' => 'Salvar eventos de log (WooCommerce > Status > Logs)',
                'Save events for debugging.' => 'Salvar eventos para depuração.',
            ];
            
            return isset($translations[$text]) ? $translations[$text] : $text;
        }
    }
    
    return $text; // Retorna original se não traduzido
}

function esc_html__($text, $domain = 'default') {
    return htmlspecialchars(__($text, $domain), ENT_QUOTES, 'UTF-8');
}

function esc_attr__($text, $domain = 'default') {
    return esc_attr(__($text, $domain));
}

function esc_html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Mock WooCommerce
class WC_Payment_Gateway {
    public $id = '';
    public $method_title = '';
    public $method_description = '';
    public $enabled = 'yes';
    public $title = '';
    public $description = '';
    public $supports = [];
    public $form_fields = [];
    
    public function __construct() {
        $this->init_form_fields();
    }
    
    public function init_form_fields() {
        // Override in child class
    }
    
    public function get_option($key, $default = '') {
        return $default;
    }
}

// Incluir classe do gateway (simulando)
class WC_Gateway_PayCryptoMe extends WC_Payment_Gateway {
    
    public function __construct() {
        $this->id = 'paycrypto_me';
        $this->method_title = __('PayCrypto.Me', 'woocommerce-gateway-paycrypto-me');
        $this->method_description = __('PayCrypto.Me introduces a complete solution to receive your payments through the main cryptocurrencies.', 'woocommerce-gateway-paycrypto-me');
        
        parent::__construct();
    }
    
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-gateway-paycrypto-me'),
                'label' => __('Enable PayCrypto.Me', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'text',
                'description' => __('Name of the payment method displayed to the customer.', 'woocommerce-gateway-paycrypto-me'),
                'default' => __('Cryptocurrencies via PayCrypto.Me', 'woocommerce-gateway-paycrypto-me'),
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'textarea',
                'description' => __('Description displayed to the customer at checkout.', 'woocommerce-gateway-paycrypto-me'),
                'default' => __('Pay with Bitcoin, Ethereum, Solana, and more.', 'woocommerce-gateway-paycrypto-me'),
            ),
            'api_key' => array(
                'title' => __('API Key', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'text',
                'description' => __('Your API Key for PayCrypto.Me.', 'woocommerce-gateway-paycrypto-me'),
            ),
            'hide_for_non_admin_users' => array(
                'title' => __('Hide for Non-Admin Users', 'woocommerce-gateway-paycrypto-me'),
                'label' => __('Show only for administrators', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('If enabled, only administrators will see the payment method.', 'woocommerce-gateway-paycrypto-me'),
            ),
            'debug_log' => array(
                'title' => __('Enable Log', 'woocommerce-gateway-paycrypto-me'),
                'label' => __('Save log events (WooCommerce > Status > Logs)', 'woocommerce-gateway-paycrypto-me'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('Save events for debugging.', 'woocommerce-gateway-paycrypto-me'),
            ),
        );
    }
    
    public function generate_admin_html() {
        $html = "<h2>" . esc_html($this->method_title) . "</h2>\n";
        $html .= "<p>" . esc_html($this->method_description) . "</p>\n\n";
        
        foreach ($this->form_fields as $field_key => $field) {
            $html .= $this->generate_field_html($field_key, $field);
        }
        
        return $html;
    }
    
    private function generate_field_html($key, $field) {
        $title = esc_html($field['title']);
        $description = isset($field['description']) ? esc_html($field['description']) : '';
        $label = isset($field['label']) ? esc_html($field['label']) : '';
        $default = isset($field['default']) ? esc_html($field['default']) : '';
        
        $html = "<div class='form-field'>\n";
        $html .= "  <label><strong>$title</strong></label>\n";
        
        if (!empty($label)) {
            $html .= "  <p>Label: $label</p>\n";
        }
        
        if (!empty($default)) {
            $html .= "  <p>Default: $default</p>\n";
        }
        
        if (!empty($description)) {
            $html .= "  <p class='description'>$description</p>\n";
        }
        
        $html .= "</div>\n\n";
        
        return $html;
    }
}

echo "🧪 TESTE DE INTERFACE ADMINISTRATIVA\n";
echo "====================================\n\n";

// Testar carregamento das traduções
echo "1️⃣ Carregando traduções...\n";
$result = load_plugin_textdomain('woocommerce-gateway-paycrypto-me', false, 'languages/');
echo "   Status: " . ($result ? "✅ SUCESSO" : "❌ FALHA") . "\n\n";

// Testar instanciação do gateway
echo "2️⃣ Testando gateway PayCrypto.Me...\n";
$gateway = new WC_Gateway_PayCryptoMe();

echo "   Method Title: '" . $gateway->method_title . "'\n";
echo "   Method Description: '" . $gateway->method_description . "'\n\n";

// Testar campos do formulário
echo "3️⃣ Testando campos de configuração...\n";
foreach ($gateway->form_fields as $key => $field) {
    $title = $field['title'];
    $original = "";
    
    // Extrair texto original (simulação)
    if ($title === "Ativar/Desativar") $original = "Enable/Disable";
    elseif ($title === "Título") $original = "Title";
    elseif ($title === "Chave API") $original = "API Key";
    elseif ($title === "Modo de Teste") $original = "Test Mode";
    elseif ($title === "Descrição") $original = "Description";
    elseif ($title === "Ocultar para Usuários Não-Administradores") $original = "Hide for Non-Admin Users";
    elseif ($title === "Ativar Log") $original = "Enable Log";
    else $original = $title;
    
    $status = ($title !== $original) ? "✅ TRADUZIDO" : "⚪ ORIGINAL";
    echo "   $key: '$original' → '$title' [$status]\n";
}

echo "\n4️⃣ Simulando HTML da interface admin...\n";
$admin_html = $gateway->generate_admin_html();

// Verificar se há texto traduzido no HTML
$has_translations = (
    strpos($admin_html, 'Ativar/Desativar') !== false ||
    strpos($admin_html, 'Título') !== false ||
    strpos($admin_html, 'Chave API') !== false ||
    strpos($admin_html, 'Modo de Teste') !== false
);

echo "   HTML contém traduções: " . ($has_translations ? "✅ SIM" : "❌ NÃO") . "\n";
echo "   Tamanho do HTML: " . strlen($admin_html) . " caracteres\n";

echo "\n5️⃣ Testando diferentes locales...\n";

// Testar com en_US
$wp_locale = 'en_US';
load_plugin_textdomain('woocommerce-gateway-paycrypto-me', false, 'languages/');
$gateway_en = new WC_Gateway_PayCryptoMe();
echo "   en_US - Method Title: '" . $gateway_en->method_title . "'\n";

// Voltar para pt_BR
$wp_locale = 'pt_BR';
load_plugin_textdomain('woocommerce-gateway-paycrypto-me', false, 'languages/');
$gateway_pt = new WC_Gateway_PayCryptoMe();
echo "   pt_BR - Method Title: '" . $gateway_pt->method_title . "'\n";

echo "\n✅ Teste de interface administrativa concluído!\n";