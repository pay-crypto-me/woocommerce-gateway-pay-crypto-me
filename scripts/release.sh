#!/usr/bin/env bash
set -euo pipefail

# Release helper for PayCrypto.Me plugin
# Usage: ./scripts/release.sh -v VERSION -s SLUG [--no-build] [--no-tests] [--no-zip] [--git] [--svn] [--no-docker] [--dry-run]

# === COLOR OUTPUT ===
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log()    { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()   { echo -e "${YELLOW}[WARN]${NC} $*"; }
error()  { echo -e "${RED}[ERROR]${NC} $*" >&2; }
header() { echo -e "\n${BLUE}${BOLD}=== $* ===${NC}"; }
step()   { echo -e "${CYAN}  →${NC} $*"; }

# === DOCKER CONFIGURATION ===
# Build runs in the dedicated, ephemeral `release` compose service (profile-gated,
# no MySQL, no long-lived stack). It shares the image and the ./src/trunk bind mount
# with the dev `wordpress` service, so the dev stack does not need to be up.
RELEASE_SERVICE="release"

# Run a command in a throwaway `release` container (working dir /plugin = src/trunk).
docker_exec() {
    docker compose run --rm "$RELEASE_SERVICE" bash -c "$1"
}

# === HELP ===
show_help() {
  cat <<EOF
Usage: $0 -v VERSION -s SLUG [options]

Required:
  -v VERSION      Release version (e.g. 1.2.0)
  -s SLUG         Plugin slug / folder name

Options:
  --no-build      Skip npm build
  --no-tests      Skip phpunit tests
  --no-zip        Skip creating the zip
  --git           Commit version bumps and create git tag
  --svn           Prepare SVN trunk/tags (requires SVN credentials)
  --no-docker     Run build/test commands on host instead of Docker container
  --dry-run       Print steps without executing them
  -h|--help       Show this help
EOF
}

# === ARGUMENT PARSING ===
if [[ ${#@} -eq 0 ]]; then
  show_help
  exit 1
fi

VERSION=""
SLUG=""
DO_BUILD=1
DO_TESTS=1
DO_ZIP=1
DO_GIT=0
DO_SVN=0
USE_DOCKER=1
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    -v)          VERSION="$2"; shift 2;;
    -s)          SLUG="$2"; shift 2;;
    --no-build)  DO_BUILD=0; shift;;
    --no-tests)  DO_TESTS=0; shift;;
    --no-zip)    DO_ZIP=0; shift;;
    --git)       DO_GIT=1; shift;;
    --svn)       DO_SVN=1; shift;;
    --no-docker) USE_DOCKER=0; shift;;
    --dry-run)   DRY_RUN=1; shift;;
    -h|--help)   show_help; exit 0;;
    *) error "Unknown option: $1"; show_help; exit 1;;
  esac
done

if [[ -z "$VERSION" || -z "$SLUG" ]]; then
  error "VERSION and SLUG are required."
  show_help
  exit 1
fi

# === SEMVER VALIDATION ===
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  error "VERSION must be a valid semver string (e.g. 1.2.3). Got: $VERSION"
  exit 1
fi

# === DRY RUN WRAPPER ===
# Wraps commands so they are printed but not executed in --dry-run mode.
run() {
  if [[ $DRY_RUN -eq 1 ]]; then
    step "[dry-run] $*"
  else
    "$@"
  fi
}

run_shell() {
  local desc="$1"; shift
  if [[ $DRY_RUN -eq 1 ]]; then
    step "[dry-run] $desc"
  else
    eval "$desc"
  fi
}

# === PATH SETUP ===
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if [[ -d "$ROOT_DIR/src/trunk" ]]; then
  TRUNK="$ROOT_DIR/src/trunk"
elif [[ -d "$ROOT_DIR/source/trunk" ]]; then
  TRUNK="$ROOT_DIR/source/trunk"
else
  TRUNK="$ROOT_DIR/source/trunk"
fi

PLUGIN_FILE="$TRUNK/paycrypto-me-for-woocommerce.php"
README_FILE="$TRUNK/readme.txt"

log "Trunk: $TRUNK"
log "Preparing release ${BOLD}$SLUG v$VERSION${NC}"
[[ $DRY_RUN -eq 1 ]] && warn "Dry-run mode active — no changes will be made."

# === PRE-FLIGHT CHECKS ===
header "Pre-flight checks"

# Check git working tree
if git -C "$ROOT_DIR" diff --quiet HEAD 2>/dev/null; then
  log "Git working tree is clean."
