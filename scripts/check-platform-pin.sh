#!/usr/bin/env bash
set -euo pipefail

# Audits the `config.platform.php` pin in src/trunk/composer.json.
#
# WHY THE PIN EXISTS
#   composer.json pins config.platform.php to 7.4, so Composer resolves the tree as if it were
#   running on PHP 7.4. It is needed for exactly ONE package: `bitwasp/bitcoin` v1.1.0 requires
#   `lastguest/murmurhash` at the EXACT version v2.0.0, and 2.0.0 declares `php: ^7`. Without the
#   pin, an honest resolution on PHP 8 refuses to install. murmurhash is only reachable from
#   `Crypto/Hash.php::murmur3()` (called from `Bloom/BloomFilter.php`), and the plugin references
#   neither — it is installed and never executed. See docs/CRYPTO-DEPENDENCIES.md -> E7.
#
# WHAT THE PIN COSTS, AND WHAT THIS SCRIPT BUYS BACK
#   The pin is global and permanent: it silences the PHP-version check for the WHOLE tree, so a
#   future dependency (direct or transitive) incompatible with the plugin's real PHP floor would
#   enter silently — the one thing Composer would otherwise have caught for free.
#
#   `composer why-not php <floor>` ignores the pin and lists EVERY package whose php requirement
#   excludes that floor. This script runs it against the floor declared in the plugin header
#   ("Requires PHP:"), and fails if anything shows up beyond the single package we knowingly accept.
#   A blanket suppression becomes an audited one.
#
#   It also reports the reverse, which is the point people forget: when the known offender stops
#   blocking the floor, the pin is dead weight and should be REMOVED, not inherited forever.
#   murmurhash 2.1.1 already declares `php: ^7||^8.0` — the blocker is upstream's exact-version
#   pin, not the package, so the day that pin is loosened this script says so.
#
# Usage: ./scripts/check-platform-pin.sh
# Run from anywhere. Needs either Docker (uses the ephemeral `release` service — the dev stack does
# NOT need to be up) or a host `composer`. Exits non-zero on an unexpected offender.

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

log()   { echo -e "${GREEN}[OK]${NC} $*"; }
info()  { echo -e "${BLUE}[INFO]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
error() { echo -e "${RED}[FAIL]${NC} $*" >&2; }

# Packages allowed to require a PHP version older than the plugin's floor. Every entry needs a
# reason in docs/CRYPTO-DEPENDENCIES.md and a check that the plugin never executes its code —
# this list is not a place to park a real incompatibility.
ALLOWED_OFFENDERS=("lastguest/murmurhash")

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TRUNK="$REPO_ROOT/src/trunk"
RELEASE_SERVICE="release"

# --- PHP floor: read from the plugin header, so bumping it moves this check with it -------------
PHP_FLOOR="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Requires PHP:[[:space:]]*\([0-9.]*\).*/\1/p' \
    "$TRUNK/paycrypto-me-for-woocommerce.php" | head -1)"

if [[ -z "$PHP_FLOOR" ]]; then
    error "Could not read 'Requires PHP:' from the plugin header — refusing to guess a floor."
    exit 1
fi

# --- The pin itself, for the record -------------------------------------------------------------
PLATFORM_PIN="$(php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    echo $j["config"]["platform"]["php"] ?? "";
' "$TRUNK/composer.json" 2>/dev/null || true)"

if [[ -z "$PLATFORM_PIN" ]]; then
    # No host PHP (the usual case here) — fall back to a grep that does not need a parser.
    PLATFORM_PIN="$(sed -n '/"platform"/,/}/s/.*"php"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
        "$TRUNK/composer.json" | head -1)"
fi

echo -e "\n${BLUE}${BOLD}== config.platform.php audit ==${NC}"
info "Plugin PHP floor (plugin header): ${BOLD}${PHP_FLOOR}${NC}"
info "config.platform.php in composer.json: ${BOLD}${PLATFORM_PIN:-<absent>}${NC}"

