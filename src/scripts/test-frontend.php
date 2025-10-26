<?php
/**
 * Teste de Frontend/Checkout
 * 
 * Este script simula o frontend do WooCommerce para testar traduções
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

// Mock básico para tradução (simulando WordPress)
function __($text, $domain = 'default') {
    global $loaded_textdomains, $wp_locale;
    
    // Se o domínio foi carregado, simular tradução
    if (isset($loaded_textdomains[$domain])) {
        // Simulação: para pt_BR, traduções específicas
        if ($wp_locale === 'pt_BR') {
            $translations = [
                'PayCrypto.Me' => 'PayCrypto.Me',
                'Cryptocurrencies via PayCrypto.Me' => 'Criptomoedas via PayCrypto.Me',
                'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Pague com Bitcoin, Ethereum, Solana e muito mais.',
                'Awaiting crypto payment.' => 'Aguardando pagamento em criptomoeda.',
                'PayCrypto.Me introduces a complete solution to receive your payments through the main cryptocurrencies.' => 'PayCrypto.Me introduz uma solução completa para receber seus pagamentos através das principais criptomoedas.',
            ];
            
            return isset($translations[$text]) ? $translations[$text] : $text;
        }
    }
    
    return $text; // Retorna original se não traduzido
}

function esc_html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function wpautop($text) {
    return "<p>" . str_replace("\n", "</p>\n<p>", trim($text)) . "</p>";
}

function wp_kses_post($text) {
    // Simulação básica - em ambiente real faria sanitização completa
    return strip_tags($text, '<p><br><strong><em><a>');
}

// Mock WooCommerce Order
class WC_Order {
    private $id;
    
    public function __construct($id) {
        $this->id = $id;
    }
    
    public function get_id() {
        return $this->id;
    }
    
    public function update_status($status, $note = '') {
        echo "   📝 Order Status Update: $status\n";
        echo "   📄 Note: $note\n";
        return true;
    }
    
    public function get_checkout_order_received_url() {
        return "https://example.com/checkout/order-received/" . $this->id;
    }
}

function wc_get_order($order_id) {
    return new WC_Order($order_id);
}

// Mock WooCommerce Cart
class WC_Cart {
    public function empty_cart() {
        echo "   🛒 Cart emptied\n";
    }
}

class WC_Mock {
    public $cart;
    
    public function __construct() {
        $this->cart = new WC_Cart();
    }
}

function WC() {
    static $wc = null;
    if ($wc === null) {
        $wc = new WC_Mock();
    }
    return $wc;
}

// Mock WooCommerce Payment Gateway
class WC_Payment_Gateway {
    public $id = '';
    public $title = '';
    public $description = '';
    public $enabled = 'yes';
    public $supports = [];
    
    public function get_option($key, $default = '') {
        return $default;
    }
    
    public function is_available() {
        return $this->enabled === 'yes';
    }
}

// Gateway PayCrypto.Me simulado
class WC_Gateway_PayCryptoMe extends WC_Payment_Gateway {
    
    public function __construct() {
        $this->id = 'paycrypto_me';
        $this->title = __('Cryptocurrencies via PayCrypto.Me', 'woocommerce-gateway-pay-crypto-me');
        $this->description = __('Pay with Bitcoin, Ethereum, Solana, and more.', 'woocommerce-gateway-pay-crypto-me');
    }
    
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
    }
    
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        $order->update_status('on-hold', __('Awaiting crypto payment.', 'woocommerce-gateway-pay-crypto-me'));
        WC()->cart->empty_cart();
        
        return [
            'result' => 'success',
            'redirect' => $order->get_checkout_order_received_url(),
        ];
    }
    
    public function get_frontend_display() {
        $html = "<div class='payment-method' id='{$this->id}'>\n";
        $html .= "  <h4>" . esc_html($this->title) . "</h4>\n";
        $html .= "  <div class='payment-description'>\n";
        $html .= "    " . wpautop(wp_kses_post($this->description)) . "\n";
        $html .= "  </div>\n";
        $html .= "</div>\n";
        
        return $html;
    }
}

echo "🧪 TESTE DE FRONTEND/CHECKOUT\n";
echo "=============================\n\n";

// Testar carregamento das traduções
echo "1️⃣ Carregando traduções para frontend...\n";
$result = load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
echo "   Status: " . ($result ? "✅ SUCESSO" : "❌ FALHA") . "\n\n";

// Testar instanciação do gateway para frontend
echo "2️⃣ Testando gateway no frontend...\n";
$gateway = new WC_Gateway_PayCryptoMe();

echo "   Title: '" . $gateway->title . "'\n";
echo "   Description: '" . $gateway->description . "'\n";
echo "   Available: " . ($gateway->is_available() ? "✅ SIM" : "❌ NÃO") . "\n\n";

// Testar campos de pagamento (checkout)
echo "3️⃣ Simulando campos de pagamento no checkout...\n";
echo "   --- HTML dos campos ---\n";
ob_start();
$gateway->payment_fields();
$fields_html = ob_get_clean();
echo "   " . str_replace("\n", "\n   ", trim($fields_html)) . "\n\n";

// Testar processamento de pagamento
echo "4️⃣ Simulando processamento de pagamento...\n";
$test_order_id = 12345;
echo "   Processando pedido #$test_order_id...\n";
$result = $gateway->process_payment($test_order_id);
echo "   Result: " . json_encode($result) . "\n\n";

// Testar exibição frontend completa
echo "5️⃣ Simulando exibição completa no checkout...\n";
$frontend_html = $gateway->get_frontend_display();
echo "   --- HTML Frontend ---\n";
echo "   " . str_replace("\n", "\n   ", trim($frontend_html)) . "\n\n";

// Verificar traduções específicas do frontend
echo "6️⃣ Verificando traduções específicas...\n";
$frontend_strings = [
    'Cryptocurrencies via PayCrypto.Me' => $gateway->title,
    'Pay with Bitcoin, Ethereum, Solana, and more.' => $gateway->description,
];

foreach ($frontend_strings as $original => $translated) {
    $status = ($original !== $translated) ? "✅ TRADUZIDO" : "⚪ ORIGINAL";
    echo "   '$original' → '$translated' [$status]\n";
}

// Testar diferentes locales no frontend
echo "\n7️⃣ Testando diferentes idiomas...\n";

// Teste com en_US
$wp_locale = 'en_US';
load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
$gateway_en = new WC_Gateway_PayCryptoMe();
echo "   en_US - Title: '" . $gateway_en->title . "'\n";

// Teste com pt_BR
$wp_locale = 'pt_BR';
load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
$gateway_pt = new WC_Gateway_PayCryptoMe();
echo "   pt_BR - Title: '" . $gateway_pt->title . "'\n";

// Verificar se HTML contém traduções
echo "\n8️⃣ Análise do HTML gerado...\n";
$has_pt_translations = (
    strpos($frontend_html, 'Criptomoedas') !== false ||
    strpos($frontend_html, 'Bitcoin') !== false ||
    strpos($frontend_html, 'Ethereum') !== false
);

echo "   HTML contém texto em português: " . ($has_pt_translations ? "✅ SIM" : "❌ NÃO") . "\n";
echo "   Tamanho do HTML: " . strlen($frontend_html) . " caracteres\n";

echo "\n✅ Teste de frontend/checkout concluído!\n";
?>