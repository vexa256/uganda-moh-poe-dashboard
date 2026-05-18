# Uganda MoH · Point-of-Entry Health Surveillance — Deployment Guide

Production deployment of the Uganda Ministry of Health POE Health Surveillance
platform at **https://poes.health.go.ug/**.

This repository contains both:

| Component       | Path     | Purpose                                                     |
| --------------- | -------- | ----------------------------------------------------------- |
| Web Admin / API | `api/`   | Laravel 11 — REST API + server-rendered admin command centre |
| Mobile / Field  | `src/`   | Ionic + Vue 3 PWA / Capacitor Android app for POE screeners |
| DB seed dump    | `database/seed-dump/ecsa_uganda_2026.sql` | Initial production schema + reference data |

> ⚠️ **Octane and OPcache are intentionally NOT enabled in the current rollout.**
> They are documented at the bottom of this file and must remain disabled
> until all major feature updates have shipped and the platform is stable in
> production. Re-read the **Deferred Optimizations** section before enabling.

---

## 0. Target topologies

The same codebase supports three deployment shapes. Pick one before you start.

| Topology     | When to use                                          | Hosts                                                            |
| ------------ | ---------------------------------------------------- | ---------------------------------------------------------------- |
| **Single**   | Pilot / staging / small POE                          | 1 host: nginx + php-fpm + mysql + redis + queue worker           |
| **Split**   | National production (recommended)                    | App host(s) + dedicated MySQL + dedicated Redis                  |
| **HA**       | Multi-region / fail-over                             | ≥ 2 app hosts behind a load balancer + replicated MySQL + Redis Sentinel |

The commands below are written for the **Single** and **Split** topologies on
**Ubuntu 22.04 LTS / 24.04 LTS** (the canonical government build). Notes are
inlined for **Debian 12** and **RHEL 9 / Rocky 9 / AlmaLinux 9** where they
differ.

---

## 1. Operating-system prerequisites

```bash
# ─── Ubuntu / Debian ────────────────────────────────────────────────────
sudo apt update && sudo apt -y upgrade
sudo apt -y install \
  curl git unzip zip jq acl ufw fail2ban software-properties-common \
  ca-certificates gnupg lsb-release \
  nginx \
  mariadb-client \
  redis-tools \
  certbot python3-certbot-nginx

# ─── RHEL / Rocky / AlmaLinux ───────────────────────────────────────────
# sudo dnf -y install epel-release && sudo dnf -y upgrade
# sudo dnf -y install curl git unzip zip jq acl firewalld fail2ban \
#   nginx mariadb redis certbot python3-certbot-nginx
```

Lock the firewall to only the ports we need:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp        # SSH (restrict to admin CIDR if possible)
sudo ufw allow 80/tcp        # HTTP (Certbot challenge + redirect)
sudo ufw allow 443/tcp       # HTTPS
sudo ufw --force enable
```

Set the timezone (audit logs and SLA scans depend on this):

```bash
sudo timedatectl set-timezone Africa/Kampala
```

---

## 2. PHP 8.3 install (PHP-FPM, no Octane, no OPcache yet)

```bash
# Ubuntu — Ondřej Surý's PPA (officially maintained, gov-approved)
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt -y install \
  php8.3 php8.3-cli php8.3-fpm \
  php8.3-mysql php8.3-redis \
  php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd \
  php8.3-bcmath php8.3-intl php8.3-curl php8.3-soap \
  php8.3-readline php8.3-imagick php8.3-gmp
```

> ❌ **Do NOT** install or enable `php8.3-opcache` at this stage. We will turn
> it on later (see **Deferred Optimizations**) once all major updates are out.

### 2.1 PHP-FPM pool tuning (no OPcache, no Octane)

Drop a custom pool override at `/etc/php/8.3/fpm/pool.d/poes.conf`:

```ini
[poes]
user  = www-data
group = www-data
listen = /run/php/poes.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

pm                   = dynamic
pm.max_children      = 64
pm.start_servers     = 12
pm.min_spare_servers = 8
pm.max_spare_servers = 24
pm.max_requests      = 1000
pm.process_idle_timeout = 30s

