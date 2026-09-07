<?php
/**
 * Bootstrap for the database integration suite.
 *
 * The unit suite (tests/bootstrap.php) shims WordPress away so it runs in ~5s with no database,
 * and that is deliberate — but it means no unit test can observe what dbDelta() actually does.
 * ActivateDbDeltaTest even defines its own fake dbDelta(). That blind spot is how a design based on
 * "declare the columns nullable and bump DB_VERSION" nearly shipped: dbDelta() does NOT apply
 * NOT NULL -> NULL, silently, with an empty $wpdb->last_error. It would have passed every unit
 * test, worked on fresh installs, and done nothing on the sites already published.
 *
 * So this suite loads the REAL WordPress from the dev container and talks to the real MySQL.
 * It is opt-in (scripts/schema-tests.sh), never part of the fast feedback loop.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The database integration suite is CLI-only.\n");
    exit(1);
}

// wp-load.php would otherwise pull in the active theme's functions.php for no benefit here.
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}

$wp_load = getenv('PAYCRYPTO_WP_LOAD') ?: '/var/www/html/wp-load.php';

if (!file_exists($wp_load)) {
    fwrite(
        STDERR,
        "WordPress not found at {$wp_load}.\n" .
        "This suite runs inside the `wordpress` dev container — use ./scripts/schema-tests.sh,\n" .
        "or point PAYCRYPTO_WP_LOAD at a wp-load.php.\n"
    );
    exit(1);
}

require $wp_load;

// dbDelta() lives here and is not loaded on a normal request. The activators require it too; doing
// it up front means a test can call dbDelta() directly to set up a "wrong" schema.
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// Safety net: the plugin's classes come from its own Composer classmap. If the plugin happens to be
// deactivated in this container, WordPress will not have loaded it, and the tests would fail with a
// class-not-found error that says nothing about the real cause. Required AFTER wp-load, because
// every plugin class starts with `\defined('ABSPATH') || exit;`.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

require_once __DIR__ . '/SchemaTestCase.php';
