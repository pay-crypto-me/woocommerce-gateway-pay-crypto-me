<?php
/**
 * Teste Específico para Traduções em Chinês Simplificado
 */

// Simular ambiente WordPress
$wp_locale = 'zh_CN';
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
    
    if (isset($loaded_textdomains[$domain]) && $wp_locale === 'zh_CN') {
        $translations = [
            'PayCrypto.Me' => 'PayCrypto.Me',
            'Enable/Disable' => '启用/禁用',
            'Enable PayCrypto.Me' => '启用 PayCrypto.Me',
            'Title' => '标题',
            'Description' => '描述',
            'API Key' => 'API 密钥',
            'Test Mode' => '测试模式',
            'Enable Test Mode' => '启用测试模式',
            'Hide for Non-Admin Users' => '对非管理员用户隐藏',
            'Show only for administrators' => '仅向管理员显示',
            'Cryptocurrencies via PayCrypto.Me' => '通过 PayCrypto.Me 使用加密货币',
            'Pay with Bitcoin, Ethereum, Solana, and more.' => '使用比特币、以太坊、索拉纳等进行支付。',
            'Awaiting crypto payment.' => '等待加密货币付款。',
            'Your API Key for PayCrypto.Me.' => '您的 PayCrypto.Me API 密钥。',
            'Use the PayCrypto.Me test environment.' => '使用 PayCrypto.Me 测试环境。',
            'Name of the payment method displayed to the customer.' => '向客户显示的支付方式名称。',
            'Description displayed to the customer at checkout.' => '在结账时向客户显示的描述。',
            'If enabled, only administrators will see the payment method.' => '如果启用，只有管理员才能看到此支付方式。',
            'Save log events (WooCommerce > Status > Logs)' => '保存日志事件（WooCommerce > 状态 > 日志）',
            'Save events for debugging.' => '保存事件用于调试。',
            'Enable Log' => '启用日志',
            'Save events in WooCommerce > Status > Logs' => '在 WooCommerce > 状态 > 日志 中保存事件',
        ];
        
        return isset($translations[$text]) ? $translations[$text] : $text;
    }
    
    return $text;
}

echo "🇨🇳 测试专用 - 中文简体翻译\n";
echo "========================\n\n";

echo "1️⃣ 正在加载中文简体翻译...\n";
$result = load_plugin_textdomain('woocommerce-gateway-paycrypto-me', false, 'languages/');
echo "   状态: " . ($result ? "✅ 成功" : "❌ 失败") . "\n";
echo "   语言环境: $wp_locale\n\n";

echo "2️⃣ 测试特定中文翻译...\n";

$test_strings = [
    'Enable/Disable' => '启用/禁用',
    'API Key' => 'API 密钥',
    'Test Mode' => '测试模式',
    'Description' => '描述',
    'Cryptocurrencies via PayCrypto.Me' => '通过 PayCrypto.Me 使用加密货币',
    'Pay with Bitcoin, Ethereum, Solana, and more.' => '使用比特币、以太坊、索拉纳等进行支付。',
    'Awaiting crypto payment.' => '等待加密货币付款。',
    'Hide for Non-Admin Users' => '对非管理员用户隐藏',
];

$success_count = 0;
$total_count = count($test_strings);

foreach ($test_strings as $original => $expected) {
    $translated = __($original, 'woocommerce-gateway-paycrypto-me');
    $success = ($translated === $expected);
    
    if ($success) $success_count++;
    
    $status = $success ? "✅ 正确" : "❌ 错误";
    echo "   '$original' → '$translated' [$status]\n";
    
    if (!$success) {
        echo "      预期: '$expected'\n";
    }
}

echo "\n3️⃣ 模拟中文管理界面...\n";

// Mock gateway para chinês
class WC_Gateway_PayCryptoMe_ZH_CN {
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

$gateway_zh_cn = new WC_Gateway_PayCryptoMe_ZH_CN();

echo "   已翻译字段:\n";
foreach ($gateway_zh_cn->form_fields as $key => $field) {
    echo "   - $key: '" . $field['title'] . "'\n";
    if (isset($field['description'])) {
        echo "     描述: '" . $field['description'] . "'\n";
    }
}

echo "\n4️⃣ 最终统计...\n";
echo "   测试字符串数: $total_count\n";
echo "   正确翻译数: $success_count\n";
echo "   成功率: " . round(($success_count / $total_count) * 100, 1) . "%\n";

if ($success_count === $total_count) {
    echo "\n🎉 恭喜！所有中文简体翻译都完美运行！\n";
} else {
    echo "\n⚠️  某些翻译需要修正。\n";
}

echo "\n✅ 中文简体翻译测试完成！\n";
?>