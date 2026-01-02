<?php
// Basic bootstrap for PHPUnit tests in this plugin.
// Load composer's autoloader so classes/autoloading work for tests.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Autoload not found. Run 'composer install' in the plugin directory.\n");
    exit(1);
}
require_once $autoload;

// Minimal constants for plugin classes that might expect ABSPATH
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/..');
}
