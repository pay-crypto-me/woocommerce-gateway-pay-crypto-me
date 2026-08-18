#!/usr/bin/env bash
set -euo pipefail

# Release helper for PayCrypto.Me plugin
# Usage: ./scripts/release.sh -v VERSION -s SLUG [--no-build] [--no-tests] [--no-zip] [--git]
#                             [--svn | --svn-commit] [--no-docker] [--dry-run]

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

# Compose v2 ships as the `docker compose` plugin, but plenty of hosts only have the standalone
# `docker-compose` binary (also v2 nowadays) — same fallback as smoke-minimal-host.sh and
# build-translations.sh. Without it this script refused to run on exactly those hosts. Resolved
# for real in the Docker check below; this is only the default.
DOCKER_COMPOSE=(docker compose)

# Run a command in a throwaway `release` container (working dir /plugin = src/trunk).
docker_exec() {
    "${DOCKER_COMPOSE[@]}" run --rm "$RELEASE_SERVICE" bash -c "$1"
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
  --svn           Prepare the SVN working copy from the approved zip (no commit)
  --svn-commit    Same as --svn, then commit trunk/assets and create the SVN tag
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
DO_SVN_COMMIT=0
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
    --svn)        DO_SVN=1; shift;;
    --svn-commit) DO_SVN=1; DO_SVN_COMMIT=1; shift;;
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

# Publishing by re-running the build would re-resolve the private Composer forks
# and diverge from the approved bytes. --svn publishes the zip that already
# exists, period.
if [[ $DO_SVN -eq 1 && ( $DO_BUILD -eq 1 || $DO_TESTS -eq 1 || $DO_ZIP -eq 1 ) ]]; then
  error "--svn/--svn-commit publishes the already-built zip and must not rebuild it."
  error "Re-run with: --no-build --no-tests --no-zip"
  exit 1
fi

# A publish run never bumps versions, so --git would have nothing to commit or
# tag. Refusing is clearer than silently doing nothing. Tag during the build run.
if [[ $DO_SVN -eq 1 && $DO_GIT -eq 1 ]]; then
  error "--git has no effect with --svn/--svn-commit: a publish run does not bump versions."
  error "Create the git tag during the build run, before publishing."
  exit 1
fi
PUBLISH_ONLY=$DO_SVN

