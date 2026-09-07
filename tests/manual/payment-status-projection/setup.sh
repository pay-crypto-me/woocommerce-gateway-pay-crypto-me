#!/usr/bin/env bash
set -euo pipefail

QA_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(cd "$QA_DIR/../../.." && pwd)"
COMPOSE=(docker compose -p pcm-browser-projection -f "$QA_DIR/docker-compose.yml")
CANDIDATE_ZIP="$ROOT_DIR/releases/paycrypto-me-for-woocommerce-0.3.0-rc.27bed50.zip"
BASELINE_ZIP="$ROOT_DIR/releases/paycrypto-me-for-woocommerce-0.2.2.zip"
HARNESS_ZIP="$ROOT_DIR/releases/qa/pcm-payment-status-qa.zip"
PRO_ZIP="$ROOT_DIR/../paycrypto-me-pro/releases/paycrypto-me-pro-0.1.0.zip"

for artifact in "$CANDIDATE_ZIP" "$BASELINE_ZIP"; do
    [[ -f "$artifact" ]] || { echo "Missing artifact: $artifact" >&2; exit 1; }
done

mkdir -p "$ROOT_DIR/releases/qa"
(
    cd "$QA_DIR"
    zip -qr "$HARNESS_ZIP" pcm-payment-status-qa
)

if [[ "${1:-}" == "--fresh" ]]; then
    "${COMPOSE[@]}" down --volumes --remove-orphans
fi

"${COMPOSE[@]}" up -d

wait_for_wordpress() {
    local service="$1"
    local deadline=$((SECONDS + 180))
    until "${COMPOSE[@]}" exec -T "$service" test -f /var/www/html/wp-includes/version.php 2>/dev/null; do
        ((SECONDS < deadline)) || { echo "$service did not become ready" >&2; exit 1; }
        sleep 3
    done
    until "${COMPOSE[@]}" exec -T "$service" php -r \
        'mysqli_report(MYSQLI_REPORT_OFF); $db = new mysqli(getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME")); exit($db->connect_errno ? 1 : 0);' \
        >/dev/null 2>&1; do
        ((SECONDS < deadline)) || { echo "$service database did not become ready" >&2; exit 1; }
        sleep 3
    done
}

wp_exec() {
    local service="$1"
    shift
    "${COMPOSE[@]}" exec -T "$service" wp --allow-root --path=/var/www/html "$@"
}

install_site() {
    local service="$1"
    local url="$2"
    local profile="$3"
    local base_zip="$4"

    wait_for_wordpress "$service"
    if ! wp_exec "$service" core is-installed >/dev/null 2>&1; then
        wp_exec "$service" core install \
            --url="$url" \
            --title="PCM Projection $profile" \
            --admin_user=admin \
            --admin_password=admin123 \
            --admin_email=admin@example.test \
            --skip-email
    fi

    if ! wp_exec "$service" plugin is-installed woocommerce >/dev/null 2>&1; then
        wp_exec "$service" plugin install woocommerce --activate
    else
        wp_exec "$service" plugin activate woocommerce
    fi

    "${COMPOSE[@]}" cp "$base_zip" "$service:/tmp/base.zip"
    wp_exec "$service" plugin install /tmp/base.zip --force --activate

    "${COMPOSE[@]}" cp "$HARNESS_ZIP" "$service:/tmp/pcm-payment-status-qa.zip"
    wp_exec "$service" plugin install /tmp/pcm-payment-status-qa.zip --force --activate
    if [[ -f "$PRO_ZIP" ]]; then
        "${COMPOSE[@]}" cp "$PRO_ZIP" "$service:/tmp/paycrypto-me-pro.zip"
        wp_exec "$service" plugin install /tmp/paycrypto-me-pro.zip --force
    fi
    wp_exec "$service" option update pcm_projection_qa_expected "$profile"
    wp_exec "$service" option update woocommerce_currency BRL
    wp_exec "$service" option update woocommerce_paycrypto_me_settings \
        '{"enabled":"yes","title":"Bitcoin On-Chain QA","description":"Pay with Bitcoin","selected_network":"mainnet","network_identifier":"1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa","crypto_currency":"BTC"}' \
        --format=json
    wp_exec "$service" option update woocommerce_paycrypto_me_lightning_settings \
        '{"enabled":"yes","title":"Bitcoin Lightning QA","description":"Pay a fixture Lightning invoice","node_type":"btcpay","invoice_expiry":"3600","btcpay_url":"https://qa-btcpay.invalid","btcpay_api_key":"qa_api_key_12345678901234567890","btcpay_store_id":"qa-store","btcpay_payment_method_id":"BTC-LN"}' \
        --format=json

    if [[ "$(wp_exec "$service" post list --post_type=product --name=qa-bitcoin-product --field=ID | head -n 1)" == "" ]]; then
        local product_id
        product_id="$(wp_exec "$service" post create \
            --post_type=product \
            --post_status=publish \
            --post_title='QA Bitcoin Product' \
            --post_name=qa-bitcoin-product \
            --porcelain)"
        wp_exec "$service" post meta update "$product_id" _regular_price 100
        wp_exec "$service" post meta update "$product_id" _price 100
        wp_exec "$service" post meta update "$product_id" _virtual yes
    fi

    wp_exec "$service" config set WP_DEBUG true --raw
    wp_exec "$service" config set WP_DEBUG_LOG true --raw
    wp_exec "$service" config set WP_DEBUG_DISPLAY false --raw
    wp_exec "$service" rewrite structure '/%postname%/' --hard
    wp_exec "$service" rewrite flush --hard
}

install_site candidate_wordpress http://localhost:8092 candidate "$CANDIDATE_ZIP"
install_site baseline_wordpress http://localhost:8093 baseline "$BASELINE_ZIP"

echo
echo 'Candidate: http://localhost:8092/wp-admin/tools.php?page=pcm-payment-status-qa'
echo 'Baseline:  http://localhost:8093/wp-admin/tools.php?page=pcm-payment-status-qa'
echo 'Login: admin / admin123'
