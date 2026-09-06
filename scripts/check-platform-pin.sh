#!/usr/bin/env bash
set -euo pipefail

# Audits the `config.platform.php` pin in src/trunk/composer.json.
#
# WHAT THE PIN IS TODAY
#   composer.json pins config.platform.php to the plugin's real PHP floor (8.1, the same value as
#   "Requires PHP:" in the plugin header), so Composer resolves the tree exactly as a supported host
#   would. It used to be pinned to 7.4 to smuggle in ONE package: `bitwasp/bitcoin` v1.1.0 requires
#   `lastguest/murmurhash` at the EXACT version v2.0.0, and 2.0.0 declares `php: ^7`. That pin was
#   global — it resolved the WHOLE tree as if on 7.4 — so the plugin shipped a crypto polyfill a
#   major behind plus a PHP 5 polyfill that never ran. murmurhash is now dropped via `replace`
#   instead, and the pin states the floor rather than hiding it. See docs/archive/DONE-LEAN-VENDOR-TREE.md,
#   and docs/archive/DONE-CRYPTO-DEPENDENCIES.md -> E7/E7.1/E7.2 for the history. Both are archived and
#   gitignored (see AGENTS.md's "Context and guides") — may be absent from your checkout.
#
# THE TWO REGIMES THIS SCRIPT DISTINGUISHES
#   Whether a pin is a declaration or a suppression depends entirely on how it compares to the floor:
#
#     pin >= floor  DECLARATION — the pin hides nothing. Resolution is reproducible on any machine
#                   instead of depending on the build container's PHP. Nothing in the tree may block
#                   the floor; anything that does is a real incompatibility, not a workaround.
#     pin <  floor  SUPPRESSION — the pin silences the PHP-version check for the WHOLE tree, so a
#                   dependency (direct or transitive) incompatible with the plugin's real floor
#                   enters silently. Every offender must be on ALLOWED_OFFENDERS with a documented
#                   reason and proof the plugin never executes its code.
#
#   `composer why-not --locked php <floor>` ignores the pin and lists EVERY package in the lock whose
#   php requirement excludes that floor, so it is the right probe in both regimes. The floor is read
#   from the plugin header ("Requires PHP:"), which means bumping the floor moves this check with it.
#   The probe's own success is verified before its result is believed — see the block that runs it.
#
#   In the suppression regime the script also reports the reverse, which is the case people forget:
#   when the known offender stops blocking the floor, the pin is dead weight and should be REMOVED,
#   not inherited forever.
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

# Packages allowed to require a PHP version older than the plugin's floor. Only meaningful in the
# suppression regime (pin < floor). Empty is the healthy state, and it is the state today: the one
# package that ever belonged here, `lastguest/murmurhash`, left the tree via `replace`. Every entry
# needs a reason recorded in AGENTS.md's "Composer dependencies" section (the live canonical doc —
# docs/archive/DONE-CRYPTO-DEPENDENCIES.md, which originally tracked this, is a closed/gitignored
# historical record now, not the place for a new entry) and a check that the plugin never executes
# its code — this list is not a place to park a real incompatibility.
ALLOWED_OFFENDERS=()

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

# --- Which regime are we in? --------------------------------------------------------------------
# Version-aware, never lexicographic: as strings "8.10" sorts before "8.9", which would silently
# flip the regime on a future floor bump.
version_lt() {
    [[ "$1" != "$2" && "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" == "$1" ]]
}

if version_lt "$PLATFORM_PIN" "$PHP_FLOOR"; then
    REGIME="suppression"
    warn "Regime: ${BOLD}SUPPRESSION${NC} — the pin (${PLATFORM_PIN}) is BELOW the declared floor"
    warn "(${PHP_FLOOR}), so it resolves the whole tree as if on ${PLATFORM_PIN} and can hide a real"
    warn "incompatibility. Auditing against the allowlist."
else
    REGIME="declaration"
    if [[ "$PLATFORM_PIN" == "$PHP_FLOOR" ]]; then
        info "Regime: ${BOLD}DECLARATION${NC} — the pin states the plugin's real floor, hiding nothing."
        info "It makes resolution reproducible instead of depending on the build container's PHP."
    else
        warn "Regime: ${BOLD}DECLARATION${NC} — the pin (${PLATFORM_PIN}) is ABOVE the declared floor"
        warn "(${PHP_FLOOR}). Resolution may then pick packages that the floor cannot run; the check"
        warn "below is what catches it. Align the two unless there is a recorded reason not to."
    fi
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

# `why-not` reports one line per offending package on STDOUT — "<vendor>/<name> <version> requires
# php (...)" — and exits non-zero when it finds any. Measured shapes:
#
#   clean tree     stdout empty, exit 0, two informational lines on STDERR
#   with offender  offender table on stdout, exit 1
#   BROKEN probe   stdout empty, exit non-zero (no composer.json, no Docker, unreadable lock)
#
# "Clean" and "broken" differ only in the exit status, so the earlier `2>/dev/null | grep ... ||
# true` — which discarded both the status and stderr — reported "nothing blocks the floor" for a
# probe that never ran, in a script wired into release.sh as a gate. Keep both and cross-check.
#
# `--locked` audits composer.lock (what a store installs) instead of whatever happens to be in
# vendor/ on this machine, and works on a fresh clone with no vendor/ at all. It does include
# require-dev packages (e.g. doctrine/instantiator): deliberately conservative, not a bug — a
# dev-only package that cannot run the floor is still worth a look before a release.
set +e
PROBE_OUTPUT="$(run_composer why-not --locked php "$PHP_FLOOR" --no-interaction 2>&1)"
PROBE_STATUS=$?
set -e