else
  warn "Uncommitted changes detected. Proceeding anyway, but consider committing first."
fi

# Check Docker — the `release` service is ephemeral (built/run on demand), so the dev
# stack does NOT need to be up; we only need the Docker CLI + Compose available.
if [[ $USE_DOCKER -eq 1 ]]; then
  if docker compose version >/dev/null 2>&1; then
    log "Build/tests will run in the ephemeral '$RELEASE_SERVICE' service (dev stack not required)."
    # Forward the repo-root auth.json to Composer inside the container (avoids GitHub
    # rate limits when resolving the private bitcoin fork). Optional — safe if absent.
    if [[ -f "$ROOT_DIR/auth.json" ]]; then
      export COMPOSER_AUTH="$(cat "$ROOT_DIR/auth.json")"
      log "Forwarding auth.json to Composer via COMPOSER_AUTH."
    fi
  else
    error "Docker Compose is not available. Install Docker (with Compose), or pass --no-docker"
    error "to run build/tests on the host (requires local Node.js, PHP, Composer)."
    exit 1
  fi
fi

# === BUILD ===
if [[ $DO_BUILD -eq 1 ]]; then
  header "npm build"
  if [[ -f "$TRUNK/package.json" ]]; then
    if [[ $USE_DOCKER -eq 1 ]]; then
      log "Running npm ci && npm run build inside Docker container..."
      if [[ $DRY_RUN -eq 0 ]]; then
        docker_exec "npm ci && npm run build"
      else
        step "[dry-run] docker_exec: npm ci && npm run build"
      fi
    else
      log "Running npm ci && npm run build on host..."
      run bash -c "cd '$TRUNK' && npm ci && npm run build"
    fi
  else
    warn "No package.json in $TRUNK — skipping build."
  fi
fi

# === TESTS ===
# The test vendor (phpunit) is provisioned manually and nothing else heals it,
# so a prior `composer install --no-dev` in the source tree would otherwise make
# this step abort with "vendor/bin/phpunit: No such file or directory". Restore
# dev dependencies first so the run is self-sufficient regardless of vendor state.
if [[ $DO_TESTS -eq 1 ]]; then
  header "PHPUnit"
  if [[ $USE_DOCKER -eq 1 ]]; then
    log "Ensuring dev dependencies, then running PHPUnit inside Docker container..."
    if [[ $DRY_RUN -eq 0 ]]; then
      docker_exec "composer install --no-interaction && ./vendor/bin/phpunit --configuration phpunit.xml.dist"
    else
      step "[dry-run] docker_exec: composer install --no-interaction && ./vendor/bin/phpunit --configuration phpunit.xml.dist"
    fi
  else
    if [[ ! -x "$TRUNK/vendor/bin/phpunit" && $DRY_RUN -eq 0 ]]; then
      if command -v composer &>/dev/null; then
        log "phpunit missing on host — restoring dev dependencies via composer install..."
        (cd "$TRUNK" && composer install --no-interaction)
      else
        error "phpunit not found in $TRUNK/vendor/bin and composer is unavailable on host."
        error "Install dev dependencies (composer install) or drop --no-docker to use the container."
        exit 1
      fi
    fi
    log "Running PHPUnit on host..."
    run bash -c "cd '$TRUNK' && ./vendor/bin/phpunit --configuration phpunit.xml.dist"
  fi
fi

# === VERSION BUMPS ===
header "Version bumps → $VERSION"

bump_sed() {
  local file="$1" pattern="$2" label="$3"
  if [[ -f "$file" ]]; then
    log "Updating $label in $file"
    if [[ $DRY_RUN -eq 0 ]]; then
      sed -E -i.bak "$pattern" "$file" || true
      rm -f "$file.bak"
    else
      step "[dry-run] sed: $label → $VERSION"
    fi
  fi
}

# Plugin header: " * Version: X.Y.Z"
bump_sed "$PLUGIN_FILE" \
  "s/^(\\s*\\*\\s*Version:[[:space:]]*).*/\\1$VERSION/" \
  "Version: header"

# PHP class constant: public const string VERSION = 'X.Y.Z';
bump_sed "$PLUGIN_FILE" \
  "s/^(\\s*public\\s+const\\s+string\\s+VERSION\\s*=\\s*')[^']+(';)/\\1$VERSION\\2/" \
  "VERSION class constant"

