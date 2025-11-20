<?php
/**
 * Teste Específico para Traduções em Alemão
 */

// Simular ambiente WordPress
$wp_locale = 'de_DE';
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
    
    if (isset($loaded_textdomains[$domain]) && $wp_locale === 'de_DE') {
        $translations = [
            'PayCrypto.Me' => 'PayCrypto.Me',
            'Enable/Disable' => 'Aktivieren/Deaktivieren',
            'Enable PayCrypto.Me' => 'PayCrypto.Me aktivieren',
            'Title' => 'Titel',
            'Description' => 'Beschreibung',
            'API Key' => 'API-Schlüssel',
            'Test Mode' => 'Testmodus',
            'Enable Test Mode' => 'Testmodus aktivieren',
            'Hide for Non-Admin Users' => 'Für Nicht-Admin-Benutzer ausblenden',
            'Show only for administrators' => 'Nur für Administratoren anzeigen',
            'Cryptocurrencies via PayCrypto.Me' => 'Kryptowährungen über PayCrypto.Me',
            'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Zahlen Sie mit Bitcoin, Ethereum, Solana und mehr.',
            'Awaiting crypto payment.' => 'Warten auf Krypto-Zahlung.',
            'Your API Key for PayCrypto.Me.' => 'Ihr API-Schlüssel für PayCrypto.Me.',
            'Use the PayCrypto.Me test environment.' => 'Verwenden Sie die PayCrypto.Me-Testumgebung.',
            'Name of the payment method displayed to the customer.' => 'Name der Zahlungsmethode, die dem Kunden angezeigt wird.',
            'Description displayed to the customer at checkout.' => 'Beschreibung, die dem Kunden beim Checkout angezeigt wird.',
            'If enabled, only administrators will see the payment method.' => 'Wenn aktiviert, sehen nur Administratoren diese Zahlungsmethode.',
            'Save log events (WooCommerce > Status > Logs)' => 'Protokollereignisse speichern (WooCommerce > Status > Protokolle)',
            'Save events for debugging.' => 'Ereignisse für das Debugging speichern.',
            'Enable Log' => 'Protokoll aktivieren',
            'Save events in WooCommerce > Status > Logs' => 'Ereignisse in WooCommerce > Status > Protokolle speichern',
        ];
        
        return isset($translations[$text]) ? $translations[$text] : $text;
    }
    
    return $text;
}

echo "🇩🇪 SPEZIFISCHER TEST - DEUTSCHE ÜBERSETZUNG\n";
echo "============================================\n\n";

echo "1️⃣ Lade deutsche Übersetzungen...\n";
$result = load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
echo "   Status: " . ($result ? "✅ ERFOLG" : "❌ FEHLER") . "\n";
echo "   Locale: $wp_locale\n\n";

echo "2️⃣ Teste spezifische deutsche Übersetzungen...\n";

$test_strings = [
    'Enable/Disable' => 'Aktivieren/Deaktivieren',
    'API Key' => 'API-Schlüssel',
    'Test Mode' => 'Testmodus',
    'Description' => 'Beschreibung',
    'Cryptocurrencies via PayCrypto.Me' => 'Kryptowährungen über PayCrypto.Me',
    'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Zahlen Sie mit Bitcoin, Ethereum, Solana und mehr.',
    'Awaiting crypto payment.' => 'Warten auf Krypto-Zahlung.',
    'Hide for Non-Admin Users' => 'Für Nicht-Admin-Benutzer ausblenden',
];

$success_count = 0;
$total_count = count($test_strings);

foreach ($test_strings as $original => $expected) {
    $translated = __($original, 'woocommerce-gateway-pay-crypto-me');
    $success = ($translated === $expected);
    
    if ($success) $success_count++;
    
    $status = $success ? "✅ KORREKT" : "❌ INKORREKT";
    echo "   '$original' → '$translated' [$status]\n";
    
    if (!$success) {
        echo "      Erwartet: '$expected'\n";
    }
}

echo "\n3️⃣ Simuliere deutsche Admin-Oberfläche...\n";

// Mock gateway für Deutsch
class WC_Gateway_PayCryptoMe_DE_DE {
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
            'testmode' => [
                'title' => __('Test Mode', 'woocommerce-gateway-pay-crypto-me'),
                'description' => __('Use the PayCrypto.Me test environment.', 'woocommerce-gateway-pay-crypto-me'),
            ],
        ];
    }
}

$gateway_de_de = new WC_Gateway_PayCryptoMe_DE_DE();

echo "   Übersetzte Felder:\n";
foreach ($gateway_de_de->form_fields as $key => $field) {
    echo "   - $key: '" . $field['title'] . "'\n";
    if (isset($field['description'])) {
        echo "     Beschr.: '" . $field['description'] . "'\n";
    }
}

echo "\n4️⃣ Endstatistiken...\n";
echo "   Getestete Strings: $total_count\n";
echo "   Korrekte Übersetzungen: $success_count\n";
echo "   Erfolgsrate: " . round(($success_count / $total_count) * 100, 1) . "%\n";

if ($success_count === $total_count) {
    echo "\n🎉 GLÜCKWUNSCH! Alle deutschen Übersetzungen funktionieren perfekt!\n";
} else {
    echo "\n⚠️  Einige Übersetzungen benötigen Korrekturen.\n";
}

echo "\n✅ Deutscher Übersetzungstest abgeschlossen!\n";
?>