request_terminate_timeout = 90s
request_slowlog_timeout   = 5s
slowlog                   = /var/log/php8.3-fpm.slow.log

php_admin_value[memory_limit]      = 512M
php_admin_value[post_max_size]     = 64M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[max_execution_time] = 90
php_admin_value[max_input_vars]    = 5000
php_admin_value[date.timezone]     = Africa/Kampala
php_admin_value[session.cookie_secure]   = 1
php_admin_value[session.cookie_httponly] = 1
php_admin_value[session.cookie_samesite] = Lax
```

`php.ini` (`/etc/php/8.3/fpm/php.ini`) — performance-relevant overrides
**without** OPcache:

```ini
realpath_cache_size = 4096k
realpath_cache_ttl  = 600
max_input_time      = 90
serialize_precision = -1
expose_php          = Off
```

Restart and verify:

```bash
sudo systemctl enable --now php8.3-fpm
sudo systemctl restart php8.3-fpm
sudo systemctl status  php8.3-fpm --no-pager
php -m | grep -Ei 'redis|mysqlnd|mbstring|intl|bcmath|gd|zip|opcache'
# OPcache MUST NOT appear in that grep yet.
```

### 2.2 Composer

```bash
curl -sS https://getcomposer.org/installer | sudo php -- \
  --install-dir=/usr/local/bin --filename=composer
composer --version
```

---

## 3. MySQL / MariaDB — server + database + user

The platform has been validated against **MySQL 8.0** and **MariaDB 10.11+**.
Both work. Use whichever your infra team prefers.

### 3.1 Install server (Single topology)

```bash
# Ubuntu — MySQL 8 (Oracle build)
sudo apt -y install mysql-server

# OR MariaDB 10.11
# sudo apt -y install mariadb-server
sudo mysql_secure_installation
sudo systemctl enable --now mysql      # or mariadb
```

### 3.2 Create the application database and user

Run **as MySQL root**. Replace the placeholders before pasting.

```bash
DB_NAME="poes_health"
DB_USER="poes_app"
DB_PASS="$(openssl rand -base64 28 | tr -d '=+/' | cut -c1-24)"
echo "Generated DB password: $DB_PASS   <-- record this for api/.env"

sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Localhost user (app on same host)
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
-- Remote-app-host user (Split / HA topology) — restrict to your app subnet
CREATE USER IF NOT EXISTS '${DB_USER}'@'10.%' IDENTIFIED BY '${DB_PASS}';

-- Full DML + DDL on this database only. CREATE/ALTER/DROP/INDEX/REFERENCES
-- are required so Laravel migrations, foreign keys, and triggers all work.
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'10.%';

-- The application uses stored procedures + triggers + events in places
-- (alerts SLA scanner, weekly digest). Make sure SUPER-free equivalents
-- are granted at the database level:
GRANT CREATE, ALTER, INDEX, DROP, REFERENCES,
      CREATE ROUTINE, ALTER ROUTINE, EXECUTE,
      CREATE VIEW, SHOW VIEW,
      TRIGGER, EVENT,
      LOCK TABLES
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT CREATE, ALTER, INDEX, DROP, REFERENCES,
      CREATE ROUTINE, ALTER ROUTINE, EXECUTE,
      CREATE VIEW, SHOW VIEW,
      TRIGGER, EVENT,
      LOCK TABLES
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'10.%';

FLUSH PRIVILEGES;
SQL
```

For a **dedicated DB host (Split topology)**, edit
`/etc/mysql/mysql.conf.d/mysqld.cnf` (Ubuntu) or
`/etc/my.cnf.d/server.cnf` (RHEL) and set:

```ini
[mysqld]
bind-address      = 0.0.0.0
default_authentication_plugin = caching_sha2_password
max_connections   = 400
innodb_buffer_pool_size = 4G        # ≈ 50–70% of host RAM
innodb_flush_log_at_trx_commit = 1
innodb_flush_method = O_DIRECT
innodb_io_capacity  = 2000
innodb_redo_log_capacity = 2G       # MySQL 8.0.30+
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci
sql_mode = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
```

Restart and verify:

```bash
sudo systemctl restart mysql
mysql -u "$DB_USER" -p"$DB_PASS" -h 127.0.0.1 -e "SELECT VERSION(), DATABASE();" "$DB_NAME"
```

### 3.3 Import the seed dump

The repo ships with a sanitized seed dump at
`database/seed-dump/ecsa_uganda_2026.sql`. Import it into the database you
just created:

```bash
# From the project root, on the DB host (or anywhere the host is reachable)
mysql -u poes_app -p -h 127.0.0.1 poes_health \
  < database/seed-dump/ecsa_uganda_2026.sql

