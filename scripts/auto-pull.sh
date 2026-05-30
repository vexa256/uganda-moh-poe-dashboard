#!/usr/bin/env bash
# scripts/auto-pull.sh — cron-callable continuous-deploy poller.
#
# Designed to run every 1-5 minutes from cron on government servers
# (poes.health.go.ug for Uganda, the Rwanda host, etc.):
#
#   */3 * * * * cd /var/www/ug-poe && ./scripts/auto-pull.sh >> /var/log/poe-auto-pull.log 2>&1
#
# Behavior:
#   1. git fetch origin (no merge)
#   2. compare local HEAD vs origin/<branch>
#   3. if behind: git pull --ff-only, then run deploy-pull.sh
#   4. exit 0 (silent) when nothing to do
#
# Edge cases handled:
#   - concurrent runs: flock guard prevents two cron ticks racing
#   - non-fast-forward: refuses to merge (logs WARN, leaves repo untouched)
#   - dirty working tree (someone hand-edited): refuses to pull, logs ERROR
#   - network failure on fetch: logs WARN, exits 0 (try again next tick)
#   - deploy-pull.sh missing or non-exec: logs ERROR
#
# Exit codes:
#   0 — nothing to do, or successfully deployed
#   1 — would have deployed but something blocked it (see log)
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

LOG_FILE="/var/log/poe-auto-pull.log"
[ -w "$(dirname "$LOG_FILE")" ] || LOG_FILE="$REPO_ROOT/storage/logs/poe-auto-pull.log"
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || LOG_FILE=/tmp/poe-auto-pull.log

log() { printf '%s [%s] %s\n' "$(date -Iseconds)" "$1" "$2" >> "$LOG_FILE"; }

# Single-instance lock (skip silently if a previous run is still going).
LOCK="/tmp/.poe-auto-pull.$(echo "$REPO_ROOT" | md5sum | cut -c1-12).lock"
exec 9>"$LOCK"
flock -n 9 || { log INFO "another run in progress — skipping"; exit 0; }

BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)"

# 1. fetch
if ! git fetch origin "$BRANCH" --quiet 2>/dev/null; then
  log WARN "git fetch failed (network?) — will retry next tick"
  exit 0
fi

LOCAL_SHA="$(git rev-parse HEAD)"
REMOTE_SHA="$(git rev-parse "origin/$BRANCH" 2>/dev/null || echo "")"

if [ -z "$REMOTE_SHA" ]; then
  log ERROR "origin/$BRANCH does not exist on remote"
  exit 1
fi
if [ "$LOCAL_SHA" = "$REMOTE_SHA" ]; then
  # Up to date — silent (don't spam the log every minute)
  exit 0
fi

# 2. precondition: clean working tree
if ! git diff --quiet || ! git diff --cached --quiet; then
  log ERROR "working tree is dirty — refusing to pull. Resolve hand edits first."
  git status --short >> "$LOG_FILE" 2>&1
  exit 1
fi

# 3. precondition: ff-only is achievable
if ! git merge-base --is-ancestor "$LOCAL_SHA" "$REMOTE_SHA"; then
  log ERROR "local commits not on remote — refusing ff-only pull (someone committed locally?)"
  log ERROR "local=$LOCAL_SHA  remote=$REMOTE_SHA"
  exit 1
fi

# 4. pull
log INFO "behind by $(git rev-list --count "$LOCAL_SHA..$REMOTE_SHA") commit(s) — pulling"
if ! git pull origin "$BRANCH" --ff-only --quiet 2>>"$LOG_FILE"; then
  log ERROR "git pull failed"
  exit 1
fi
NEW_SHA="$(git rev-parse HEAD)"
log INFO "pulled  $LOCAL_SHA → $NEW_SHA"

# 5. deploy
DEPLOY="$REPO_ROOT/scripts/deploy-pull.sh"
if [ ! -x "$DEPLOY" ]; then
  log ERROR "deploy script missing or not executable: $DEPLOY"
  exit 1
fi
if ! "$DEPLOY"; then
  log ERROR "deploy-pull.sh exited non-zero — investigate /var/log/poe-deploy.log"
  exit 1
fi
log INFO "deploy complete at $NEW_SHA"
