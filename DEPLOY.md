# Deployment — Auto-pull mechanism

This repo deploys to three places, all using the **same scripts**:

| Host                       | Role                          | Reload mechanism |
| -------------------------- | ----------------------------- | ---------------- |
| `con-dev2`                 | Staging — `ug-poe.ecsahc.com` | Laravel **Octane** (long-running PHP) |
| `poes.health.go.ug`        | Production — Uganda MOH       | Classic **PHP-FPM** (per-request) |
| Rwanda government host      | Production — Rwanda           | Classic **PHP-FPM** |

The deploy story is:

```
  ┌────────────────────┐
  │  Developer / WSL   │
  └─────────┬──────────┘
            │ git push origin main
            ▼
  ┌────────────────────┐
  │   GitHub (origin)  │
  └─────────┬──────────┘
            │ pulled every 3 min by cron
            ▼  (or instant via manual `git pull`)
  ┌────────────────────┐     ┌──────────────────────────┐
  │  Government server │ ──► │ scripts/deploy-pull.sh    │
  └────────────────────┘     │  • composer install      │
                             │  • npm ci + npm run build│
                             │  • artisan caches        │
                             │  • migrations (if any)   │
                             │  • octane:reload OR      │
                             │    systemctl reload fpm  │
                             └──────────────────────────┘
```

No human intervention after the initial `install-deploy-hooks.sh`.

---

## One-time setup (per server)

```bash
# 1. Clone the repo (or skip if it's already there)
sudo mkdir -p /var/www && sudo chown $USER:$USER /var/www
cd /var/www
git clone https://github.com/vexa256/uganda-moh-poe-dashboard.git ug-poe
cd ug-poe

# 2. Install dependencies once
( cd api && composer install --no-dev --optimize-autoloader )
npm ci && npm run build

# 3. Configure api/.env (DB, APP_KEY, etc.) — copy from secure storage

# 4. Install the deploy hooks (idempotent — safe to re-run)
./scripts/install-deploy-hooks.sh

# 5. Install the auto-pull cron (every 3 minutes)
( crontab -l 2>/dev/null | grep -v auto-pull ; \
  echo '*/3 * * * * cd /var/www/ug-poe && ./scripts/auto-pull.sh' ) | crontab -

# 6. (PHP-FPM hosts only) give the deploy user passwordless reload
echo "$(whoami) ALL=NOPASSWD: /bin/systemctl reload php8.3-fpm" \
  | sudo tee /etc/sudoers.d/poe-deploy
sudo chmod 0440 /etc/sudoers.d/poe-deploy
```

---

## Daily flow

### Developer side (you, on WSL or con-dev2)

```bash
# Make changes
git add -A
git commit -m "fix(something): ..."
git push origin main
```

That's it. Every server picks up the change within 3 minutes.

### Government servers (no human action needed)

The `auto-pull.sh` cron runs every 3 minutes. When it sees the remote is
ahead of local HEAD, it pulls, then runs `deploy-pull.sh`. Logs:

```
tail -f /var/log/poe-auto-pull.log    # every pull attempt, success or skip
tail -f /var/log/poe-deploy.log       # what deploy-pull.sh actually did
```

### Manual emergency deploy

```bash
ssh poes.health.go.ug
cd /var/www/ug-poe
git pull                                 # post-merge hook fires deploy-pull.sh
# OR force a rebuild even if no commits changed:
./scripts/deploy-pull.sh --force
```

---

## What `deploy-pull.sh` actually does

It diffs `HEAD@{1}..HEAD` (i.e. what changed in this pull) and runs the
minimum set of steps:

| Trigger                                              | Action                                             |
| ---------------------------------------------------- | -------------------------------------------------- |
| `api/composer.lock` or `api/composer.json` changed   | `composer install --no-dev --optimize-autoloader`  |
| `package-lock.json` or `package.json` changed        | `npm ci` (falls back to `npm install`)             |
| Anything under `src/`, `public/`, `index.html`, `vite.config.ts`, or npm deps changed | `npm run build`     |
| `api/database/migrations/` changed                   | `php artisan migrate --force`                      |
| Always (when `APP_ENV=production`)                   | `config:clear → config:cache → route:cache → view:cache` |
| Always                                                | reload PHP — `octane:reload` if Octane is running, else `systemctl reload php-fpm` |
| `capacitor.config.ts` or native deps changed         | `npx cap sync android` (build host only)           |

The diff detection means a docs-only commit triggers zero rebuilds.
`--force` overrides the detection and rebuilds everything.

`--dry-run` prints what it would do without touching anything — useful
for sanity-checking on production before a risky merge.

---

## Edge cases handled by `auto-pull.sh`

| Case                                          | Behavior                                                            |
| --------------------------------------------- | ------------------------------------------------------------------- |
| Two cron ticks racing                          | `flock` guard — second tick exits silently                          |
| Network blip on `git fetch`                    | logs WARN, exits 0, retries next tick                               |
| Dirty working tree (someone hand-edited)       | refuses to pull, logs ERROR — operator must resolve                  |
| Non-fast-forward (someone committed locally)   | refuses to merge, logs ERROR — never auto-rebases                    |
| Up to date                                     | silent exit 0 — no log spam                                          |
| Deploy script missing or non-executable        | logs ERROR exit 1                                                    |
| Migration failure                              | deploy continues but logs ERROR — manual investigation required      |

---

## What about the mobile app?

The Capacitor app's JS/HTML/CSS bundle ships **via OTA**, not via this
pipeline. After a `git pull` rebuilds `dist/`, you publish it to the
self-hosted Capgo backend:

```bash
capgo publish ug.moh.poesentinel production <version> ./dist
```

See `~/.cloud/CAPGO-RUNBOOK.md` for the OTA story. The two pipelines are
intentionally independent — backend changes don't force an APK rebuild,
and frontend changes don't force a server restart.

---

## Rollback

```bash
# Server-side: revert to a known-good commit and let auto-pull pick it up
git revert <bad-sha>
git push origin main
# OR force a previous commit
git reset --hard <good-sha>
git push --force-with-lease origin main   # USE WITH CARE
```

For frontend rollback specifically (no native code involved), prefer:

```bash
capgo rollback ug.moh.poesentinel production
```

— that's instant and doesn't touch the backend.

---

## Health monitoring

The auto-pull cron is a silent no-op when everything is up to date. To
confirm it's still alive, check the last "behind by ..." line in
`/var/log/poe-auto-pull.log` — should be within the last week if you've
deployed anything recently.

For continuous monitoring, the `/up` Laravel health endpoint reports the
current commit SHA via response headers (set in `bootstrap/app.php` if
you extend it). Pair with your existing uptime monitor.

<!-- deploy-pipeline acceptance test: 2026-05-30T23:53:07+00:00 -->
