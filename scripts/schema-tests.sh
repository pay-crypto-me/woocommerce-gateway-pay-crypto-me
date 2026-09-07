#!/usr/bin/env bash
set -euo pipefail

# Database integration suite — the only tests in this repo that see a real wpdb/dbDelta() against
# a real MySQL. The historical script name is retained as a stable release command.
#
# The unit suite (./vendor/bin/phpunit) shims wpdb away and ActivateDbDeltaTest defines its own
# fake dbDelta(), which is what keeps it fast and WordPress-free. The price is that no unit test can
# observe what dbDelta actually does — and dbDelta silently ignores a whole class of declarations:
# it never applies a NOT NULL -> NULL change, never removes a column or index, and parses line by
# line, so a second column declared on the same line is dropped without a word. Every one of those
# is a change that passes CI-less review, works on a fresh install, and does nothing at all on the
# sites already published on WordPress.org.
#
# The suite installs each frozen schema and proves convergence, and also exercises concurrency
# contracts that unit-test wpdb fakes cannot represent faithfully.
#
# Usage: ./scripts/schema-tests.sh [extra phpunit args]
# Run from the repo root, with the `wordpress` dev service up (docker compose up -d wordpress wp_db).
# Mandatory before cutting a release — see docs/GUIDE-RELEASE.md.

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

log()   { echo -e "${GREEN}[OK]${NC} $*"; }
error() { echo -e "${RED}[FAIL]${NC} $*" >&2; }

COMPOSE_SERVICE="wordpress"
PLUGIN_DIR_IN_CONTAINER="/var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce"

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
    error "The '$COMPOSE_SERVICE' service is not running. Start it first: ${DOCKER_COMPOSE[*]} up -d $COMPOSE_SERVICE wp_db"
    exit 1
fi

if ! "${DOCKER_COMPOSE[@]}" exec -T "$COMPOSE_SERVICE" test -x "$PLUGIN_DIR_IN_CONTAINER/vendor/bin/phpunit"; then
    error "vendor/bin/phpunit is missing. Run: ${DOCKER_COMPOSE[*]} run --rm release composer install"
    exit 1
fi

echo "== Database integration suite (real WordPress, real MySQL, real wpdb/dbDelta) =="

if "${DOCKER_COMPOSE[@]}" exec -T -w "$PLUGIN_DIR_IN_CONTAINER" "$COMPOSE_SERVICE" \
    ./vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"; then
    log "schema-tests: all checks passed"
else
    error "schema-tests: the schema suite failed — see output above"
    exit 1
fi