# If the dump's CREATE DATABASE statement targets a different name,
# import without selecting a DB first:
mysql -u root -p < database/seed-dump/ecsa_uganda_2026.sql
```

> The dump uses `--add-drop-database` so re-importing is idempotent and will
> rebuild the schema cleanly on a fresh host.

---

## 4. Redis — cache, sessions, queue, locks

Single-host install:

```bash
sudo apt -y install redis-server
sudo systemctl enable --now redis-server
redis-cli ping        # → PONG
```

Edit `/etc/redis/redis.conf` (Ubuntu) and apply these production-relevant
settings:

```conf
bind 127.0.0.1 -::1            # Single host. For Split/HA, listen on app subnet only.
protected-mode yes
requirepass <generate-32-byte-secret>
maxmemory 2gb                  # raise to 25–40% of host RAM on dedicated Redis hosts
maxmemory-policy allkeys-lru   # safe for Laravel cache + sessions
appendonly yes                 # required for queues — durable
appendfsync everysec
tcp-backlog 511
timeout 0
tcp-keepalive 300
io-threads 4                   # only if host has ≥ 4 cores
```

```bash
sudo systemctl restart redis-server
redis-cli -a '<your-redis-password>' ping     # → PONG
```

The application uses Redis for **four** logical channels (all behind the
same instance is fine; split DB indices keep them isolated):

| Channel  | Laravel ENV                  | Redis DB |
| -------- | ---------------------------- | -------- |
| Cache    | `CACHE_STORE=redis`          | `0`      |
| Sessions | `SESSION_DRIVER=redis`       | `1`      |
| Queue    | `QUEUE_CONNECTION=redis`     | `2`      |
| Locks    | `CACHE_LOCK_CONNECTION=redis`| `3`      |

---

## 5. Application users, paths, and permissions (low-level, fail-proof)

This is the most common source of production outages. The recipe below uses
**ACLs (`setfacl`)** so that even if a future deploy is done by a different
human user, web-server and worker writes stay correct.

### 5.1 Create the deployment user and lay out paths

```bash
# Deploy user — owns the codebase, performs releases. NOT www-data.
sudo useradd -r -m -d /var/www -s /bin/bash deploy 2>/dev/null || true
sudo usermod -aG www-data deploy

# Canonical paths
sudo mkdir -p /var/www/poes.health.go.ug
sudo chown deploy:www-data /var/www/poes.health.go.ug
sudo chmod 2775 /var/www/poes.health.go.ug    # 2 = setgid: new files inherit www-data group
```

### 5.2 Clone the repository as the deploy user

```bash
sudo -u deploy -H bash -lc '
  cd /var/www/poes.health.go.ug
  git clone https://github.com/vexa256/uganda-moh-poe-dashboard.git .
'
```

### 5.3 Bullet-proof permissions with ACLs

Run this once after every release. It is **idempotent** and will not fail on
already-correct trees.

```bash
APP=/var/www/poes.health.go.ug
WRITE_DIRS=(
  "$APP/api/storage"
  "$APP/api/bootstrap/cache"
)

# 1) Ownership: deploy owns the code; www-data is the group everywhere.
sudo chown -R deploy:www-data "$APP"

# 2) Base file/dir modes: dirs 2775 (setgid), files 0664.
sudo find "$APP" -type d -exec chmod 2775 {} +
sudo find "$APP" -type f -exec chmod 0664 {} +