# Anchored on "requires php" because stderr is now merged in: Compose progress lines must never be
# mistaken for an offending package.
OFFENDER_LINES="$(printf '%s\n' "$PROBE_OUTPUT" | sed 's/\r$//' \
    | grep -E '^[a-z0-9._-]+/[a-z0-9._-]+[[:space:]]+[^[:space:]]+[[:space:]]+requires php ' || true)"

# The line Composer prints when it looked and found nothing. Its absence, with no offender lines
# either, is what tells "the tree is clean" apart from "the probe never ran".
if [[ -n "$OFFENDER_LINES" ]]; then
    : # the probe ran and found offenders — audited by regime below
elif [[ $PROBE_STATUS -eq 0 ]] \
    && printf '%s\n' "$PROBE_OUTPUT" | grep -qE 'There is no (installed|locked) package depending on'; then
    : # the probe ran and the tree is clean
else
    error "The platform-pin probe did not run — refusing to report a result it never measured."
    error ""
    error "Ran: composer why-not --locked php ${PHP_FLOOR}   (exit ${PROBE_STATUS})"
    error "Neither an offending-package line nor Composer's \"nothing to report\" line came back, so"
    error "this is a broken probe, not a clean tree. Raw output:"
    error ""
    while IFS= read -r line; do
        echo "    ${line}" >&2
    done <<< "${PROBE_OUTPUT:-<empty>}"
    error ""
    error "Usual causes: Docker unavailable (and no host 'composer'), the release image failing to"
    error "build, or src/trunk/composer.lock missing. Fix the probe and re-run — do not skip this"
    error "step: it is the only thing standing between the platform pin and an unaudited change."
    exit 1
fi

if [[ -z "$OFFENDER_LINES" ]]; then
    if [[ "$REGIME" == "declaration" ]]; then
        log "Nothing in the tree requires a PHP older than ${PHP_FLOOR}."
        log "check-platform-pin: pin is a declaration of the floor, and the tree honours it."
        exit 0
    fi

    warn "Nothing in the tree requires a PHP older than ${PHP_FLOOR} any more."
    warn "The pin (config.platform.php = ${PLATFORM_PIN}) has become dead weight: raise it to"
    warn "${PHP_FLOOR} or remove it from src/trunk/composer.json, re-run 'composer update --lock',"
    warn "and drop the workaround note from docs/archive/DONE-CRYPTO-DEPENDENCIES.md (if present) and AGENTS.md."
    exit 0
fi

echo
info "Packages requiring a PHP older than ${PHP_FLOOR}:"
while IFS= read -r line; do
    echo "    $line"
done <<< "$OFFENDER_LINES"
echo

if [[ "$REGIME" == "declaration" ]]; then
    error "The tree does not satisfy the plugin's declared PHP floor (${PHP_FLOOR})."
    error ""
    error "The pin is NOT hiding these — it states the floor (${PLATFORM_PIN}). A package above is a"
    error "real incompatibility that ships to stores, in code the plugin's own header promises runs"
    error "on PHP ${PHP_FLOOR}."
    error ""
    error "Do NOT lower the pin to make this pass: that turns an audited declaration back into the"
    error "blanket suppression this script exists to prevent. Either drop/replace the dependency, or"
    error "prove it is unreachable and record the reasoning in AGENTS.md's \"Composer dependencies\""
    error "section (the live canonical doc — docs/archive/DONE-CRYPTO-DEPENDENCIES.md is closed history)."
    exit 1
fi

UNEXPECTED=()
SEEN=()
while IFS= read -r line; do
    pkg="${line%% *}"
    SEEN+=("$pkg")

    allowed=0
    # With `set -u`, expanding an empty array aborts on bash < 4.4 — and the allowlist is empty in
    # the healthy state, so this guard is load-bearing, not defensive noise.
    if [[ ${#ALLOWED_OFFENDERS[@]} -gt 0 ]]; then
        for ok in "${ALLOWED_OFFENDERS[@]}"; do
            [[ "$pkg" == "$ok" ]] && allowed=1 && break
        done
    fi
    [[ $allowed -eq 0 ]] && UNEXPECTED+=("$pkg")
done <<< "$OFFENDER_LINES"

# An allowlisted package that no longer appears is also news: the list should shrink, never rot.
if [[ ${#ALLOWED_OFFENDERS[@]} -gt 0 ]]; then
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
fi

if [[ ${#UNEXPECTED[@]} -gt 0 ]]; then
    error "Unexpected package(s) hidden by the platform pin: ${UNEXPECTED[*]}"
    error ""
    error "The pin resolves the whole tree as if on PHP ${PLATFORM_PIN}, below the plugin's floor of"
    error "${PHP_FLOOR}. Something above is a real PHP-version incompatibility that the pin is"
    error "currently silencing, in code that ships to stores."
    error ""
    error "Do NOT widen ALLOWED_OFFENDERS to make this pass. Either the dependency is reachable"
    error "from plugin code — in which case it is a bug, not a workaround — or it is provably"
    error "unreachable and the reasoning belongs in AGENTS.md's \"Composer dependencies\" section"
    error "first (the live canonical doc — docs/archive/DONE-CRYPTO-DEPENDENCIES.md is closed history)."
    exit 1
fi

log "Only the known, documented package(s) are hidden by the pin (${ALLOWED_OFFENDERS[*]})."
log "check-platform-pin: pin is still justified and still audited."
