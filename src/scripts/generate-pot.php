<?php
/**
 * Simple POT Generator for PayCrypto.Me Plugin
 * 
 * This script manually extracts translatable strings and creates a POT file
 * Used as fallback when WP-CLI and xgettext are not available
 */

// Configuration
$plugin_dir = dirname(__DIR__);
$plugin_slug = 'woocommerce-gateway-paycrypto-me';
$text_domain = 'woocommerce-gateway-paycrypto-me';
$languages_dir = $plugin_dir . '/languages';
$pot_file = $languages_dir . '/' . $plugin_slug . '.pot';

// Ensure languages directory exists
if (!is_dir($languages_dir)) {
    mkdir($languages_dir, 0755, true);
}

// Translation function patterns
$patterns = [
    '/\b__\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]' . preg_quote($text_domain, '/') . '[\'"]/',
    '/\b_e\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]' . preg_quote($text_domain, '/') . '[\'"]/',
    '/\besc_html__\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]' . preg_quote($text_domain, '/') . '[\'"]/',
    '/\besc_attr__\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]' . preg_quote($text_domain, '/') . '[\'"]/',
    '/\besc_html_e\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]' . preg_quote($text_domain, '/') . '[\'"]/',
    '/\besc_attr_e\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]' . preg_quote($text_domain, '/') . '[\'"]/',
];

// Find all PHP files
function findPhpFiles($dir) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $path = $file->getPathname();
            // Skip vendor, node_modules, .git directories
            if (!preg_match('/\/(vendor|node_modules|\.git)\//i', $path)) {
                $files[] = $path;
            }
        }
    }
    
    return $files;
}

// Extract strings from file
function extractStrings($file, $patterns) {
    $content = file_get_contents($file);
    $strings = [];
    
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $string) {
                $strings[] = $string;
            }
        }
    }
    
    return array_unique($strings);
}

echo "🚀 Generating POT file for PayCrypto.Me Plugin...\n";
echo "Plugin Directory: $plugin_dir\n";
echo "Languages Directory: $languages_dir\n";
echo "POT File: $pot_file\n\n";

// Find all PHP files
$php_files = findPhpFiles($plugin_dir);
echo "Found " . count($php_files) . " PHP files\n";

// Extract all translatable strings
$all_strings = [];
foreach ($php_files as $file) {
    $strings = extractStrings($file, $patterns);
    if (!empty($strings)) {
        echo "  " . basename($file) . ": " . count($strings) . " strings\n";
        $all_strings = array_merge($all_strings, $strings);
    }
}

$all_strings = array_unique($all_strings);
echo "\nTotal unique strings found: " . count($all_strings) . "\n";

// Generate POT content
$pot_content = '';

// POT Header
$pot_content .= '# PayCrypto.Me for WooCommerce Translation Template' . "\n";
$pot_content .= '# This file is distributed under the same license as the PayCrypto.Me for WooCommerce package.' . "\n";
$pot_content .= 'msgid ""' . "\n";
$pot_content .= 'msgstr ""' . "\n";
$pot_content .= '"Project-Id-Version: PayCrypto.Me for WooCommerce 0.1.0\\n"' . "\n";
$pot_content .= '"Report-Msgid-Bugs-To: https://github.com/paycrypto-me/woocommerce-gateway-paycrypto-me/issues\\n"' . "\n";
$pot_content .= '"POT-Creation-Date: ' . date('Y-m-d H:i:s+0000') . '\\n"' . "\n";
$pot_content .= '"MIME-Version: 1.0\\n"' . "\n";
$pot_content .= '"Content-Type: text/plain; charset=UTF-8\\n"' . "\n";
$pot_content .= '"Content-Transfer-Encoding: 8bit\\n"' . "\n";
$pot_content .= '"Language-Team: PayCrypto.Me Team <support@paycrypto.me>\\n"' . "\n";
$pot_content .= '"X-Generator: PayCrypto.Me POT Generator\\n"' . "\n\n";

// Add strings
foreach ($all_strings as $string) {
    $pot_content .= 'msgid "' . addslashes($string) . '"' . "\n";
    $pot_content .= 'msgstr ""' . "\n\n";
}

// Write POT file
if (file_put_contents($pot_file, $pot_content)) {
    echo "✅ POT file generated successfully: $pot_file\n";
    echo "📊 File size: " . formatBytes(filesize($pot_file)) . "\n";
} else {
    echo "❌ Failed to write POT file\n";
    exit(1);
}

function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while ($size > 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, $precision) . ' ' . $units[$unit];
}

echo "\n🎯 Next steps:\n";
echo "1. Create PO files: ./scripts/build-translations.sh po pt_BR\n";
echo "2. Edit translations with PoEdit or manually\n";
echo "3. Compile MO files: ./scripts/build-translations.sh mo pt_BR\n";
echo "\n✅ Done!\n";
?>