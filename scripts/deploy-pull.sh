#!/usr/bin/env bash
# scripts/deploy-pull.sh — universal post-pull deploy.
#
# Runs after `git pull` on any server hosting this codebase
# (con-dev2, poes.health.go.ug, the Rwanda host, etc.) and brings the
# checkout to a fully-deployed state:
#
#   - composer install --no-dev   IF composer.lock changed
#   - npm ci                       IF package-lock.json changed
#   - npm run build                IF any of src/ public/ index.html package.json changed
#   - artisan config:cache / route:cache / view:cache
#   - reload PHP — Octane if installed and running, else php-fpm
#
# Detection is from git HEAD vs HEAD@{1} (the ref before the pull).
# Safe to re-run: every step is idempotent.
#
# Usage:
#   scripts/deploy-pull.sh           # full auto-detect deploy
#   scripts/deploy-pull.sh --force   # rebuild everything regardless of diff
#   scripts/deploy-pull.sh --dry-run # show what WOULD run; no side effects
#
# Exit codes:
#   0 — success
#   1 — one or more steps failed (with structured log)
#   2 — usage error
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# ---- PATH bootstrap for non-interactive shells (cron, ssh) ----------------
# Sourcing the user's nvm (most common node install on these servers).
# If a project-local .nvmrc exists, nvm picks the matching version; otherwise
# the default node is used. Falls through quietly if nvm isn't installed.
if [ -z "${_PATH_BOOTSTRAPPED:-}" ]; then
  export _PATH_BOOTSTRAPPED=1
  for nvm_dir in "$HOME/.nvm" "/home/ubuntu/.nvm" "/root/.nvm"; do
    if [ -s "$nvm_dir/nvm.sh" ]; then
      export NVM_DIR="$nvm_dir"
      # shellcheck source=/dev/null
      . "$nvm_dir/nvm.sh" >/dev/null 2>&1 || true
      break
    fi
  done
  # Common system locations as a last resort
  export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"
fi

FORCE=0
DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --force)    FORCE=1 ;;
    --dry-run)  DRY_RUN=1 ;;
    -h|--help)  sed -n '2,30p' "$0"; exit 0 ;;
    *)          echo "unknown flag: $arg" >&2; exit 2 ;;
  esac
done

# ---- logging --------------------------------------------------------------
LOG_DIR=/var/log
[ -w "$LOG_DIR" ] || LOG_DIR="$REPO_ROOT/storage/logs"
mkdir -p "$LOG_DIR" 2>/dev/null || LOG_DIR=/tmp
LOG_FILE="$LOG_DIR/poe-deploy.log"

log() {
  local lvl="$1"; shift
  printf '%s [%s] %s\n' "$(date -Iseconds)" "$lvl" "$*" | tee -a "$LOG_FILE"
}
run() {
  log INFO "exec: $*"
  if [ "$DRY_RUN" -eq 1 ]; then return 0; fi
  if "$@" >>"$LOG_FILE" 2>&1; then
    log INFO "  ok: $*"
    return 0
  else
    local rc=$?
    log ERROR "  fail($rc): $*"
    return $rc
  fi
}

START=$(date +%s)
log INFO "================================================================"
log INFO "deploy-pull start  host=$(hostname)  repo=$REPO_ROOT  force=$FORCE  dry=$DRY_RUN"

# ---- change detection -----------------------------------------------------
# HEAD@{1} is the SHA that was current before the most-recent ref update on
# this branch. After `git pull`, HEAD@{1} is the pre-pull SHA, HEAD is post.
PRE="$(git rev-parse --quiet --verify 'HEAD@{1}' || true)"
HEAD="$(git rev-parse HEAD)"
RANGE="HEAD"
if [ -n "$PRE" ] && [ "$PRE" != "$HEAD" ]; then
  RANGE="$PRE..HEAD"
fi
log INFO "diff range: $RANGE  (HEAD=$HEAD)"

changed() {
  [ "$FORCE" -eq 1 ] && return 0
  local pattern="$1"
  if [ "$RANGE" = "HEAD" ]; then
    # First deploy ever — assume everything changed
    return 0
  fi
  git diff --name-only "$RANGE" -- $pattern 2>/dev/null | grep -q . || return 1
}

# ---- detect environment ---------------------------------------------------
HAS_API=0
HAS_FRONTEND=0
[ -f "$REPO_ROOT/api/artisan" ]   && HAS_API=1
[ -f "$REPO_ROOT/package.json" ]  && HAS_FRONTEND=1
log INFO "components: api=$HAS_API  frontend=$HAS_FRONTEND"

# ---- 1. composer (api) ----------------------------------------------------
if [ "$HAS_API" -eq 1 ] && { [ "$FORCE" -eq 1 ] || git diff --name-only "$RANGE" -- 'api/composer.lock' 'api/composer.json' 2>/dev/null | grep -q .; }; then
  log INFO "composer.lock or composer.json changed → composer install"
  ( cd "$REPO_ROOT/api" && run composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist ) || log WARN "composer install failed — continuing"
