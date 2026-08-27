#!/usr/bin/env bash
set -euo pipefail

# Audits the canonical docs (CLAUDE.md + docs/*.md) against the tree they describe.
#
# WHY THIS EXISTS
#   The docs here are load-bearing: an agent reads them before the code, and the repo deliberately
#   keeps long-lived records instead of deleting them. Records rot silently — a renamed path, a
#   `file.php:NNN` that now lands on a blank line after an edit above it, a hooks table that lost an
#   entry. None of that fails anything, which is why it survives. This checks the mechanically
#   checkable part; whether a paragraph is still *true* stays a human judgement.
#
# WHY NOT A PHPUNIT TEST
#   The unit suite runs with `src/trunk` as its world (that is all the dev container mounts, and all
#   the release build copies). CLAUDE.md and docs/ live above it, so a test would skip exactly where
#   the suite normally runs — a guard that never fires. Here the repo root exists by construction.
#
# Usage: ./scripts/check-docs-drift.sh
# No Docker, no dev stack, no network. Exits non-zero on any finding.

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
log()   { echo -e "${GREEN}[OK]${NC} $*"; }
info()  { echo -e "${BLUE}[INFO]${NC} $*"; }
error() { echo -e "${RED}[FAIL]${NC} $*" >&2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TRUNK="$REPO_ROOT/src/trunk"

# Paths cited by an approved-but-not-started plan: they will exist once it runs. Listed here instead
# of loosening the check, so "planned" stays distinguishable from "rotted".
PLANNED_PATHS=(
    "scripts/schema-tests.sh"
    "src/trunk/phpunit-integration.xml.dist"
    "src/trunk/tests/integration/bootstrap.php"
    "tests/bin/dump-schema.php"
    "scripts/check-i18n-conventions.sh"
)
# WordPress core, not ours: the docs cite wp-includes/functions.php and
# wp-admin/includes/upgrade.php (the file dbDelta lives in) by their tail.
# docs/PREMIUM-ADDON.md moved to the paycrypto-me-pro repo on 2026-08-25 — genuinely external
# now, not planned. CLAUDE.md links it by full GitHub URL (matches the substring), and
# CRYPTO-DEPENDENCIES-AUDIT.md's mention is a historical record of when it still lived here; neither
# should be "fixed" by resurrecting the file.
EXTERNAL_PATHS=("includes/functions.php" "includes/upgrade.php" "docs/PREMIUM-ADDON.md")

FINDINGS=0
finding() { error "$*"; FINDINGS=$((FINDINGS + 1)); }

is_listed() {
    local needle="$1"; shift
    local item
    for item in "$@"; do
        [[ "$needle" == "$item" ]] && return 0
    done
    return 1
}

echo -e "\n${BLUE}${BOLD}== canonical docs vs codebase ==${NC}"

mapfile -t DOCS < <(printf '%s\n' "$REPO_ROOT/CLAUDE.md" "$REPO_ROOT"/docs/*.md | sort -u)

# A sweep that silently finds no docs would pass every check below vacuously.
if [[ ${#DOCS[@]} -lt 2 || ! -f "$REPO_ROOT/CLAUDE.md" ]]; then
    error "Found no canonical docs to audit under $REPO_ROOT — refusing to report a clean sweep."
    exit 1
fi
info "Auditing ${#DOCS[@]} documents"

# --- 1. every cited path exists ------------------------------------------------------------------
for doc in "${DOCS[@]}"; do
    while IFS= read -r cited; do
        [[ -z "$cited" ]] && continue
        is_listed "$cited" "${PLANNED_PATHS[@]}" && continue
        is_listed "$cited" "${EXTERNAL_PATHS[@]}" && continue

        if [[ -e "$REPO_ROOT/$cited" || -e "$TRUNK/$cited" || -e "$REPO_ROOT/docs/$cited" ]]; then
            continue
        fi

        finding "$(basename "$doc") cites a path that does not exist: $cited"
    done < <(grep -oE '(src/trunk/|docs/|scripts/|includes/|templates/|tests/|assets/|exceptions/)[A-Za-z0-9_./-]*\.(json|php|md|sh|jsx?|xml|dist|txt|css|png|pot?|mo|yml)' "$doc" | sort -u)
done

# --- 2. every `file.php:NNN` still lands on code -------------------------------------------------
# Line numbers rot on any edit above them; the house rule is to name a symbol instead and keep a
# number only for vendor code, which composer.lock pins. Vendor refs are therefore skipped.
resolve_source() {
    local cited="$1" candidate found
    for candidate in "$REPO_ROOT/$cited" "$TRUNK/$cited" "$TRUNK/includes/$cited"; do
        [[ -f "$candidate" ]] && { echo "$candidate"; return 0; }
    done
    found="$(find "$TRUNK/includes" "$TRUNK/templates" -name "$(basename "$cited")" -type f 2>/dev/null)"
    [[ "$(printf '%s\n' "$found" | grep -c .)" == "1" ]] && { echo "$found"; return 0; }
    return 1
}

for doc in "${DOCS[@]}"; do
    while IFS= read -r ref; do
        [[ -z "$ref" ]] && continue
        cited="${ref%:*}"
        line="${ref##*:}"
        [[ "$cited" == *"/vendor/"* ]] && continue

        source_file="$(resolve_source "$cited" || true)"
        [[ -z "$source_file" ]] && continue
        [[ "$source_file" == *"/vendor/"* ]] && continue

        total="$(grep -c '' "$source_file")"
        if (( line > total )); then
            finding "$(basename "$doc") cites $ref but the file has $total lines"
            continue
        fi

        target="$(sed -n "${line}p" "$source_file" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
        case "$target" in
            ""|"}"|"{"|"*/"|"/*"|"?>")
                finding "$(basename "$doc") cites $ref, which lands on '${target}' — name the symbol instead"
                ;;
        esac
    done < <(grep -oE '[A-Za-z0-9_./-]+\.php:[0-9]{1,4}' "$doc" | sort -u)
done

# --- 3. the hooks table matches the code ---------------------------------------------------------
# It is the contract the Pro add-on is built against: a hook missing from it is a seam nobody
# knows exists, one listed but absent is a promise the add-on cannot keep.
HOOKS_IN_CODE="$(grep -rhoE "'paycryptome_[a-z_]+'" "$TRUNK/includes" "$TRUNK/templates" | tr -d "'" | sort -u)"
HOOKS_IN_DOC="$(grep -oE 'paycryptome_[a-z_]+' "$REPO_ROOT/CLAUDE.md" | sort -u)"

if [[ -z "$HOOKS_IN_CODE" ]]; then
    finding "found no paycryptome_* hooks in the code at all — the scan is broken, not the docs"
fi

while IFS= read -r hook; do
    [[ -n "$hook" ]] && finding "hook '$hook' is fired in code but missing from the CLAUDE.md hooks table"
done < <(comm -23 <(printf '%s\n' "$HOOKS_IN_CODE") <(printf '%s\n' "$HOOKS_IN_DOC"))

while IFS= read -r hook; do
    [[ -n "$hook" ]] && finding "hook '$hook' is in the CLAUDE.md hooks table but fired nowhere in the code"
done < <(comm -13 <(printf '%s\n' "$HOOKS_IN_CODE") <(printf '%s\n' "$HOOKS_IN_DOC"))

# --- 4. counts the docs state in prose -----------------------------------------------------------
check_count() {
    local what="$1" documented="$2" actual="$3"
    if [[ "$documented" != "$actual" ]]; then
        finding "docs say $documented $what, the code has $actual — fix every doc that repeats the number"
    fi
}

LIGHTNING="$TRUNK/includes/class-wc-gateway-paycrypto-me-lightning.php"
check_count "translation locales"          7 "$(find "$TRUNK/languages" -name '*.po' | grep -c .)"
check_count "Lightning validate_*_field"   9 "$(grep -c 'function validate_.*_field' "$LIGHTNING")"
check_count "Lightning generate_*_html"    3 "$(grep -c 'function generate_.*_html' "$LIGHTNING")"
# Counts statements, not distinct captured names: two activators build their SQL with the same
# `$table_name` variable, so deduplicating names silently loses a table. The leading quote keeps the
# comment that quotes dbDelta's own regex out of the count.
check_count "custom database tables"       4 "$(grep -rhoF '"CREATE TABLE ' "$TRUNK/includes" | grep -c .)"
check_count "address vectors"             60 "$(grep -oE '"[a-zA-Z0-9]{26,62}"' "$TRUNK/tests/vectors/bitcoin_addresses.json" | grep -cE '"(1|3|bc1|m|n|2|tb1)')"

# --- verdict -------------------------------------------------------------------------------------
echo
if (( FINDINGS > 0 )); then
    error "check-docs-drift: $FINDINGS finding(s). The docs are read before the code here — a stale"
    error "record is worse than no record, because it is trusted."
    exit 1
fi

log "check-docs-drift: paths, line references, the hooks table and the stated counts all match the tree."
