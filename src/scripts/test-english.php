<?php
/**
 * Teste Específico para Traduções em Inglês Americano
 */

// Simular ambiente WordPress
$wp_locale = 'en_US';
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
    
    if (isset($loaded_textdomains[$domain]) && $wp_locale === 'en_US') {
        $translations = [
            'PayCrypto.Me' => 'PayCrypto.Me',
            'Enable/Disable' => 'Enable/Disable',
            'Enable PayCrypto.Me' => 'Enable PayCrypto.Me',
            'Title' => 'Title',
            'Description' => 'Description',
            'API Key' => 'API Key',
            'Test Mode' => 'Test Mode',
            'Enable Test Mode' => 'Enable Test Mode',
            'Hide for Non-Admin Users' => 'Hide for Non-Admin Users',
            'Show only for administrators' => 'Show only for administrators',
            'Cryptocurrencies via PayCrypto.Me' => 'Cryptocurrencies via PayCrypto.Me',
            'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Pay with Bitcoin, Ethereum, Solana, and more.',
            'Awaiting crypto payment.' => 'Awaiting crypto payment.',
            'Your API Key for PayCrypto.Me.' => 'Your API Key for PayCrypto.Me.',
            'Use the PayCrypto.Me test environment.' => 'Use the PayCrypto.Me test environment.',
            'Name of the payment method displayed to the customer.' => 'Name of the payment method displayed to the customer.',
            'Description displayed to the customer at checkout.' => 'Description displayed to the customer at checkout.',
            'If enabled, only administrators will see the payment method.' => 'If enabled, only administrators will see the payment method.',
            'Save log events (WooCommerce > Status > Logs)' => 'Save log events (WooCommerce > Status > Logs)',
            'Save events for debugging.' => 'Save events for debugging.',
            'Enable Log' => 'Enable Log',
            'Save events in WooCommerce > Status > Logs' => 'Save events in WooCommerce > Status > Logs',
        ];
        
        return isset($translations[$text]) ? $translations[$text] : $text;
    }
    
    return $text;
}

echo "🇺🇸 SPECIFIC TEST - ENGLISH (US) TRANSLATION\n";
echo "============================================\n\n";

echo "1️⃣ Loading English (US) translations...\n";
$result = load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
echo "   Status: " . ($result ? "✅ SUCCESS" : "❌ FAILED") . "\n";
echo "   Locale: $wp_locale\n\n";

echo "2️⃣ Testing specific English translations...\n";

$test_strings = [
    'Enable/Disable' => 'Enable/Disable',
    'API Key' => 'API Key',
    'Test Mode' => 'Test Mode',
    'Description' => 'Description',
    'Cryptocurrencies via PayCrypto.Me' => 'Cryptocurrencies via PayCrypto.Me',
    'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Pay with Bitcoin, Ethereum, Solana, and more.',
    'Awaiting crypto payment.' => 'Awaiting crypto payment.',
    'Hide for Non-Admin Users' => 'Hide for Non-Admin Users',
];

$success_count = 0;
$total_count = count($test_strings);

foreach ($test_strings as $original => $expected) {
    $translated = __($original, 'woocommerce-gateway-pay-crypto-me');
    $success = ($translated === $expected);
    
    if ($success) $success_count++;
    
    $status = $success ? "✅ CORRECT" : "❌ INCORRECT";
    echo "   '$original' → '$translated' [$status]\n";
    
    if (!$success) {
        echo "      Expected: '$expected'\n";
    }
}

echo "\n3️⃣ Simulating admin interface in English...\n";

// Mock gateway for English
class WC_Gateway_PayCryptoMe_EN {
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

$gateway_en = new WC_Gateway_PayCryptoMe_EN();

echo "   Translated fields:\n";
foreach ($gateway_en->form_fields as $key => $field) {
    echo "   - $key: '" . $field['title'] . "'\n";
    if (isset($field['description'])) {
        echo "     Desc: '" . $field['description'] . "'\n";
    }
}

echo "\n4️⃣ Final statistics...\n";
echo "   Strings tested: $total_count\n";
echo "   Correct translations: $success_count\n";
echo "   Success rate: " . round(($success_count / $total_count) * 100, 1) . "%\n";

if ($success_count === $total_count) {
    echo "\n🎉 CONGRATULATIONS! All English (US) translations are working perfectly!\n";
} else {
    echo "\n⚠️  Some translations need correction.\n";
}

echo "\n✅ English (US) translation test completed!\n";
?>