if [[ -z "$PLATFORM_PIN" ]]; then
    log "No platform pin in composer.json — nothing to audit."
    exit 0
fi

# --- How to run composer -----------------------------------------------------------------------
# Compose v2 ships as the `docker compose` plugin, but plenty of hosts only have the standalone
# `docker-compose` binary (also v2 nowadays).
run_composer() {
    if docker compose version >/dev/null 2>&1; then
        (cd "$REPO_ROOT" && docker compose run --rm "$RELEASE_SERVICE" composer "$@")
    elif command -v docker-compose >/dev/null 2>&1; then
        (cd "$REPO_ROOT" && docker-compose run --rm "$RELEASE_SERVICE" composer "$@")
    elif command -v composer >/dev/null 2>&1; then
        (cd "$TRUNK" && composer "$@")
    else
        error "Neither Docker Compose nor a host 'composer' is available."
        exit 1
    fi
}

# `why-not` reports one line per offending package: "<vendor>/<name> <version> requires php (...)".
# Empty output means nothing in the tree blocks the floor.
OFFENDER_LINES="$(run_composer why-not php "$PHP_FLOOR" --no-interaction 2>/dev/null \
    | sed 's/\r$//' | grep -E '^[a-z0-9._-]+/[a-z0-9._-]+ ' || true)"

if [[ -z "$OFFENDER_LINES" ]]; then
    warn "Nothing in the tree requires a PHP older than ${PHP_FLOOR} any more."
    warn "The pin (config.platform.php = ${PLATFORM_PIN}) has become dead weight: remove it from"
    warn "src/trunk/composer.json, re-run 'composer update --lock', and drop the E7 workaround note"
    warn "from docs/CRYPTO-DEPENDENCIES.md and the Composer section of CLAUDE.md."
    exit 0
fi

echo
info "Packages requiring a PHP older than ${PHP_FLOOR} (the pin hides these from resolution):"
while IFS= read -r line; do
    echo "    $line"
done <<< "$OFFENDER_LINES"
echo

UNEXPECTED=()
SEEN=()
while IFS= read -r line; do
    pkg="${line%% *}"
    SEEN+=("$pkg")

    allowed=0
    for ok in "${ALLOWED_OFFENDERS[@]}"; do
        [[ "$pkg" == "$ok" ]] && allowed=1 && break
    done
    [[ $allowed -eq 0 ]] && UNEXPECTED+=("$pkg")
done <<< "$OFFENDER_LINES"

# An allowlisted package that no longer appears is also news: the list should shrink, never rot.
for ok in "${ALLOWED_OFFENDERS[@]}"; do
    found=0
    for pkg in "${SEEN[@]}"; do
        [[ "$pkg" == "$ok" ]] && found=1 && break
    done
    if [[ $found -eq 0 ]]; then
        warn "'${ok}' is allowlisted but no longer blocks PHP ${PHP_FLOOR} — drop it from"
        warn "ALLOWED_OFFENDERS in this script, and check whether the pin is still needed at all."
    fi
done

if [[ ${#UNEXPECTED[@]} -gt 0 ]]; then
    error "Unexpected package(s) hidden by the platform pin: ${UNEXPECTED[*]}"
    error ""
    error "The pin exists for ${ALLOWED_OFFENDERS[*]} only (installed, never executed — see"
    error "docs/CRYPTO-DEPENDENCIES.md -> E7). Something above is a real PHP-version"
    error "incompatibility that the pin is currently silencing, in code that ships to stores."
    error ""
    error "Do NOT widen ALLOWED_OFFENDERS to make this pass. Either the dependency is reachable"
    error "from plugin code — in which case it is a bug, not a workaround — or it is provably"
    error "unreachable and the reasoning belongs in docs/CRYPTO-DEPENDENCIES.md first."
    exit 1
fi

log "Only the known, documented package is hidden by the pin (${ALLOWED_OFFENDERS[*]})."
log "check-platform-pin: pin is still justified and still audited."