# 3) ACLs on writable dirs — guarantees BOTH current and FUTURE files
#    are writable by deploy AND www-data, no matter who creates them.
for d in "${WRITE_DIRS[@]}"; do
  sudo mkdir -p "$d"
  sudo setfacl -R  -m u:www-data:rwX -m u:deploy:rwX "$d"
  sudo setfacl -dR -m u:www-data:rwX -m u:deploy:rwX "$d"
done

# 4) Executables in vendor/bin and artisan stay executable.
sudo chmod +x "$APP/api/artisan"
sudo find "$APP/api/vendor/bin" -type f -exec chmod +x {} + 2>/dev/null || true

# 5) .env is sensitive — owner-only.
[ -f "$APP/api/.env" ] && sudo chmod 0640 "$APP/api/.env" && sudo chown deploy:www-data "$APP/api/.env"
```

> **Why this can't fail on permissions:** `setgid` on directories pins the
> group to `www-data`. ACLs (`-d`) set the *default* ACL, so any file created
> later — by a queue worker, by `artisan`, by `php-fpm`, by a one-off cron —
> is automatically writable by both `deploy` and `www-data`. SELinux hosts
> (RHEL) additionally need: `sudo setsebool -P httpd_can_network_connect 1`
> and `sudo chcon -R -t httpd_sys_rw_content_t $APP/api/storage`.

---

## 6. Application install & boot

```bash
cd /var/www/poes.health.go.ug

# 6.1 API (Laravel)
sudo -u deploy -H bash -lc '
  cd api
  cp .env.example .env
  # edit .env (see § 6.2)
'

# 6.2 .env — required values
sudo -u deploy nano /var/www/poes.health.go.ug/api/.env
```

Minimum `.env` for production:

```ini
APP_NAME="Uganda POE Health Surveillance"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://poes.health.go.ug
APP_TIMEZONE=Africa/Kampala
APP_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1         # or the private IP of your DB host
DB_PORT=3306
DB_DATABASE=poes_health
DB_USERNAME=poes_app
DB_PASSWORD=<from § 3.2>

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=240
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=<from § 4>
REDIS_DB=0
REDIS_CACHE_DB=0

MAIL_MAILER=smtp
MAIL_HOST=<gov-relay>
MAIL_PORT=587
MAIL_USERNAME=<smtp-user>
MAIL_PASSWORD=<smtp-pass>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@poes.health.go.ug"
MAIL_FROM_NAME="${APP_NAME}"

TRUSTED_PROXIES=*           # tighten to LB CIDR in HA
```

```bash
# 6.3 Install PHP deps, generate app key, link storage, import schema
sudo -u deploy -H bash -lc '
  cd /var/www/poes.health.go.ug/api
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  php artisan key:generate --force
  php artisan storage:link
  # Schema + reference data:
  mysql -u poes_app -p"$(grep ^DB_PASSWORD .env | cut -d= -f2)" \
        -h "$(grep ^DB_HOST .env | cut -d= -f2)" \
        poes_health < ../database/seed-dump/ecsa_uganda_2026.sql
  # OR, on a fresh DB, run migrations + seeders instead:
  # php artisan migrate --force --seed
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan event:cache
'
```

> **Note on caches:** `config:cache`, `route:cache`, `view:cache`,
> `event:cache` are the four caches we use today. We intentionally **do not**
> run `php artisan optimize` (which would also warm OPcache) until OPcache is
> turned on in the deferred-optimizations phase.

### 6.4 Front-end / mobile PWA build

The mobile UI is also served as a PWA at `/app` (configurable). Build it once
per release on the same host or in CI:

```bash
sudo -u deploy -H bash -lc '
  cd /var/www/poes.health.go.ug
  # Node 20 LTS (via NodeSource) — install once on the build host
  # curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
  # sudo apt -y install nodejs
  npm ci
  npm run build      # outputs to dist/
'
```

Re-apply ACLs on the new build artifacts:

```bash
sudo setfacl -R -m u:www-data:rX  /var/www/poes.health.go.ug/dist
```

---

## 7. Nginx — single virtual host, HTTPS, both API and PWA

`/etc/nginx/sites-available/poes.health.go.ug`:

```nginx
# ── HTTP → HTTPS redirect ────────────────────────────────────────────────
server {
    listen 80;
    listen [::]:80;
    server_name poes.health.go.ug;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$host$request_uri; }
}

