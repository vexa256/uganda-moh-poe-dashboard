#!/usr/bin/env bash
# ============================================================================


set -euo pipefail
trap 'echo; printf "\033[31m✘ Aborted on line %s (last command exit: %s)\033[0m\n" "$LINENO" "$?" >&2' ERR

# ----- Constants you can override at the top of the file ---------------------
APP_ROOT_DEFAULT="/var/www/poes.health.go.ug/api"
DB_NAME_DEFAULT="ecsa_uganda_2026"
DB_USER_DEFAULT="ecsa_uganda"
DB_HOST_DEFAULT="127.0.0.1"
DB_PORT_DEFAULT="3306"
WEB_USER_DEFAULT="www-data"

# ----- UI helpers ------------------------------------------------------------
c_green()  { printf "\033[32m%s\033[0m\n" "$*"; }
c_yellow() { printf "\033[33m%s\033[0m\n" "$*"; }
c_red()    { printf "\033[31m%s\033[0m\n" "$*" >&2; }
c_blue()   { printf "\033[34m%s\033[0m\n" "$*"; }
hr() { printf '%.0s─' {1..70}; echo; }
step() { echo; hr; c_blue "▸ $*"; hr; }

ask()      { local p="$1" def="${2:-}" v; read -r -p "$p${def:+ [$def]}: " v; echo "${v:-$def}"; }
ask_yn()   { local p="$1" def="${2:-y}" v; read -r -p "$p [Y/n]: " v; v="${v:-$def}"; [[ "$v" =~ ^[Yy]$ ]]; }
ask_pw()   { local p="$1" v; read -r -s -p "$p (leave blank to auto-generate strong 24-char): " v; echo; echo "$v"; }

require_root() {
  if [[ $EUID -ne 0 ]]; then
    c_red "This script must be run as root (use: sudo bash $0)"
    exit 1
  fi
}