else
  log INFO "composer: no change"
fi

# ---- 2. npm (frontend) ----------------------------------------------------
NEEDS_NPM=0
if [ "$HAS_FRONTEND" -eq 1 ] && { [ "$FORCE" -eq 1 ] || git diff --name-only "$RANGE" -- 'package-lock.json' 'package.json' 2>/dev/null | grep -q .; }; then
  NEEDS_NPM=1
  log INFO "package-lock.json or package.json changed → npm ci"
  if [ -f "$REPO_ROOT/package-lock.json" ]; then
    run npm ci --silent --no-audit --no-fund || run npm install --no-audit --no-fund || log WARN "npm install failed — continuing"
  else
    run npm install --no-audit --no-fund || log WARN "npm install failed — continuing"
  fi
else
  log INFO "npm: no change"
fi

# ---- 3. frontend build ----------------------------------------------------
if [ "$HAS_FRONTEND" -eq 1 ] && {
     [ "$FORCE" -eq 1 ] || [ "$NEEDS_NPM" -eq 1 ] ||
     git diff --name-only "$RANGE" -- 'src/' 'public/' 'index.html' 'vite.config.ts' 'tsconfig.json' 2>/dev/null | grep -q .;
   }; then
  log INFO "frontend assets changed → npm run build"
  run npm run build || log WARN "vite build failed — frontend not rebuilt"
else
  log INFO "frontend build: no change"
fi

# ---- 4. artisan caches (api) ----------------------------------------------
if [ "$HAS_API" -eq 1 ]; then
  cd "$REPO_ROOT/api"
  run php artisan config:clear || true
  run php artisan route:clear  || true
  run php artisan view:clear   || true
  run php artisan event:clear  || true
  # Rebuild caches for production performance
  if grep -q '^APP_ENV=production' "$REPO_ROOT/api/.env" 2>/dev/null; then
    run php artisan config:cache || true
    run php artisan route:cache  || true
    run php artisan view:cache   || true
  else
    log INFO "non-production: skipping cache rebuild"
  fi

  # Run migrations only if migration files changed (NEVER auto-rollback)
  if [ "$FORCE" -eq 1 ] || git diff --name-only "$RANGE" -- 'api/database/migrations/' 2>/dev/null | grep -q .; then
    log INFO "migrations changed → artisan migrate --force"
    run php artisan migrate --force --no-interaction || log ERROR "migrations failed — investigate"
  fi
  cd "$REPO_ROOT"
fi

# ---- 5. reload PHP runtime ------------------------------------------------
reload_php() {
  if [ "$HAS_API" -ne 1 ]; then return 0; fi
  cd "$REPO_ROOT/api"

  # Octane (con-dev2 + similar hosts)
  if grep -q '"laravel/octane"' composer.json 2>/dev/null \
     && php artisan octane:status >/dev/null 2>&1; then
    log INFO "octane detected → octane:reload"
    run php artisan octane:reload || log WARN "octane:reload failed"
    cd "$REPO_ROOT"
    return 0
  fi

  # PHP-FPM (poes.health.go.ug government server + similar)
  # Try sudoers-friendly reload; deploy user must have NOPASSWD on this one
  # systemctl invocation (see DEPLOY.md).
  for unit in php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
    if systemctl is-active --quiet "$unit" 2>/dev/null; then
      log INFO "php-fpm unit detected: $unit → systemctl reload"
      if sudo -n systemctl reload "$unit" >/dev/null 2>&1; then
        log INFO "  $unit reloaded"
      else
        log WARN "  cannot reload $unit without sudo — opcache may still hold stale code"
      fi
      cd "$REPO_ROOT"
      return 0
    fi
  done

  log WARN "no PHP runtime to reload — code is on disk but workers may serve stale OPcache"
  cd "$REPO_ROOT"
}
reload_php

# ---- 6. capacitor sync (mobile native bindings) ---------------------------
# Only meaningful when running ON the build machine, not the API server.
# Skipped silently if @capacitor/cli isn't installed.
if [ "$HAS_FRONTEND" -eq 1 ] && [ -f "$REPO_ROOT/node_modules/.bin/cap" ] && [ -d "$REPO_ROOT/android" ]; then
  if [ "$FORCE" -eq 1 ] || git diff --name-only "$RANGE" -- 'capacitor.config.ts' 'package-lock.json' 2>/dev/null | grep -q .; then
    log INFO "capacitor config / native deps changed → npx cap sync android"
    run npx cap sync android || log WARN "cap sync failed — APK rebuild needed"
  fi
fi

END=$(date +%s)
log INFO "deploy-pull done  elapsed=$((END-START))s  HEAD=$HEAD"
log INFO "================================================================"