# readme.txt: Stable tag
bump_sed "$README_FILE" \
  "s/^(Stable tag:[[:space:]]*).*/\\1$VERSION/" \
  "Stable tag"

# composer.json and package.json: "version": "X.Y.Z"
for f in "$TRUNK/composer.json" "$TRUNK/package.json"; do
  if [[ -f "$f" ]]; then
    log "Updating version in $(basename "$f")"
    if [[ $DRY_RUN -eq 0 ]]; then
      sed -E -i.bak 's/^([[:space:]]*"version"[[:space:]]*:[[:space:]]*")[^"]+("[[:space:]]*,?)/\1'"$VERSION"'\2/' "$f" || true
      rm -f "$f.bak"
    else
      step "[dry-run] sed: version → $VERSION in $(basename "$f")"
    fi
  fi
done

# === BUILD DIR ===
header "Creating release build"

BUILD_DIR=$(mktemp -d -t "${SLUG}-release-XXXX")
# Cleanup build dir on exit (success or failure)
trap 'log "Cleaning up build dir $BUILD_DIR"; rm -rf "$BUILD_DIR"' EXIT

log "Build dir: $BUILD_DIR"

log "Syncing files (excluding vendor, node_modules, dev files)..."
if [[ $DRY_RUN -eq 0 ]]; then
  rsync -a --delete \
    --exclude='vendor/' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='.git' \
    --exclude='.phpunit.result.cache' \
    --exclude='phpunit.xml.dist' \
    --exclude='*~' \
    --exclude='*.po~' \
    --exclude='*.map' \
    --exclude='webpack.config.js' \
    --exclude='package-lock.json' \
    --exclude='/includes/blocks/js/' \
    --exclude='/includes/blocks/scss/' \
    "$TRUNK/" "$BUILD_DIR/$SLUG/"
else
  step "[dry-run] rsync $TRUNK/ → $BUILD_DIR/$SLUG/ (without vendor/)"
fi

# === COMPOSER PRODUCTION INSTALL ===
header "Composer production install"
log "Running: composer install --no-dev --optimize-autoloader --prefer-dist"

if [[ $DRY_RUN -eq 0 ]]; then
  if [[ $USE_DOCKER -eq 1 ]]; then
    docker compose run --rm \
      -v "$BUILD_DIR/$SLUG:/release-build" \
      -w /release-build \
      "$RELEASE_SERVICE" bash -c \
      "composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction 2>&1"
  else
    if command -v composer &>/dev/null; then
      (cd "$BUILD_DIR/$SLUG" && composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction)
    else
      error "composer not found on host and --no-docker was set. Cannot install production dependencies."
      exit 1
    fi
  fi
else
  step "[dry-run] docker compose run: composer install --no-dev --optimize-autoloader in build dir"
fi

# === VENDOR CLEANUP (residual files) ===
if [[ -d "$BUILD_DIR/$SLUG/vendor" && $DRY_RUN -eq 0 ]]; then
  header "Vendor cleanup"
  log "Removing VCS metadata, tests, heavy assets..."

  find "$BUILD_DIR/$SLUG/vendor" -type d -name '.git' -prune -exec rm -rf {} + || true
  find "$BUILD_DIR/$SLUG/vendor" -type f \( -name '.git*' -o -name '.gitignore' \) -delete || true
  find "$BUILD_DIR/$SLUG/vendor" -type f \( -iname 'phpunit*.xml*' -o -iname 'psalm*.xml*' -o -iname 'phpstan*.neon*' -o -iname 'build.xml' -o -iname '*.dist' \) -delete || true
  find "$BUILD_DIR/$SLUG/vendor" -type f \( -name 'composer.lock' -o -name 'composer-php52.json' -o -name '.editorconfig' -o -name '.php_cs*' -o -name '.php-cs-fixer*' \) -delete || true
  find "$BUILD_DIR/$SLUG/vendor" -type f -iname '.travis.yml' -delete || true
  find "$BUILD_DIR/$SLUG/vendor" -type d -name '.github' -prune -exec rm -rf {} + || true
  find "$BUILD_DIR/$SLUG/vendor" -type d \( -iname 'tests' -o -iname 'test' -o -iname 'Tests' \) -prune -exec rm -rf {} + || true
  find "$BUILD_DIR/$SLUG/vendor" -type d \( -iname 'examples' -o -iname 'example' -o -iname 'Examples' \) -prune -exec rm -rf {} + || true
  find "$BUILD_DIR/$SLUG/vendor" -type d -name 'bin' -prune -exec rm -rf {} + || true
  find "$BUILD_DIR/$SLUG/vendor" -type f \( \
    -iname 'license' -o -iname 'license.*' -o \
    -iname '*.md' -o -iname '*.markdown' -o \
    -iname '*.yml' -o -iname '*.yaml' -o \
    -iname '*.sh' -o -iname '*.neon' -o \
    -iname 'Makefile' \
  \) -delete || true

  # Remove heavy fonts and unrelated images from endroid/qr-code
  if [[ -d "$BUILD_DIR/$SLUG/vendor/endroid/qr-code/assets" ]]; then
    log "Removing heavy fonts from endroid/qr-code/assets..."
    find "$BUILD_DIR/$SLUG/vendor/endroid/qr-code/assets" \
      -type f \( -iname '*.ttf' -o -iname '*.otf' -o -iname 'blackfire.png' \) \
      -print -delete || true
  fi

  # Remove root-level non-distributable files
  rm -f "$BUILD_DIR/$SLUG/LICENSE" || true
  rm -rf "$BUILD_DIR/$SLUG/examples" || true

  # composer.json/composer.lock/package.json are kept in the package (transparency for
  # WordPress.org review — nothing at runtime reads them, but users should be able to
  # inspect/reproduce the dependency tree from the distributed zip).

  # Remove leftover backup and temp files
  find "$BUILD_DIR/$SLUG" -name '*~' -type f -delete || true
  find "$BUILD_DIR/$SLUG" -name '*.po~' -type f -delete || true

  log "Vendor cleanup complete."