# ── HTTPS ────────────────────────────────────────────────────────────────
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name poes.health.go.ug;

    ssl_certificate     /etc/letsencrypt/live/poes.health.go.ug/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/poes.health.go.ug/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    # Security headers
    add_header X-Frame-Options DENY always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    client_max_body_size 64m;
    client_body_timeout  90s;
    send_timeout         90s;

    # ── Laravel API + admin (server-rendered) ───────────────────────────
    root  /var/www/poes.health.go.ug/api/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/poes.sock;
        fastcgi_read_timeout 90s;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_param HTTPS on;
    }

    # ── PWA / mobile dashboard — served from /var/www/.../dist ─────────
    location /app/ {
        alias /var/www/poes.health.go.ug/dist/;
        try_files $uri $uri/ /app/index.html;
        add_header Cache-Control "no-cache, must-revalidate" always;
    }

    # Static assets — long-cache
    location ~* \.(js|css|woff2?|svg|png|jpg|gif|ico|webp)$ {
        expires 7d;
        access_log off;
        add_header Cache-Control "public, max-age=604800, immutable";
    }

    # Deny dotfiles
    location ~ /\.(?!well-known) { deny all; }
}
```

Enable and test:

```bash
sudo mkdir -p /var/www/letsencrypt
sudo ln -sf /etc/nginx/sites-available/poes.health.go.ug /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d poes.health.go.ug --redirect --agree-tos -m ops@health.go.ug
sudo systemctl reload nginx
```

---

## 8. Background workers and scheduler (systemd)

### 8.1 Queue workers

`/etc/systemd/system/poes-queue@.service`:

```ini
[Unit]
Description=Uganda POE queue worker %i
After=network.target redis-server.service mysql.service

[Service]
Type=simple
User=deploy
Group=www-data
WorkingDirectory=/var/www/poes.health.go.ug/api
ExecStart=/usr/bin/php artisan queue:work redis --queue=high,default,low --sleep=1 --tries=3 --max-time=3600 --max-jobs=1000
Restart=always
RestartSec=3
StandardOutput=append:/var/log/poes/queue-%i.log
StandardError=append:/var/log/poes/queue-%i.err
Nice=5
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

```bash
sudo mkdir -p /var/log/poes && sudo chown deploy:www-data /var/log/poes && sudo chmod 2775 /var/log/poes
sudo systemctl daemon-reload
# Run 4 workers; adjust to host capacity.
for n in 1 2 3 4; do sudo systemctl enable --now "poes-queue@$n"; done
```

### 8.2 Laravel scheduler

`/etc/systemd/system/poes-scheduler.service`:

```ini
[Unit]
Description=Uganda POE Laravel scheduler
After=network.target

[Service]
Type=oneshot
User=deploy
Group=www-data
WorkingDirectory=/var/www/poes.health.go.ug/api
ExecStart=/usr/bin/php artisan schedule:run
```

`/etc/systemd/system/poes-scheduler.timer`:

```ini
[Unit]
Description=Run Uganda POE Laravel scheduler every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now poes-scheduler.timer
sudo systemctl list-timers --all | grep poes
```

### 8.3 Horizon (optional, drop-in replacement for raw queue workers)

If the codebase ships with Laravel Horizon, swap the `poes-queue@.service`
units for a single `poes-horizon.service` that runs `php artisan horizon`.
Both styles are supported; pick one, not both.

---

## 9. Release flow (everyday deploys)

Run **on the app host(s)** as the `deploy` user. Idempotent and safe to
re-run.

```bash
sudo -u deploy -H bash -lc '
  set -euo pipefail
  cd /var/www/poes.health.go.ug

  # Pull the new release
  git fetch --all --prune
  git reset --hard origin/main

  cd api
  php artisan down --retry=10 --secret="$(openssl rand -hex 16 | tee /tmp/poes-deploy-secret)"
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  php artisan migrate --force          # no schema changes? no-op.
  php artisan config:clear   && php artisan config:cache
  php artisan route:clear    && php artisan route:cache
  php artisan view:clear     && php artisan view:cache
  php artisan event:clear    && php artisan event:cache
  php artisan queue:restart            # signal all workers to gracefully reload
  php artisan up

  # Reapply ACLs in case anything new dropped in
  ../scripts/fix-perms.sh || true
'

# Rebuild the PWA bundle (only if src/ or package.json changed)
sudo -u deploy -H bash -lc '
  cd /var/www/poes.health.go.ug
  npm ci && npm run build
'

# Reload Nginx (only required if config changed)
sudo nginx -t && sudo systemctl reload nginx
```

