#!/usr/bin/env bash
# scripts/install-deploy-hooks.sh — one-shot installer for the deploy hooks.
#
# Run this ONCE per server checkout. It:
#   1. Installs a git post-merge hook so manual `git pull` triggers deploy
#   2. Marks deploy-pull.sh + auto-pull.sh executable
#   3. Prints the cron line to install for continuous deployment
#
# Idempotent — safe to re-run.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# 1. Make scripts executable
chmod +x scripts/deploy-pull.sh scripts/auto-pull.sh scripts/install-deploy-hooks.sh

# 2. Install post-merge hook (git pull → auto-deploy)
HOOK_DIR="$(git rev-parse --git-path hooks)"
HOOK_FILE="$HOOK_DIR/post-merge"
cat > "$HOOK_FILE" <<'HOOK'
#!/usr/bin/env bash
# Auto-installed by scripts/install-deploy-hooks.sh
# Runs after `git pull` or `git merge` succeeds.
REPO="$(git rev-parse --show-toplevel)"
if [ -x "$REPO/scripts/deploy-pull.sh" ]; then
  echo "[post-merge] running deploy-pull.sh..."
  "$REPO/scripts/deploy-pull.sh"
fi
HOOK
chmod +x "$HOOK_FILE"
echo "  installed git hook: $HOOK_FILE"

# 3. Print cron line
echo
echo "─── Hooks installed ─────────────────────────────────────────────────"
echo
echo "✅ Manual deploy: just run \`git pull\` — the post-merge hook runs deploy-pull.sh."
echo
echo "For CONTINUOUS auto-deploy (every 3 minutes), install this cron line"
echo "as the user that owns the checkout:"
echo
echo "  ( crontab -l 2>/dev/null | grep -v auto-pull ; echo '*/3 * * * * cd $REPO_ROOT && ./scripts/auto-pull.sh' ) | crontab -"
echo
echo "Logs land at /var/log/poe-auto-pull.log and /var/log/poe-deploy.log"
echo "(or storage/logs/ if /var/log is not writable)."
echo
echo "If this server runs php-fpm under www-data, grant the deploy user"
echo "passwordless reload via sudoers:"
echo
echo "  echo \"$(whoami) ALL=NOPASSWD: /bin/systemctl reload php8.3-fpm\" | sudo tee /etc/sudoers.d/poe-deploy"
echo
echo "─────────────────────────────────────────────────────────────────────"