fi

# === ZIP ===
if [[ $DO_ZIP -eq 1 ]]; then
  header "Creating zip"
  mkdir -p "$ROOT_DIR/releases"
  ZIP_PATH="$ROOT_DIR/releases/${SLUG}-${VERSION}.zip"
  rm -f "$ZIP_PATH" || true
  log "Zipping → releases/${SLUG}-${VERSION}.zip"
  if [[ $DRY_RUN -eq 0 ]]; then
    (cd "$BUILD_DIR" && zip -r "$ZIP_PATH" "$SLUG") >/dev/null
    log "Zip created: $ZIP_PATH"
    log "Size: $(du -sh "$ZIP_PATH" | cut -f1)"
  else
    step "[dry-run] zip $ZIP_PATH"
  fi
fi

# === GIT ===
if [[ $DO_GIT -eq 1 ]]; then
  header "Git: commit + tag v$VERSION"
  if [[ $DRY_RUN -eq 0 ]]; then
    (cd "$ROOT_DIR" && git add \
      "$PLUGIN_FILE" \
      "$README_FILE" \
      "$TRUNK/composer.json" \
      "$TRUNK/package.json" \
    && git commit -m "chore: bump version to $VERSION" || log "No changes to commit")
    (cd "$ROOT_DIR" && git tag -a "v$VERSION" -m "Release v$VERSION" \
      && log "Tag v$VERSION created. Push manually: git push origin v$VERSION" \
      || warn "Tag v$VERSION already exists or failed.")
  else
    step "[dry-run] git add (version files) && git commit -m 'chore: bump version to $VERSION'"
    step "[dry-run] git tag -a v$VERSION"
  fi
fi

# === SVN ===
if [[ $DO_SVN -eq 1 ]]; then
  header "SVN export"
  svn_dir="$BUILD_DIR/svn-checkout"
  svn_url="https://plugins.svn.wordpress.org/${SLUG}"
  log "Checking out $svn_url"
  if [[ $DRY_RUN -eq 0 ]]; then
    svn checkout "$svn_url" "$svn_dir" || true
    log "Clearing SVN trunk..."
    find "$svn_dir/trunk" -mindepth 1 -delete || true
    log "Copying files to SVN trunk..."
    rsync -a "$BUILD_DIR/$SLUG/" "$svn_dir/trunk/"
    log "SVN checkout at: $svn_dir"
    log "Run: cd $svn_dir && svn add --force . && svn commit -m 'Release $VERSION'"
  else
    step "[dry-run] svn checkout + rsync to $svn_dir/trunk/"
  fi
fi

header "Done"
log "Release ${BOLD}$SLUG v$VERSION${NC} finished successfully."
[[ $DO_ZIP -eq 1 && $DRY_RUN -eq 0 ]] && log "Package: $ROOT_DIR/releases/${SLUG}-${VERSION}.zip"

exit 0