`scripts/fix-perms.sh` is the ACL recipe from § 5.3 wrapped in a script —
keep it in the repo and run it after every deploy.

---

## 10. Smoke tests after every deploy

```bash
# API health
curl -fsSI https://poes.health.go.ug/                | head -1
curl -fsS  https://poes.health.go.ug/login           | grep -i "<title"
# PWA
curl -fsSI https://poes.health.go.ug/app/            | head -1
# Queue + scheduler
sudo systemctl status poes-queue@1 --no-pager | head -5
sudo systemctl status poes-scheduler.timer --no-pager | head -5
# Storage writability (must succeed silently)
sudo -u www-data touch /var/www/poes.health.go.ug/api/storage/logs/.health && \
  rm /var/www/poes.health.go.ug/api/storage/logs/.health
```

Application logs:

```bash
tail -F /var/www/poes.health.go.ug/api/storage/logs/laravel-*.log
journalctl -u 'poes-queue@*' -f
journalctl -u poes-scheduler.service -n 50
```

---

## 11. Split / HA topology notes

### 11.1 Split (app ↔ dedicated MySQL ↔ dedicated Redis)

* Open MySQL only to the app-host subnet (firewall + `bind-address`).
* Use the `poes_app@10.%` grant created in § 3.2.
* In `api/.env`, set `DB_HOST=` and `REDIS_HOST=` to the private IPs.
* Run queue workers on the app hosts, **never** on the DB host.
* Configure MySQL replication (`source_log_file` + `source_log_pos`) before
  enabling read-only replicas; the app currently writes to a single primary.

### 11.2 HA (≥ 2 app hosts behind a load balancer)

* All app hosts share the same `APP_KEY` (copy `api/.env`'s `APP_KEY` —
  Laravel encrypted sessions / signed URLs break if it differs).
* Redis must be **shared** — sessions and queue jobs must hit the same Redis
  instance (or a Sentinel pool). Do not use per-host Redis.
* Use sticky sessions on the LB **only** as a fallback; Redis-backed sessions
  remove the need for it.
* `TRUSTED_PROXIES` in `api/.env` must include the LB's CIDR so the
  framework respects `X-Forwarded-Proto` / `X-Forwarded-For`.
* Run queue workers on a dedicated worker host (or one of the app hosts —
  not all of them, to avoid duplicate scheduler ticks). Only **one** host
  must run `poes-scheduler.timer`.

---

## 12. Backups

```bash
# Daily logical dump — keep 14 days
sudo install -d -o deploy -g www-data -m 2775 /var/backups/poes
sudo tee /etc/cron.daily/poes-db-backup > /dev/null <<'CRON'
#!/usr/bin/env bash
set -euo pipefail
TS=$(date +%F-%H%M)
OUT="/var/backups/poes/poes_health-${TS}.sql.gz"
mysqldump --single-transaction --routines --triggers --events \
  -u poes_app -p"$(grep ^DB_PASSWORD /var/www/poes.health.go.ug/api/.env | cut -d= -f2)" \
  poes_health | gzip -9 > "$OUT"
find /var/backups/poes -name 'poes_health-*.sql.gz' -mtime +14 -delete
CRON
sudo chmod +x /etc/cron.daily/poes-db-backup
```

Off-host upload (S3-compatible / restic / rclone) is left to the gov ops
team — point it at `/var/backups/poes/`.

---

## 13. Troubleshooting cheatsheet

