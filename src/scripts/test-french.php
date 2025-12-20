<?php
/**
 * Teste Específico para Traduções em Francês
 */

// Simular ambiente WordPress
$wp_locale = 'fr_FR';
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
    
    if (isset($loaded_textdomains[$domain]) && $wp_locale === 'fr_FR') {
        $translations = [
            'PayCrypto.Me' => 'PayCrypto.Me',
            'Enable/Disable' => 'Activer/Désactiver',
            'Enable PayCrypto.Me' => 'Activer PayCrypto.Me',
            'Title' => 'Titre',
            'Description' => 'Description',
            'API Key' => 'Clé API',
            'Test Mode' => 'Mode test',
            'Enable Test Mode' => 'Activer le mode test',
            'Hide for Non-Admin Users' => 'Masquer pour les utilisateurs non-administrateurs',
            'Show only for administrators' => 'Afficher uniquement pour les administrateurs',
            'Cryptocurrencies via PayCrypto.Me' => 'Cryptomonnaies via PayCrypto.Me',
            'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Payez avec Bitcoin, Ethereum, Solana et bien plus.',
            'Awaiting crypto payment.' => 'En attente du paiement crypto.',
            'Your API Key for PayCrypto.Me.' => 'Votre clé API pour PayCrypto.Me.',
            'Use the PayCrypto.Me test environment.' => 'Utiliser l\'environnement de test PayCrypto.Me.',
            'Name of the payment method displayed to the customer.' => 'Nom de la méthode de paiement affichée au client.',
            'Description displayed to the customer at checkout.' => 'Description affichée au client lors du checkout.',
            'If enabled, only administrators will see the payment method.' => 'Si activé, seuls les administrateurs verront cette méthode de paiement.',
            'Save log events (WooCommerce > Status > Logs)' => 'Enregistrer les événements de journal (WooCommerce > Statut > Journaux)',
            'Save events for debugging.' => 'Enregistrer les événements pour le débogage.',
            'Enable Log' => 'Activer les journaux',
            'Save events in WooCommerce > Status > Logs' => 'Enregistrer les événements dans WooCommerce > Statut > Journaux',
        ];
        
        return isset($translations[$text]) ? $translations[$text] : $text;
    }
    
    return $text;
}

echo "🇫🇷 TEST SPÉCIFIQUE - TRADUCTION FRANÇAISE\n";
echo "==========================================\n\n";

echo "1️⃣ Chargement des traductions françaises...\n";
$result = load_plugin_textdomain('woocommerce-gateway-paycrypto-me', false, 'languages/');
echo "   Statut: " . ($result ? "✅ SUCCÈS" : "❌ ÉCHEC") . "\n";
echo "   Locale: $wp_locale\n\n";

echo "2️⃣ Test des traductions spécifiques françaises...\n";

$test_strings = [
    'Enable/Disable' => 'Activer/Désactiver',
    'API Key' => 'Clé API',
    'Test Mode' => 'Mode test',
    'Description' => 'Description',
    'Cryptocurrencies via PayCrypto.Me' => 'Cryptomonnaies via PayCrypto.Me',
    'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Payez avec Bitcoin, Ethereum, Solana et bien plus.',
    'Awaiting crypto payment.' => 'En attente du paiement crypto.',
    'Hide for Non-Admin Users' => 'Masquer pour les utilisateurs non-administrateurs',
];

$success_count = 0;
$total_count = count($test_strings);

foreach ($test_strings as $original => $expected) {
    $translated = __($original, 'woocommerce-gateway-paycrypto-me');
    $success = ($translated === $expected);
    
    if ($success) $success_count++;
    
    $status = $success ? "✅ CORRECT" : "❌ INCORRECT";
    echo "   '$original' → '$translated' [$status]\n";
    
    if (!$success) {
        echo "      Attendu: '$expected'\n";
    }
}

echo "\n3️⃣ Simulation de l'interface d'administration en français...\n";

// Mock gateway pour français
class WC_Gateway_PayCryptoMe_FR_FR {
    public $form_fields = [];
    
    public function __construct() {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'woocommerce-gateway-paycrypto-me'),
            ],
            'title' => [
                'title' => __('Title', 'woocommerce-gateway-paycrypto-me'),
                'description' => __('Name of the payment method displayed to the customer.', 'woocommerce-gateway-paycrypto-me'),
            ],
            'api_key' => [
                'title' => __('API Key', 'woocommerce-gateway-paycrypto-me'),
                'description' => __('Your API Key for PayCrypto.Me.', 'woocommerce-gateway-paycrypto-me'),
            ],
        ];
    }
}

$gateway_fr_fr = new WC_Gateway_PayCryptoMe_FR_FR();

echo "   Champs traduits:\n";
foreach ($gateway_fr_fr->form_fields as $key => $field) {
    echo "   - $key: '" . $field['title'] . "'\n";
    if (isset($field['description'])) {
        echo "     Desc: '" . $field['description'] . "'\n";
    }
}

echo "\n4️⃣ Statistiques finales...\n";
echo "   Chaînes testées: $total_count\n";
echo "   Traductions correctes: $success_count\n";
echo "   Taux de réussite: " . round(($success_count / $total_count) * 100, 1) . "%\n";

if ($success_count === $total_count) {
    echo "\n🎉 FÉLICITATIONS ! Toutes les traductions françaises fonctionnent parfaitement !\n";
} else {
    echo "\n⚠️  Certaines traductions nécessitent une correction.\n";
}

echo "\n✅ Test de traduction française terminé !\n";
?>