# Pure-bash, pipefail-safe password generator. openssl is on every modern
# server; the `tr -dc ... | head -c` recipe is intentionally avoided because
# `head` closing early makes `tr` exit 141 (SIGPIPE), which `pipefail` will
# treat as a fatal pipeline error and abort the entire script.
genpw() {
  if command -v openssl >/dev/null 2>&1; then
    local raw
    raw=$(openssl rand -hex 24)         # always 48 hex chars
    printf '%s' "${raw:0:24}"
  else
    # Fallback: read enough bytes in one go, post-filter in pure bash.
    local bytes raw
    bytes=$(LC_ALL=C dd if=/dev/urandom bs=1 count=128 2>/dev/null | base64)
    raw=${bytes//[^A-Za-z0-9]/}
    printf '%s' "${raw:0:24}"
  fi
}

# ----- 0. PRE-FLIGHT ---------------------------------------------------------
require_root

echo
c_blue "════════════════════════════════════════════════════════════════════"
c_blue " Laravel + MySQL one-shot repair · poes.health.go.ug"
c_blue "════════════════════════════════════════════════════════════════════"
echo
echo "This script will repair Laravel storage permissions, ensure a MySQL"
echo "user + database exist with correct grants, write a .env that points"
echo "at them, and run pending migrations. It is idempotent — running it"
echo "again is safe."
echo

APP_ROOT=$(ask "Laravel application root" "$APP_ROOT_DEFAULT")
DB_NAME=$(ask "MySQL database name"        "$DB_NAME_DEFAULT")
DB_USER=$(ask "MySQL application user"     "$DB_USER_DEFAULT")
DB_HOST=$(ask "MySQL host"                 "$DB_HOST_DEFAULT")
DB_PORT=$(ask "MySQL port"                 "$DB_PORT_DEFAULT")
WEB_USER=$(ask "Web user that PHP-FPM runs as" "$WEB_USER_DEFAULT")
DB_PASS=$(ask_pw "MySQL password for $DB_USER")
if [[ -z "$DB_PASS" ]]; then
  DB_PASS=$(genpw)
  c_yellow "→ Generated 24-char alnum password (saved into .env only)."
fi
# Reject a literal single-quote in the password. We store DB_PASSWORD in
# .env single-quoted (Laravel preserves single-quoted values verbatim — no
# escape processing), and `'` is the one char that breaks that quoting.
if [[ "$DB_PASS" == *"'"* ]]; then
  c_red "Password contains a literal single quote (') — that breaks .env"
  c_red "single-quoted storage. Re-run and choose a password without '."
  exit 1
fi

# Sanity-check the inputs before doing anything
[[ -d "$APP_ROOT" ]] || { c_red "Application root '$APP_ROOT' not found."; exit 1; }
[[ -f "$APP_ROOT/artisan" ]] || { c_red "'$APP_ROOT' does not look like a Laravel app (no artisan)."; exit 1; }
command -v php   >/dev/null || { c_red "PHP is not installed on PATH."; exit 1; }
command -v mysql >/dev/null || { c_red "mysql client is not installed."; exit 1; }
id "$WEB_USER" >/dev/null 2>&1 || { c_red "User '$WEB_USER' does not exist on this server."; exit 1; }

cd "$APP_ROOT"
PHP_BIN=$(command -v php)

# ----- 1. STORAGE SKELETON + PERMISSIONS ------------------------------------
step "1/6 · Storage directories + permissions"

mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/framework/testing \
  storage/logs \
  storage/app/public \
  bootstrap/cache

chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache
chmod -R u+rwX,g+rwX,o+rX storage bootstrap/cache
# setgid: any file later created inside inherits the group, so the next
# deploy / git pull / rsync cannot break this again.
find storage bootstrap/cache -type d -exec chmod g+s {} +

c_green "✔ storage/framework/{cache,sessions,views,testing} + storage/logs + bootstrap/cache present, owned by $WEB_USER, setgid."

# ----- 2. MYSQL USER + DATABASE ---------------------------------------------
step "2/6 · MySQL user + database (via sudo mysql socket-auth)"

# Verify we can reach MySQL as root over the Unix socket
if ! mysql -e 'SELECT 1' >/dev/null 2>&1; then
  c_red "Cannot connect to MySQL as root via the Unix socket."
  c_red "If MySQL is on a remote host, edit this script to use credentialed"
  c_red "access. Aborting before we make a mess."
  exit 1
fi

# Escape any quotes the password might carry, then build a one-liner
ESC_PASS=${DB_PASS//\'/\'\'}
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost'
  IDENTIFIED BY '${ESC_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1'
  IDENTIFIED BY '${ESC_PASS}';
ALTER USER '${DB_USER}'@'localhost'  IDENTIFIED BY '${ESC_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1'  IDENTIFIED BY '${ESC_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# Verify the new user can actually log in and see the database
if ! MYSQL_PWD="$DB_PASS" mysql -u "$DB_USER" -h "$DB_HOST" -P "$DB_PORT" -e "USE \`${DB_NAME}\`;" 2>/dev/null; then
  c_red "User '${DB_USER}' could not authenticate to '${DB_NAME}'."
  c_red "Common cause: MySQL host is not '$DB_HOST' (try 'localhost')."
  exit 1
fi

c_green "✔ Database '${DB_NAME}' exists and '${DB_USER}' has full privileges on it."

# ----- 3. .env FILE ---------------------------------------------------------
step "3/6 · .env (preserving any operator overrides)"

ENV_FILE="$APP_ROOT/.env"

if [[ ! -f "$ENV_FILE" ]]; then
  if [[ -f "$APP_ROOT/.env.example" ]]; then
    cp "$APP_ROOT/.env.example" "$ENV_FILE"
    c_yellow "→ Copied .env.example → .env (no existing .env found)."
  else
    cat >"$ENV_FILE" <<'ENV'
APP_NAME=Laravel
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost
LOG_CHANNEL=stack
ENV
    c_yellow "→ No .env or .env.example present; wrote a minimal skeleton."
  fi
fi
chown "$WEB_USER:$WEB_USER" "$ENV_FILE"
chmod 640 "$ENV_FILE"

# Patch a key in-place — preserve unrelated lines the operator has set.
# Sed-replacement-safe escaping: backslash first (so our own added
# backslashes aren't re-escaped), then ampersand (whole-match shortcut),
# then forward slash (the delimiter).
set_env() {
  local key="$1" val="$2"
  local esc_val=$val
  esc_val=${esc_val//\\/\\\\}
  esc_val=${esc_val//&/\\&}
  esc_val=${esc_val//\//\\/}
  if grep -qE "^${key}=" "$ENV_FILE"; then
    sed -i -E "s/^${key}=.*/${key}=${esc_val}/" "$ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$val" >>"$ENV_FILE"
  fi
}

set_env DB_CONNECTION "mysql"
set_env DB_HOST       "$DB_HOST"
set_env DB_PORT       "$DB_PORT"
set_env DB_DATABASE   "$DB_NAME"
set_env DB_USERNAME   "$DB_USER"
set_env DB_PASSWORD   "'${DB_PASS}'"     # single-quoted = literal in .env

# Generate APP_KEY only if missing (don't rotate a working key)
if ! grep -qE '^APP_KEY=base64:' "$ENV_FILE"; then
  sudo -u "$WEB_USER" "$PHP_BIN" artisan key:generate --force --ansi
  c_green "✔ APP_KEY generated."
else
  c_green "✔ APP_KEY already set — preserved."
fi

c_green "✔ .env points at ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"

# ----- 4. CLEAR STALE CACHES ------------------------------------------------
step "4/6 · Clear stale config / view / route / event caches"

# Some of these can fail on a broken stack (e.g. cache:clear can't reach Redis
# if Redis was the cache driver and isn't running). Don't abort the whole
# script for a transient cache:clear error — only the manual rm matters.
sudo -u "$WEB_USER" "$PHP_BIN" artisan config:clear 2>/dev/null || c_yellow "  (config:clear skipped)"
sudo -u "$WEB_USER" "$PHP_BIN" artisan view:clear   2>/dev/null || c_yellow "  (view:clear skipped)"
sudo -u "$WEB_USER" "$PHP_BIN" artisan route:clear  2>/dev/null || c_yellow "  (route:clear skipped)"
sudo -u "$WEB_USER" "$PHP_BIN" artisan event:clear  2>/dev/null || c_yellow "  (event:clear skipped)"

# Hard-remove any cached artifacts that could pin a stale path.
rm -f bootstrap/cache/config.php       bootstrap/cache/services.php \
      bootstrap/cache/packages.php     bootstrap/cache/events.php
rm -f bootstrap/cache/routes-v7.php    bootstrap/cache/routes-v8.php
rm -rf storage/framework/views/*

c_green "✔ Caches cleared."

# ----- 5. MIGRATIONS --------------------------------------------------------
step "5/6 · Migrations"

if ask_yn "Run 'php artisan migrate --force' now?" "y"; then
  sudo -u "$WEB_USER" "$PHP_BIN" artisan migrate --force --ansi
  c_green "✔ Migrations applied."
else
  c_yellow "→ Skipped migrations on operator request."
fi

# ----- 6. PROBE -------------------------------------------------------------
step "6/6 · Live probe"

sudo -u "$WEB_USER" "$PHP_BIN" artisan about --ansi 2>/dev/null | head -40 || true

echo
if command -v curl >/dev/null; then
  HEALTH_URL="${APP_URL_OVERRIDE:-http://127.0.0.1/up}"
  c_blue "Health probe → $HEALTH_URL"
  if curl -fsS -o /dev/null -w "  HTTP %{http_code}\n" --max-time 5 "$HEALTH_URL"; then
    c_green "✔ Local /up returned 200."
  else
    c_yellow "  (non-200 — that's fine if Nginx is fronted by HTTPS only; test the public URL externally.)"
  fi
fi

# ----- DONE -----------------------------------------------------------------
echo
hr
c_green "ALL DONE."
hr
echo "  • DB:       ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo "  • Web user: ${WEB_USER}"
echo "  • App dir:  ${APP_ROOT}"
echo "  • .env:     ${ENV_FILE}"
echo
c_yellow "If you still see 'Please provide a valid cache path' after this:"
echo "  1. Run this script again — sometimes a previous half-failed deploy"
echo "     left files owned by root in storage/. Re-running re-chowns."
echo "  2. Reload PHP-FPM:    sudo systemctl reload php8.3-fpm   (or your version)"
echo "  3. Reload the web server: sudo systemctl reload nginx    (or apache2)"
echo "  4. If you use Octane:  sudo -u ${WEB_USER} ${PHP_BIN} artisan octane:reload"
echo
