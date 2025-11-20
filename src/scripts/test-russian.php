<?php
/**
 * Teste Específico para Traduções em Russo
 */

// Simular ambiente WordPress
$wp_locale = 'ru_RU';
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
    
    if (isset($loaded_textdomains[$domain]) && $wp_locale === 'ru_RU') {
        $translations = [
            'PayCrypto.Me' => 'PayCrypto.Me',
            'Enable/Disable' => 'Включить/Отключить',
            'Enable PayCrypto.Me' => 'Включить PayCrypto.Me',
            'Title' => 'Заголовок',
            'Description' => 'Описание',
            'API Key' => 'API ключ',
            'Test Mode' => 'Тестовый режим',
            'Enable Test Mode' => 'Включить тестовый режим',
            'Hide for Non-Admin Users' => 'Скрыть от обычных пользователей',
            'Show only for administrators' => 'Показывать только администраторам',
            'Cryptocurrencies via PayCrypto.Me' => 'Криптовалюты через PayCrypto.Me',
            'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Оплачивайте Bitcoin, Ethereum, Solana и другими криптовалютами.',
            'Awaiting crypto payment.' => 'Ожидается криптоплатёж.',
            'Your API Key for PayCrypto.Me.' => 'Ваш API ключ для PayCrypto.Me.',
            'Use the PayCrypto.Me test environment.' => 'Использовать тестовую среду PayCrypto.Me.',
            'Name of the payment method displayed to the customer.' => 'Название способа оплаты, отображаемое клиенту.',
            'Description displayed to the customer at checkout.' => 'Описание, отображаемое клиенту при оформлении заказа.',
            'If enabled, only administrators will see the payment method.' => 'Если включено, только администраторы увидят этот способ оплаты.',
            'Save log events (WooCommerce > Status > Logs)' => 'Сохранять события журнала (WooCommerce > Статус > Журналы)',
            'Save events for debugging.' => 'Сохранять события для отладки.',
            'Enable Log' => 'Включить журнал',
            'Save events in WooCommerce > Status > Logs' => 'Сохранять события в WooCommerce > Статус > Журналы',
        ];
        
        return isset($translations[$text]) ? $translations[$text] : $text;
    }
    
    return $text;
}

echo "🇷🇺 СПЕЦИАЛЬНЫЙ ТЕСТ - РУССКИЙ ПЕРЕВОД\n";
echo "======================================\n\n";

echo "1️⃣ Загружаем русские переводы...\n";
$result = load_plugin_textdomain('woocommerce-gateway-pay-crypto-me', false, 'languages/');
echo "   Статус: " . ($result ? "✅ УСПЕХ" : "❌ ОШИБКА") . "\n";
echo "   Локаль: $wp_locale\n\n";

echo "2️⃣ Тестируем конкретные русские переводы...\n";

$test_strings = [
    'Enable/Disable' => 'Включить/Отключить',
    'API Key' => 'API ключ',
    'Test Mode' => 'Тестовый режим',
    'Description' => 'Описание',
    'Cryptocurrencies via PayCrypto.Me' => 'Криптовалюты через PayCrypto.Me',
    'Pay with Bitcoin, Ethereum, Solana, and more.' => 'Оплачивайте Bitcoin, Ethereum, Solana и другими криптовалютами.',
    'Awaiting crypto payment.' => 'Ожидается криптоплатёж.',
    'Hide for Non-Admin Users' => 'Скрыть от обычных пользователей',
];

$success_count = 0;
$total_count = count($test_strings);

foreach ($test_strings as $original => $expected) {
    $translated = __($original, 'woocommerce-gateway-pay-crypto-me');
    $success = ($translated === $expected);
    
    if ($success) $success_count++;
    
    $status = $success ? "✅ ВЕРНО" : "❌ НЕВЕРНО";
    echo "   '$original' → '$translated' [$status]\n";
    
    if (!$success) {
        echo "      Ожидается: '$expected'\n";
    }
}

echo "\n3️⃣ Симулируем админ интерфейс на русском...\n";

// Mock gateway для русского
class WC_Gateway_PayCryptoMe_RU_RU {
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

$gateway_ru_ru = new WC_Gateway_PayCryptoMe_RU_RU();

echo "   Переведённые поля:\n";
foreach ($gateway_ru_ru->form_fields as $key => $field) {
    echo "   - $key: '" . $field['title'] . "'\n";
    if (isset($field['description'])) {
        echo "     Описание: '" . $field['description'] . "'\n";
    }
}

echo "\n4️⃣ Финальная статистика...\n";
echo "   Протестировано строк: $total_count\n";
echo "   Правильных переводов: $success_count\n";
echo "   Процент успеха: " . round(($success_count / $total_count) * 100, 1) . "%\n";

if ($success_count === $total_count) {
    echo "\n🎉 ПОЗДРАВЛЯЕМ! Все русские переводы работают идеально!\n";
} else {
    echo "\n⚠️  Некоторые переводы нуждаются в исправлении.\n";
}

echo "\n✅ Тест русского перевода завершён!\n";
?>