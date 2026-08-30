#!/usr/bin/env bash
set -euo pipefail

# Audits translatable PHP strings against docs/GUIDE-I18N-CONVENTIONS.md.
# Usage: ./scripts/check-i18n-conventions.sh
# No Docker, dev stack or network is required.

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
log()   { echo -e "${GREEN}[OK]${NC} $*"; }
info()  { echo -e "${BLUE}[INFO]${NC} $*"; }
error() { echo -e "${RED}[FAIL]${NC} $*" >&2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TRUNK="$REPO_ROOT/src/trunk"

FINDINGS=0
finding() { error "$*"; FINDINGS=$((FINDINGS + 1)); }

mapfile -t PHP_FILES < <(
    find "$TRUNK/includes" "$TRUNK/templates" "$TRUNK/exceptions" -type f -name '*.php' -print
    find "$TRUNK" -maxdepth 1 -type f -name '*.php' -print
)

if [[ ${#PHP_FILES[@]} -eq 0 ]]; then
    error "Found no PHP source files under $TRUNK — refusing to report a clean sweep."
    exit 1
fi

echo -e "\n${BLUE}${BOLD}== i18n string-authoring conventions ==${NC}"
info "Auditing ${#PHP_FILES[@]} PHP files"

# --- 1. translated strings are not concatenated into grammatical text ---------------------------
# These lines join complete, independently-rendered button labels to closing HTML markup.
CONCAT_ALLOWLIST=(
    'id="copy-btc-admin"'
    'id="paycrypto-me-reset-derivation-index"'
    'id="paycrypto-btcpay-test"'
    'id="paycrypto-lnd-test"'
)

while IFS= read -r hit; do
    [[ -z "$hit" ]] && continue
    allowed=0
    for marker in "${CONCAT_ALLOWLIST[@]}"; do
        if [[ "$hit" == *"$marker"* ]]; then
            allowed=1
            break
        fi
    done
    (( allowed == 1 )) || finding "translated call concatenated directly: $hit"
done < <(grep -nHE "'paycrypto-me-for-woocommerce'\)[[:space:]]*\.[[:space:]]*" "${PHP_FILES[@]}" || true)

# --- 2. volatile Class-A names never appear literally inside a msgid -----------------------------
while IFS= read -r hit; do
    [[ -n "$hit" ]] && finding "raw Class-A brand token inside a translation call: $hit"
done < <(grep -nHE "(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_html_x)\([^)]*(Premium|PayCrypto\.Me|\bPro\b)" "${PHP_FILES[@]}" || true)

# --- 3. placeholder-bearing msgids have an immediately-preceding translators comment ------------
for file in "${PHP_FILES[@]}"; do
    while IFS=: read -r line_number _; do
        [[ -z "$line_number" ]] && continue
        previous_line="$(sed -n "$((line_number - 1))p" "$file")"
        [[ "$previous_line" == *'translators:'* ]] || \
            finding "$file:$line_number has a % placeholder with no preceding translators: comment"
    done < <(grep -nE "(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_html_x)\([^)]*%[0-9]?\\\$?[sd][^)]*['\"]" "$file" || true)
done

# --- verdict -------------------------------------------------------------------------------------
echo
if (( FINDINGS > 0 )); then
    error "check-i18n-conventions: $FINDINGS finding(s)."
    exit 1
fi

log "check-i18n-conventions: translated strings follow the project conventions."
