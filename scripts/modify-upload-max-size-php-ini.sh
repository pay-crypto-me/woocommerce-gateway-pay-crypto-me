#!/usr/bin/env bash
set -euo pipefail

# Compose v2 ships as the `docker compose` plugin, but plenty of hosts only have the standalone
# `docker-compose` binary (also v2 nowadays). Resolved once, same as the other scripts here.
if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    DOCKER_COMPOSE=(docker-compose)
else
    echo "Neither 'docker compose' nor 'docker-compose' is available." >&2
    exit 1
fi

echo "Modifying upload max size in php.ini inside the WordPress Docker container..."
"${DOCKER_COMPOSE[@]}" exec --user root wordpress bash -lc "cat > /usr/local/etc/php/conf.d/uploads.ini <<'EOF'
upload_max_filesize=5M
post_max_size=8M
memory_limit=256M
EOF
if command -v service >/dev/null 2>&1; then service php8.3-fpm restart 2>/dev/null || service php8.1-fpm restart 2>/dev/null || service php-fpm restart 2>/dev/null || true; elif command -v systemctl >/dev/null 2>&1; then systemctl restart php8.3-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true; fi
php -r 'echo "upload_max_filesize=".ini_get("upload_max_filesize")."\n"; echo "post_max_size=".ini_get("post_max_size")."\n"; echo "memory_limit=".ini_get("memory_limit")."\n";'"

"${DOCKER_COMPOSE[@]}" stop wordpress

"${DOCKER_COMPOSE[@]}" start wordpress

echo "Verifying the changes made to php.ini..."
"${DOCKER_COMPOSE[@]}" exec --user root wordpress bash -lc "echo '--- uploads.ini ---'; cat /usr/local/etc/php/conf.d/uploads.ini || true; echo '--- php -i grep ---'; php -i | egrep -i 'upload_max_filesize|post_max_size|memory_limit' || true"
