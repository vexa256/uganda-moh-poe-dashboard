#!/usr/bin/env bash
# Idempotent permissions enforcer for the Uganda POE platform.
# Run as root (sudo). Safe to re-run after every deploy.

set -euo pipefail

APP="${APP_ROOT:-/var/www/poes.health.go.ug}"
WEB_USER="${WEB_USER:-www-data}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"

WRITE_DIRS=(
  "$APP/api/storage"
  "$APP/api/bootstrap/cache"
)

if [ ! -d "$APP" ]; then
  echo "fix-perms: $APP does not exist" >&2
  exit 1
fi

# 1) Ownership: deploy owns the code; web user is the group everywhere.
chown -R "$DEPLOY_USER:$WEB_USER" "$APP"

# 2) Base file/dir modes: dirs 2775 (setgid), files 0664.
find "$APP" -type d -exec chmod 2775 {} +
find "$APP" -type f -exec chmod 0664 {} +

# 3) ACLs on writable dirs — covers BOTH current and future files.
if command -v setfacl >/dev/null 2>&1; then
  for d in "${WRITE_DIRS[@]}"; do
    mkdir -p "$d"
    setfacl -R  -m "u:${WEB_USER}:rwX"   -m "u:${DEPLOY_USER}:rwX" "$d"
    setfacl -dR -m "u:${WEB_USER}:rwX"   -m "u:${DEPLOY_USER}:rwX" "$d"
  done
else
  echo "fix-perms: setfacl not available — falling back to chmod 0775" >&2
  for d in "${WRITE_DIRS[@]}"; do
    mkdir -p "$d"
    chmod -R 0775 "$d"
  done
fi

# 4) Executables.
[ -f "$APP/api/artisan" ] && chmod +x "$APP/api/artisan"
[ -d "$APP/api/vendor/bin" ] && find "$APP/api/vendor/bin" -type f -exec chmod +x {} +

# 5) .env is sensitive — owner+group only.
if [ -f "$APP/api/.env" ]; then
  chown "$DEPLOY_USER:$WEB_USER" "$APP/api/.env"
  chmod 0640 "$APP/api/.env"
fi

# 6) SELinux (RHEL/Rocky) — best-effort, no-op elsewhere.
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" != "Disabled" ]; then
  command -v setsebool >/dev/null 2>&1 && setsebool -P httpd_can_network_connect 1 || true
  command -v chcon     >/dev/null 2>&1 && chcon -R -t httpd_sys_rw_content_t "$APP/api/storage" "$APP/api/bootstrap/cache" || true
fi

echo "fix-perms: OK ($APP)"
