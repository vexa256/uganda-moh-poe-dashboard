#!/usr/bin/env bash
# Idempotent permissions enforcer for the Uganda POE platform.
# Run as root (sudo). Safe to re-run after every deploy.
#
# Purpose: prepare a server so that DeployWatch (Octane worker running as
# www-data) can autonomously run `git pull` → `php artisan migrate --force`
# → `php artisan octane:reload` with ZERO manual steps. After this script
# runs once per server, every subsequent commit on origin/main lands
# automatically within one DeployWatch poll cycle (~120 s).
#
# Coverage as of 2026-05-20:
#   - Web user (www-data) ownership of the entire working tree (so git
#     pull can unlink/replace any file).
#   - setgid + g+w on every directory (so newly created files inherit
#     www-data group + are group-writable).
#   - .git is included (DeployWatch needs to fetch + write FETCH_HEAD).
#   - storage + bootstrap/cache get ACLs for both deploy + web user.
#   - .env stays 0640 owner+group only.

set -euo pipefail

APP="${APP_ROOT:-/var/www/poes.health.go.ug}"
WEB_USER="${WEB_USER:-www-data}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"

WRITE_DIRS=(
  "$APP/api/storage"
  "$APP/api/bootstrap/cache"
  # 2026-05-20: .git must be writable by www-data so DeployWatch (run by
  # the Octane worker pool) can `git fetch` / `git pull` autonomously. If
  # this is missing, deploy-watch.log fills with "cannot open .git/FETCH_HEAD:
  # Permission denied" every 2 minutes and the server silently drifts
  # behind origin (con-dev2 incident, 2026-05-20).
  "$APP/.git"
)

if [ ! -d "$APP" ]; then
  echo "fix-perms: $APP does not exist" >&2
  exit 1
fi

# 0) Resolve DEPLOY_USER. Fall back to the directory's current owner if the
#    configured deploy user does not exist on this host (con-dev2 uses
#    'ubuntu', some MoH hosts use 'admin' or similar). Never error out — the
#    important invariant is the GROUP, not the owning user.
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
  DEPLOY_USER="$(stat -c '%U' "$APP")"
  echo "fix-perms: configured deploy user not found — using '$DEPLOY_USER' (current owner of $APP)" >&2
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
