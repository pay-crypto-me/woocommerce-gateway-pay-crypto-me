#!/usr/bin/env bash
set -euo pipefail

# Smoke test for environment-dependent fatals — the class of bug that no PHPUnit test can
# catch, because our dev image has every PHP extension installed (gmp, gd, imagick, intl,
# bcmath, sodium) while the WordPress.org reviewer's environment (WordPress Playground /
# PHP WASM) does not. This is how the GMP activation fatal was originally reproduced:
#
#   docker compose exec wordpress php -d disable_functions=gmp_init \
#     /usr/local/bin/wp eval 'WC()->payment_gateways()->payment_gateways();'
#
# This script generalizes that technique to every extension-dependent degradation path this
# plugin relies on. Each check disables one function (simulating that extension being
# unavailable) and must complete without a fatal error:
#
#   - gmp     -> On-Chain gateway hidden from checkout, Lightning keeps working (C4)
#   - gd      -> QR code degrades to none, order-details page still renders (C6)
#   - iconv   -> same (hard requirement of bacon/bacon-qr-code)
#   - fileinfo -> same (mime_content_type, used for the QR logo image)
#
# Usage: ./scripts/smoke-minimal-host.sh
# Run from the repo root, with the `wordpress` dev service up (docker compose up -d).
# Exits non-zero if any check fatals — see docs/GUIDE-RELEASE.md, this is a mandatory
# pre-release step.

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

log()   { echo -e "${GREEN}[OK]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
error() { echo -e "${RED}[FAIL]${NC} $*" >&2; }

COMPOSE_SERVICE="wordpress"
PLUGIN_DIR_IN_CONTAINER="/var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce"
SCRATCH_SUBDIR=".smoke-minimal-host-tmp"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SCRATCH_DIR="$REPO_ROOT/src/trunk/$SCRATCH_SUBDIR"

FAILURES=0

cleanup() {
    rm -rf "$SCRATCH_DIR"
}
trap cleanup EXIT

# Compose v2 ships as the `docker compose` plugin, but plenty of hosts only have the standalone
# `docker-compose` binary (also v2 nowadays).
if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker-compose)
else
    error "Neither 'docker compose' nor 'docker-compose' is available."
    exit 1
fi

if ! "${DOCKER_COMPOSE[@]}" ps --status running --services 2>/dev/null | grep -qx "$COMPOSE_SERVICE"; then
    error "The '$COMPOSE_SERVICE' service is not running. Start it first: ${DOCKER_COMPOSE[*]} up -d $COMPOSE_SERVICE"
    exit 1
fi

mkdir -p "$SCRATCH_DIR"

write_check() {
    local filename="$1"
    local php_body="$2"
    cat > "$SCRATCH_DIR/$filename" <<PHP
<?php
$php_body
PHP
}

run_check() {
    local label="$1"
    local disabled_fn="$2"
    local filename="$3"

    echo
    echo -e "${BOLD}== ${label} (disable_functions=${disabled_fn}) ==${NC}"

    if "${DOCKER_COMPOSE[@]}" exec -T "$COMPOSE_SERVICE" php -d "disable_functions=${disabled_fn}" \
        /usr/local/bin/wp eval-file "$PLUGIN_DIR_IN_CONTAINER/$SCRATCH_SUBDIR/$filename"; then
        log "$label"
    else
        error "$label produced a fatal — see output above"
        FAILURES=$((FAILURES + 1))
    fi
}

# --- 1) GMP missing: gateway listing/construction must never touch gmp_init (lazy init,
#     see BitcoinAddressService/WC_Gateway_PayCryptoMe). This is the exact reproduction of
#     the reviewer's activation fatal. Note: disable_functions blocks the *function*, not
#     the extension, so extension_loaded("gmp") (the is_available() guard, C4) still
#     reports true here — that guard can only be verified on a host that truly lacks the
#     extension (e.g. the reviewer's WordPress Playground), not simulated this way. ---
write_check "gmp.php" '
$gateways = WC()->payment_gateways()->payment_gateways();

if (!isset($gateways["paycrypto_me_lightning"], $gateways["paycrypto_me"])) {
    fwrite(STDERR, "Expected gateways missing from the registry\n");
    exit(1);
}

echo "gateways listed without touching gmp_init\n";
'
run_check "GMP missing" "gmp_init" "gmp.php"

# --- 2/3/4) GD, iconv, fileinfo missing: QR generation must degrade to an empty string
#     instead of fataling (QrCodeService::generate_native(), see C6). Runs with a logo
#     path too, so the mime-detection code path is exercised for the fileinfo case. ---
qr_check_body() {
    cat <<'PHP'
$svc = new PayCryptoMe\WooCommerce\QrCodeService();
$logo = \PayCryptoMe\WooCommerce\WC_PayCryptoMe::plugin_abspath() . 'assets/bitcoin-icon.png';
$uri = $svc->generate_qr_code_data_uri('bitcoin:bc1qexampleaddressxxxxxxxxxxxxxxxxxxxxxxx', $logo);

echo 'QR result: ' . (strlen($uri) === 0 ? 'empty (degraded gracefully)' : 'generated') . "\n";
PHP
}

write_check "gd.php" "$(qr_check_body)"
run_check "GD missing" "imagecreatetruecolor" "gd.php"

write_check "iconv.php" "$(qr_check_body)"
run_check "iconv missing" "iconv" "iconv.php"

write_check "fileinfo.php" "$(qr_check_body)"
run_check "fileinfo missing" "mime_content_type" "fileinfo.php"

# --- 5) Order-details page must still render the essentials (address, copy button) with
#     GD missing — the QR block disappears, but the page itself must never fatal (C6). ---
write_check "order-details.php" '
$order = wc_create_order();
$order->set_payment_method("paycrypto_me");
$address = "bc1qexampleaddressxxxxxxxxxxxxxxxxxxxxxxx";
$order->add_meta_data("_paycrypto_me_payment_address", $address, true);
$order->add_meta_data("_paycrypto_me_crypto_network", "mainnet", true);
$order->add_meta_data("_paycrypto_me_payment_uri", "bitcoin:{$address}", true);
$order->save();

$gateway = new PayCryptoMe\WooCommerce\WC_Gateway_PayCryptoMe();

ob_start();
$gateway->render_checkout_order_details_section($order);
$html = ob_get_clean();

$order_id = $order->get_id();
$order->delete(true);

if (strpos($html, $address) === false) {
    fwrite(STDERR, "Order #{$order_id} details page did not render the payment address\n");
    exit(1);
}

echo "order-details page rendered " . strlen($html) . " bytes, address present\n";
'
run_check "Order-details page renders without GD" "imagecreatetruecolor" "order-details.php"

echo
if [ "$FAILURES" -gt 0 ]; then
    error "smoke-minimal-host: ${FAILURES} check(s) failed"
    exit 1
fi

log "smoke-minimal-host: all checks passed"