# === DRY RUN WRAPPER ===
# Wraps commands so they are printed but not executed in --dry-run mode.
run() {
  if [[ $DRY_RUN -eq 1 ]]; then
    step "[dry-run] $*"
  else
    "$@"
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
ZIP_PATH="$ROOT_DIR/releases/${SLUG}-${VERSION}.zip"

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
  if docker compose version >/dev/null 2>&1 || command -v docker-compose >/dev/null 2>&1; then
    if ! docker compose version >/dev/null 2>&1; then
      DOCKER_COMPOSE=(docker-compose)
      log "Using the standalone 'docker-compose' binary (the 'docker compose' plugin is not installed)."
    fi
    log "Build/tests will run in the ephemeral '$RELEASE_SERVICE' service (dev stack not required)."
    # Forward the repo-root auth.json to Composer inside the container. Optional — safe
    # if absent, and no longer required now that all deps come from Packagist (the private
    # bitcoin fork is gone); kept only to avoid GitHub API rate limits on dist downloads
    # when an auth.json happens to be present.
    if [[ -f "$ROOT_DIR/auth.json" ]]; then
      export COMPOSER_AUTH="$(cat "$ROOT_DIR/auth.json")"
      log "Forwarding auth.json to Composer via COMPOSER_AUTH."
    fi
  else
    error "Neither 'docker compose' nor 'docker-compose' is available. Install Docker (with"
    error "Compose), or pass --no-docker to run build/tests on the host (requires local"
    error "Node.js, PHP, Composer)."
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

  # `config.platform.php` decides which PHP Composer resolves the whole tree against. Pinned at or
  # above the plugin's real floor it hides nothing and just makes resolution reproducible; pinned
  # below it (as it was at 7.4), a dependency incompatible with that floor installs silently. This
  # audits both regimes against the floor in the plugin header and fails on any package that blocks
  # it. Cheap (no dev stack needed) and hard to forget when it lives here.
  header "Platform pin audit"
  if [[ $DRY_RUN -eq 0 ]]; then
    "$ROOT_DIR/scripts/check-platform-pin.sh"
  else
    step "[dry-run] scripts/check-platform-pin.sh"
  fi

  # The docs here are read before the code, by people and agents alike, so a stale record is worse
  # than no record. This checks the mechanical part (paths, line references, the hooks table, the
  # counts stated in prose) against the tree. It cannot be a PHPUnit test: the suite's world is
  # src/trunk, and CLAUDE.md/docs/ live above it. Cheap, no Docker, no network.
  header "Docs drift audit"
  if [[ $DRY_RUN -eq 0 ]]; then
    "$ROOT_DIR/scripts/check-docs-drift.sh"
  else
    step "[dry-run] scripts/check-docs-drift.sh"
  fi
fi

if [[ $PUBLISH_ONLY -eq 0 ]]; then

# === VERSION BUMPS ===
header "Version bumps → $VERSION"

# $4 (verify) is not optional bookkeeping: it is what makes a bump that silently matched nothing
# fail the release. The VERSION class constant went un-bumped through the whole 0.1.0 cycle because
# its pattern required `public const string VERSION` (a typed constant) while the code declares
# `public const VERSION` — sed matched nothing, returned 0, and the script logged success. That
# constant is the cache-busting version of the block assets (AssetManager) and the value the
# premium add-on's dependency guard compares against, so shipping it stale is not cosmetic.
bump_sed() {
  local file="$1" pattern="$2" label="$3" verify="$4"

  if [[ ! -f "$file" ]]; then
    return
  fi

  log "Updating $label in $file"

  if [[ $DRY_RUN -eq 1 ]]; then
    step "[dry-run] sed: $label → $VERSION"
    return
  fi

  sed -E -i.bak "$pattern" "$file"
  rm -f "$file.bak"

  # Checked after the fact rather than by counting substitutions, so re-running the script for a
  # version already applied stays a no-op instead of an error.
  if ! grep -qE "$verify" "$file"; then
    error "$label was not updated to $VERSION in $file — the pattern matched nothing."
    error "Fix the pattern in $(basename "$0") before releasing; a stale version would ship silently."
    exit 1
  fi
}

# Plugin header: " * Version: X.Y.Z"
bump_sed "$PLUGIN_FILE" \
  "s/^(\\s*\\*\\s*Version:[[:space:]]*).*/\\1$VERSION/" \
  "Version: header" \
  "^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*$VERSION[[:space:]]*$"

# PHP class constant: public const VERSION = 'X.Y.Z'; (the `string` type is optional)
bump_sed "$PLUGIN_FILE" \
  "s/^(\\s*public\\s+const\\s+(string\\s+)?VERSION\\s*=\\s*')[^']+(';)/\\1$VERSION\\3/" \
  "VERSION class constant" \
  "public const (string )?VERSION = '$VERSION';"

# readme.txt: Stable tag
bump_sed "$README_FILE" \
  "s/^(Stable tag:[[:space:]]*).*/\\1$VERSION/" \
  "Stable tag" \
  "^Stable tag:[[:space:]]*$VERSION[[:space:]]*$"

# CLAUDE.md states the current version twice, and it is the file every agent loads first — a stale
# number there is read as fact. Bumped here so it cannot fall behind the header it describes.
bump_sed "$ROOT_DIR/CLAUDE.md" \
  "s/(current version \*\*)[0-9]+\.[0-9]+\.[0-9]+(\*\*)/\1$VERSION\2/" \
  "current version in CLAUDE.md" \
  "current version \*\*$VERSION\*\*"

bump_sed "$ROOT_DIR/CLAUDE.md" \
  "s/(Version: \*\*)[0-9]+\.[0-9]+\.[0-9]+(\*\*)/\1$VERSION\2/" \
  "Version: line in CLAUDE.md" \
  "Version: \*\*$VERSION\*\*"

# composer.json and package.json: "version": "X.Y.Z"
for f in "$TRUNK/composer.json" "$TRUNK/package.json"; do
  bump_sed "$f" \
    's/^([[:space:]]*"version"[[:space:]]*:[[:space:]]*")[^"]+("[[:space:]]*,?)/\1'"$VERSION"'\2/' \
    "version in $(basename "$f")" \
    "\"version\"[[:space:]]*:[[:space:]]*\"$VERSION\""
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
    "${DOCKER_COMPOSE[@]}" run --rm \
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
  step "[dry-run] ${DOCKER_COMPOSE[*]} run: composer install --no-dev --optimize-autoloader in build dir"
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
  log "Zipping → releases/${SLUG}-${VERSION}.zip"
  if [[ $DRY_RUN -eq 0 ]]; then
    rm -f "$ZIP_PATH" || true
    (cd "$BUILD_DIR" && zip -r "$ZIP_PATH" "$SLUG") >/dev/null
    log "Zip created: $ZIP_PATH"
    log "Size: $(du -sh "$ZIP_PATH" | cut -f1)"
  else
    step "[dry-run] zip $ZIP_PATH"
  fi
fi

fi # PUBLISH_ONLY

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

# === SVN PUBLISH (WordPress.org) ===
# A fonte da verdade é o ZIP APROVADO, nunca o BUILD_DIR efêmero.
svn_publish() {
  local payload bad left st rev existing wc_url out
  local containers=()

  mkdir -p "$ROOT_DIR/releases"

  # ---- 1. working copy esparso: checkout novo, ou reset de um existente ----
  if [[ -e "$SVN_DIR" ]] && ! svn info "$SVN_DIR" >/dev/null 2>&1; then
    error "$SVN_DIR existe mas não é um working copy SVN."
    error "Remova e rode de novo:  rm -rf \"$SVN_DIR\""
    return 1
  fi

  if svn info "$SVN_DIR" >/dev/null 2>&1; then
    # Sem esta assertiva, um ensaio (SVN_URL=file://...) rodado depois de um run
    # real reusaria um WC apontado para o plugins.svn.wordpress.org de verdade —
    # e com --svn-commit publicaria lá achando que estava ensaiando.
    wc_url="$(svn info --show-item url --no-newline "$SVN_DIR")"
    if [[ "$wc_url" != "$SVN_URL" ]]; then
      error "O working copy em $SVN_DIR aponta para:"
      error "  $wc_url"
      error "mas SVN_URL é:"
      error "  $SVN_URL"
      error "Remova e rode de novo:  rm -rf \"$SVN_DIR\""
      return 1
    fi
    step "Resetando o working copy para o estado pristino"
    svn cleanup "$SVN_DIR"                                        # solta locks
    svn revert -R --remove-added "$SVN_DIR"                       # mods E schedule-adds
    svn cleanup --remove-unversioned --remove-ignored "$SVN_DIR"  # cruft
    svn update "$SVN_DIR" "${SVN_RO[@]}"                          # cura '!' incomplete
  else
    step "Checkout esparso de $SVN_URL"
    svn checkout --depth immediates "$SVN_URL" "$SVN_DIR" "${SVN_RO[@]}"
  fi

  # trunk PRECISA estar completo: um trunk esparso commitaria deleções falsas.
  if [[ ! -d "$SVN_DIR/trunk" ]]; then
    error "Não há trunk/ em $SVN_URL — slug errado?"; return 1
  fi
  svn update --set-depth infinity "$SVN_DIR/trunk" "${SVN_RO[@]}"
  if [[ "$(svn info --show-item depth --no-newline "$SVN_DIR/trunk")" != "infinity" ]]; then
    error "trunk/ não está em depth=infinity; abortando."; return 1
  fi

  if [[ -d "$SVN_DIR/assets" ]]; then
    svn update --set-depth infinity "$SVN_DIR/assets" "${SVN_RO[@]}"
  else
    warn "assets/ ausente no repositório — será criado por este commit."
    mkdir -p "$SVN_DIR/assets"
    svn add --depth empty "$SVN_DIR/assets"
  fi
  # tags/ fica em depth=empty de propósito: a tag é criada server-side, então
  # nada local consegue aninhar dentro de um tags/<versão>/ já existente.

  # ---- 2. payload vem do ZIP APROVADO -------------------------------------
  step "Extraindo $ZIP_PATH"
  rm -rf "$SVN_STAGE"; mkdir -p "$SVN_STAGE"
  unzip -q "$ZIP_PATH" -d "$SVN_STAGE"
  payload="$SVN_STAGE/$SLUG"
  if [[ ! -d "$payload" ]]; then
    error "O zip não tem o diretório de topo '$SLUG/'."; return 1
  fi
  # O zip precisa ser o artefato DESTA versão, senão publicaríamos um readme cujo
  # Stable tag discorda da tag que estamos prestes a criar.
  if ! grep -qE "^Stable tag:[[:space:]]*${VERSION}[[:space:]]*$" "$payload/readme.txt"; then
    error "readme.txt do zip não declara 'Stable tag: $VERSION'."; return 1
  fi
  if ! grep -qE "^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*${VERSION}[[:space:]]*$" \
                "$payload/${SLUG}.php"; then
    error "Header do plugin no zip não é 'Version: $VERSION'."; return 1
  fi

  # ---- 3. espelhar payload em trunk/ e assets/ ----------------------------
  # -c: compara por checksum, então arquivos iguais mantêm mtime e o svn status
  # fica O(mudanças). --exclude='.svn/': no-op hoje (svn 1.7+ mantém um único
  # .svn na raiz do WC, que está ACIMA de trunk/) — mantido como seguro caso
  # alguém reancore o WC em trunk/. NUNCA adicionar --delete-excluded.
  step "Espelhando payload -> trunk/"
  rsync -a -c --delete --exclude='.svn/' "$payload/" "$SVN_DIR/trunk/"
  step "Espelhando src/assets -> assets/"
  rsync -a -c --delete --exclude='.svn/' "$ASSETS_SRC/" "$SVN_DIR/assets/"

  # ---- 4. reconciliar metadados do SVN com o filesystem -------------------
  # GUARD PRIMEIRO: '!' também significa "incomplete" (svn help status), então um
  # update interrompido faria o sweep abaixo agendar a deleção de TODAS as tags.
  containers=( "$SVN_DIR" "$SVN_DIR/trunk" "$SVN_DIR/assets" )
  if [[ -d "$SVN_DIR/tags" ]];     then containers+=( "$SVN_DIR/tags" ); fi
  if [[ -d "$SVN_DIR/branches" ]]; then containers+=( "$SVN_DIR/branches" ); fi
  bad="$( svn status --depth empty "${containers[@]}" | { grep -E '^[!~]' || true; } )"
  if [[ -n "$bad" ]]; then
    error "Working copy inconsistente (dirs de topo missing/obstructed):"
    printf '%s\n' "$bad" >&2
    error "Remova e rode de novo para um checkout limpo:  rm -rf \"$SVN_DIR\""
    return 1
  fi

  # Deleções: '!' = versionado mas sumiu do disco. ESCOPADO a trunk+assets.
  # O '|| true' é obrigatório: grep sai 1 quando nada falta, e pipefail
  # transformaria isso em abort DEPOIS do WC já ter sido mutado.
  # (xargs -r -d é GNU — ok em Linux/WSL, não em macOS/BSD.)
  step "Agendando deleções"
  ( cd "$SVN_DIR" \
    && svn status trunk assets | { grep '^!' || true; } | cut -c9- \
       | xargs -r -d '\n' svn rm --force )

  # Adições: alvos explícitos apenas — nunca `svn add .` na raiz do WC, que
  # entraria nos tags/<versão-antiga>/ depth-empty e colidiria no commit.
  # --no-ignore: global-ignores (*.a *.so *~ .DS_Store ...) descartaria arquivos
  # do payload em silêncio. --no-auto-props: bytes publicados verbatim.
  step "Agendando adições"
  svn add --force --no-ignore --no-auto-props --depth infinity -q \
          "$SVN_DIR/trunk" "$SVN_DIR/assets"

  # Pós-condição: nada não-reconciliado pode sobreviver (?=unversioned,
  # !=missing, ~=obstructed, C=conflito).
  left="$( svn status "$SVN_DIR/trunk" "$SVN_DIR/assets" \
           | { grep -E '^[?!~]|^.C|^.{6}C' || true; } )"
  if [[ -n "$left" ]]; then
    error "Estado não reconciliado no working copy:"
    printf '%s\n' "$left" >&2
    return 1
  fi

  # ---- 5. gate de revisão -------------------------------------------------
  header "Mudanças SVN pendentes para $VERSION → $SVN_URL"
  st="$(svn status -q "$SVN_DIR")"
  if [[ -z "$st" ]]; then
    warn "Nenhuma mudança versionada: trunk/ e assets/ já batem com este release."
  else
    # Nada de `| head`: SIGPIPE (141) + pipefail abortaria com o WC já preparado.
    awk '{print substr($0,1,2)}' <<<"$st" | sort | uniq -c | sort -rn
    awk 'NR<=30' <<<"$st"
    log "$(wc -l <<<"$st") caminho(s) alterado(s)"
  fi

  if [[ $DO_SVN_COMMIT -eq 0 ]]; then
    log "Nada commitado (gate de revisão)."
    log "Inspecionar:  (cd $SVN_DIR && svn status)"
    log "Publicar:     $0 -v $VERSION -s $SLUG --no-build --no-tests --no-zip --svn-commit"
    return 0
  fi

  # ---- 6. commit de trunk+assets, depois tag por cópia server-side --------
  # A tag NÃO pode existir: `svn help copy` — "If DST is an existing directory,
  # the sources will be added as children of DST" — então re-taggear criaria
  # tags/$VERSION/trunk/ silenciosamente em vez de falhar. Capturar antes, para
  # que uma falha do `svn ls` aborte em vez de ser engolida pelo exit do grep.
  existing="$(svn ls "$SVN_URL/tags/" "${SVN_RO[@]}")"
  if printf '%s\n' "$existing" | grep -qx "${VERSION}/"; then
    error "tags/$VERSION já existe em $SVN_URL."
    error "Tags do WP.org são imutáveis por convenção — bumpe a versão e rode de novo."
    return 1
  fi

  step "Commitando trunk/ + assets/"
  out="$(LC_ALL=C svn commit "$SVN_DIR" -m "Release $VERSION" "${SVN_RW[@]}" | tee /dev/stderr)"
  rev="$(sed -n 's/^Committed revision \([0-9]\{1,\}\)\.$/\1/p' <<<"$out" | tail -n1)"
  if [[ -z "$rev" ]]; then
    # Nada a commitar — acontece ao re-rodar depois de um `svn copy` que falhou.
    # Taggeia o trunk atual em vez de morrer com uma revisão vazia.
    rev="$(svn info --show-item revision --no-newline "$SVN_URL/trunk" "${SVN_RO[@]}")"
    warn "Nada a commitar; taggeando o trunk existente @$rev."
  fi

  step "Taggeando trunk@$rev -> tags/$VERSION (cópia server-side, 0 bytes)"
  svn copy "$SVN_URL/trunk@$rev" "$SVN_URL/tags/$VERSION" \
           -m "Tag $VERSION (copy of trunk@$rev)" "${SVN_RW[@]}"

  svn update "$SVN_DIR" "${SVN_RO[@]}" >/dev/null
  log "Publicado $VERSION: trunk@$rev + tags/$VERSION"
}

if [[ $DO_SVN -eq 1 ]]; then
  header "SVN publish -> WordPress.org"

  SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org/${SLUG}}"  # env-overridable p/ ensaio
  SVN_DIR="$ROOT_DIR/releases/svn"            # persistente, gitignored, FORA do BUILD_DIR
  SVN_STAGE="$ROOT_DIR/releases/.svn-stage"   # limpo no início do run
  ASSETS_SRC="$ROOT_DIR/src/assets"           # NÃO src/trunk/assets (esse é runtime, vai no zip)
  SVN_USER="${SVN_USER:-paycryptome}"

  # Sempre visível: num working copy reusado nada mais imprimiria o destino, e
  # este é um passo que escreve em público.
  log "Repositório de destino: ${BOLD}$SVN_URL${NC}"
  log "Working copy:           $SVN_DIR"

  # Fixar auto-props=no torna os bytes publicados independentes do
  # ~/.subversion/config do operador (que poderia aplicar svn:eol-style /
  # svn:keywords). RO pode rodar desassistido; RW precisa poder pedir a senha,
  # por isso NÃO leva --non-interactive.
  SVN_RO=( --username "$SVN_USER" --non-interactive
           --config-option "config:miscellany:enable-auto-props=no" )
  SVN_RW=( --username "$SVN_USER"
           --config-option "config:miscellany:enable-auto-props=no" )

  for _bin in svn unzip rsync; do
    command -v "$_bin" >/dev/null 2>&1 || { error "'$_bin' não encontrado no PATH."; exit 1; }
  done
  [[ -f "$ZIP_PATH" ]] || { error "Zip aprovado não encontrado: $ZIP_PATH"; exit 1; }
  # Protege contra confundir src/assets (banner/ícone/screenshots do diretório)
  # com src/trunk/assets (assets de runtime, que vão dentro do zip).
  [[ -f "$ASSETS_SRC/banner-1544x500.png" ]] \
    || { error "Assets de diretório do WP.org não encontrados em $ASSETS_SRC"; exit 1; }

  if [[ $DRY_RUN -eq 1 ]]; then
    step "[dry-run] svn checkout --depth immediates $SVN_URL $SVN_DIR (+ set-depth infinity trunk/assets)"
    step "[dry-run] unzip $ZIP_PATH -> $SVN_STAGE; rsync --delete -> trunk/ e assets/"
    step "[dry-run] svn rm (missing) / svn add --force --no-ignore; gate de revisão"
    step "[dry-run] svn commit + svn copy $SVN_URL/trunk@REV -> tags/$VERSION"
  else
    svn_publish
  fi
fi

header "Done"
log "Release ${BOLD}$SLUG v$VERSION${NC} finished successfully."
[[ $DO_ZIP -eq 1 && $DRY_RUN -eq 0 ]] && log "Package: $ROOT_DIR/releases/${SLUG}-${VERSION}.zip"

exit 0