| Symptom                                              | First thing to check                                                                    |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------- |
| 500 on every page, blank screen                      | `tail -F api/storage/logs/laravel-*.log` — usually `.env` or DB credentials             |
| "The stream or file ... could not be opened"         | Re-run § 5.3 ACL script — storage/log dirs lost their default ACL                       |
| Login works once, then bounces                       | Redis password mismatch, or `SESSION_SECURE_COOKIE=true` over plain HTTP                |
| Queue jobs pile up                                   | `systemctl status poes-queue@*` — workers stopped; check Redis password / DB connection |
| Scheduler not firing                                 | `systemctl list-timers poes-scheduler.timer`; confirm host running it has `--force` env |
| 502 Bad Gateway from Nginx                           | `php-fpm` socket path / perms — confirm `/run/php/poes.sock` matches both pool + nginx  |
| `SQLSTATE[HY000] [2002]`                             | `DB_HOST` unreachable from app host; check firewall and `bind-address`                  |
| Migrations fail with "denied for user"               | The MySQL user is missing `CREATE/ALTER/INDEX/REFERENCES/TRIGGER` — re-run § 3.2 grants  |

---

## 14. Deferred optimizations — **DO NOT ENABLE YET**

These two optimizations are **off** in the current production rollout and
must remain off until **all major feature updates have shipped and the
platform is stable in production for at least one full release cycle**.

Re-enable them, in this order, only after a written go-ahead from the
platform owner.

### 14.1 PHP OPcache — DEFERRED

Why it's off now: hot-fix iteration is frequent during rollout; OPcache
keeps stale bytecode unless every deploy explicitly invalidates it, and the
risk of serving stale class definitions outweighs the perf gain at this
stage.

When stable, enable with:

```bash
sudo apt -y install php8.3-opcache
sudo tee /etc/php/8.3/fpm/conf.d/10-opcache-prod.ini > /dev/null <<'INI'
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0          ; trust release process
opcache.revalidate_freq=0
opcache.save_comments=1                ; Laravel needs annotations
opcache.fast_shutdown=1
opcache.jit_buffer_size=64M
opcache.jit=tracing
INI
sudo systemctl restart php8.3-fpm
```

Then `php artisan optimize` must be added to the deploy script (§ 9), and
every deploy must `sudo systemctl reload php8.3-fpm` to invalidate cached
opcodes.

### 14.2 Laravel Octane (Swoole / RoadRunner) — DEFERRED

Why it's off now: Octane keeps the app long-running in memory. Until the
codebase has been fully audited for request-scoped state leaks (especially
in the alerts SLA scanner, the scope resolver, and shared singletons),
Octane can leak data **between requests of different roles** — which is a
hard security violation for this platform.

When stable and audited, enable with:

```bash
sudo apt -y install php8.3-swoole       # or use roadrunner
cd /var/www/poes.health.go.ug/api
sudo -u deploy composer require laravel/octane
sudo -u deploy php artisan octane:install --server=swoole
# Then create poes-octane.service (systemd), point Nginx upstream to 127.0.0.1:8000,
# and remove the unix-socket fastcgi block from § 7.
```

> ⚠️ **Re-test every RBAC role end-to-end before Octane goes live.** The
> per-request container singletons that the current stateless FPM model
> hides will start leaking under Octane.

---

## 15. Quick reference

```bash
# Restart everything
sudo systemctl restart php8.3-fpm nginx redis-server mysql
sudo systemctl restart 'poes-queue@*' poes-scheduler.timer

# Tail all relevant logs in one terminal
sudo journalctl -u nginx -u php8.3-fpm -u redis-server -u 'poes-queue@*' -f

# Re-apply ACLs after a manual edit
bash /var/www/poes.health.go.ug/scripts/fix-perms.sh

# DB shell
mysql -u poes_app -p -h 127.0.0.1 poes_health

# Redis shell
redis-cli -a "$(grep ^REDIS_PASSWORD /var/www/poes.health.go.ug/api/.env | cut -d= -f2)"

# Clear all Laravel caches (use on suspected stale config)
cd /var/www/poes.health.go.ug/api && \
  php artisan optimize:clear
```

---

**Owner:** Uganda Ministry of Health — Digital Health & Surveillance Unit
**Operations contact:** ops@health.go.ug
**Production URL:** https://poes.health.go.ug/
