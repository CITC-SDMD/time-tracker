# Setup

## Laravel API — local dev on Windows (Phase 1+)

This is for developing `apps/api` on the same Windows PC as the desktop app — a lighter, local-only setup, not the office server (see "Office server (Phase 1+)" below for that). Do these steps once.

### 1. Install the tools

```powershell
winget install --id PHP.PHP.8.3 -e
winget install --id Oracle.MySQL -e
```

Composer doesn't have a winget package — download and run the official installer instead: https://getcomposer.org/Composer-Setup.exe (or `/VERYSILENT /NORESTART` for an unattended install).

Close and reopen PowerShell afterward, so the new commands are found.

### 2. Enable PHP's extensions

The winget PHP build ships without an active `php.ini`. In the PHP install directory (`(Get-Command php).Source | Split-Path`):

```powershell
Copy-Item php.ini-development php.ini
```

Then uncomment (remove the leading `;`) these lines in `php.ini`: `extension=curl`, `extension=exif` (after `mbstring`; the media library for screenshots needs it), `extension=fileinfo`, `extension=gd`, `extension=mbstring`, `extension=openssl`, `extension=pdo_mysql`, `extension=zip`. Verify with `php -m`.

### 3. Initialize and start MySQL

Winget's silent install lays down MySQL's files but skips the usual configuration wizard — no data directory, no Windows service. Initialize it once:

```powershell
$mysqlBase = "C:\Program Files\MySQL\MySQL Server 8.4"
$dataDir = "$HOME\mysql-data"
New-Item -ItemType Directory -Force -Path $dataDir
& "$mysqlBase\bin\mysqld.exe" --initialize-insecure --basedir="$mysqlBase" --datadir="$dataDir"
```

Then, **every time you want MySQL running** (it is not a registered service, so it doesn't survive a reboot or start on its own — this is a deliberate choice to avoid needing admin rights for local dev; the real office server *does* run it as a proper service, see below):

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --basedir="C:\Program Files\MySQL\MySQL Server 8.4" --datadir="$HOME\mysql-data" --console
```

Leave that running in its own terminal (or start it as a background job) while you work. First time only, secure it and create the dev database:

```powershell
$mysql = "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe"
@"
ALTER USER 'root'@'localhost' IDENTIFIED BY '<pick a local password>';
CREATE DATABASE tracker_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tracker'@'localhost' IDENTIFIED BY '<pick a local password>';
GRANT ALL PRIVILEGES ON tracker_dev.* TO 'tracker'@'localhost';
FLUSH PRIVILEGES;
"@ | & $mysql -u root
```

### 4. Set up and run the API

```powershell
cd apps/api
composer install
copy .env.example .env
php artisan key:generate
```

Edit `.env`: set `DB_DATABASE=tracker_dev`, `DB_USERNAME=tracker`, `DB_PASSWORD=<what you picked above>` (`DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306` are already right from `.env.example`).

```powershell
php artisan migrate --seed
php artisan tracker:make-superadmin "Your Name" you@example.com
php artisan serve
```

Write down the temporary password `tracker:make-superadmin` prints: that is the platform owner, the only account until you create organizations. Sign in to the dashboard, create an organization and add its admin on its profile page (or run `php artisan tracker:make-organization "Office name" "Admin name" admin@example.com`); the admin then makes their own roles and people (docs/DEVELOPMENT_PLAN.md §9).

**Trying the dashboard with a filled-in office (development only):** after `php artisan migrate:fresh --seed`, run `php artisan db:seed --class=DemoHierarchySeeder`. It adds 2 project managers, 3 team leaders and 6 members under `oic@test.com` (all with the password `password`, e.g. `pm1@test.com`, `tl1@test.com`, `dev1@test.com`), 60 days of daily totals, every live state (tracking, idle, paused, not tracking, offline) and today's timelines for a few of them. It does nothing in production and can be run again.

`http://127.0.0.1:8000/health` should return `{"ok":true,"version":"dev"}`. Quality checks: `vendor\bin\pint --test` (formatting) and `php artisan test`.

To run the dashboard against this local API instead of the same-origin production setup, point `apps/dashboard`'s dev server at it — see `apps/dashboard/nuxt.config.ts`'s `runtimeConfig.public.apiBase`.

---

## Desktop app on Windows (Phase 0)

The desktop app only works on **Windows 10 or 11**. Do these steps once on the Windows PC.

### 1. Install the tools

1. **Git:** https://git-scm.com/download/win (default options).
2. **Node.js 24 LTS:** https://nodejs.org (default options).
3. **pnpm:** open PowerShell and run:
   ```powershell
   corepack enable
   corepack prepare pnpm@12.4.1 --activate
   ```
4. **Microsoft C++ Build Tools:** https://visualstudio.microsoft.com/visual-cpp-build-tools/
   In the installer, tick **"Desktop development with C++"** and install.
5. **Rust:** https://rustup.rs → download `rustup-init.exe` → run it → press Enter for the defaults.
6. **WebView2:** already included in Windows 10/11. Nothing to do.

Close and reopen PowerShell after installing, so the new commands are found.

### 2. Get the code and run it

```powershell
git clone <repo-url> time-tracker
cd time-tracker
pnpm install
pnpm dev:agent
```

The first run compiles the Rust code and takes a few minutes. Later runs are fast.
A window called **Time Tracker** opens and shows the app in front, the window title and the idle seconds.

### 3. Phase 0 background log

The app writes a line every 2 seconds, plus every lock/sleep event, to:

```text
%LOCALAPPDATA%\com.office.timetracker\logs\activity-spike.log
```

(The exact path is also shown at the bottom of the app window.) Use it for tests 0.7 and 0.8 in `docs/DEVELOPMENT_PLAN.md`.

### 4. Build an installer (optional in Phase 0)

```powershell
pnpm build:agent
```

The installer is created in `apps/agent/src-tauri/target/release/bundle/nsis/`.
Use the installed (release) build for test 0.11 (resource use). Dev builds use more CPU and memory.

**API address:** the app talks to the server named by the `TRACKER_API_URL` build setting (the server root, no `/api/v1`), for example `$env:TRACKER_API_URL = "https://tracker.example.com"` before `pnpm build:agent`. If it is not set, the app uses `http://127.0.0.1:8000`, which is right for local development.

**Dashboard address (for "Forgot password?"):** the login screen opens the dashboard's reset page in the browser. In production the dashboard shares the API's address, so nothing more is needed. If yours differs, set `TRACKER_DASHBOARD_URL` the same way. Development builds default to `http://localhost:3100`.

---

## Office server (Phase 1+)

The API, database and dashboard run on **one Linux server** you control, reachable on the internet under a domain name — WFH employees connect to it directly (see the Hosting decision in `docs/DEVELOPMENT_PLAN.md` §0). These steps apply once `apps/api` exists in the repo (Phase 1 onward). Run them **on the server** over SSH, not on the Windows dev PC.

### 1. Before you start

- A domain or subdomain (e.g. `tracker.yourcompany.com`) with its DNS **A record** already pointing at the server's public IP.
- A Linux server (Ubuntu 22.04/24.04 LTS is a safe default) with SSH access and a sudo-capable user. 2 vCPU / 2-4 GB RAM covers an office this size comfortably.
- Ports 80 and 443 reachable from the internet (cloud security group and/or router port-forward, as applicable).

### 2. Install the server software

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath \
  unzip git certbot python3-certbot-nginx
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

(Use whatever PHP version is current when you do this — Laravel 11 needs 8.2+.)

### 3. Secure MySQL and create the database

```bash
sudo mysql_secure_installation
sudo mysql -u root -p
```

```sql
CREATE DATABASE tracker_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tracker'@'localhost' IDENTIFIED BY '<a strong generated password>';
GRANT ALL PRIVILEGES ON tracker_prod.* TO 'tracker'@'localhost';
FLUSH PRIVILEGES;
```

Confirm MySQL only listens on `localhost` (`bind-address = 127.0.0.1` in `/etc/mysql/mysql.conf.d/mysqld.cnf`) — never open port 3306 to the internet. This is Test 1.5 in the dev plan.

### 4. Get the code and install the API

```bash
git clone <repo-url> time-tracker
cd time-tracker/apps/api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tracker.yourcompany.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=tracker_prod
DB_USERNAME=tracker
DB_PASSWORD=<the password from step 3>

SANCTUM_STATEFUL_DOMAINS=tracker.yourcompany.com

# Where welcome and password-reset emails link to. Leave it out when the dashboard and API
# share one address (the normal setup); locally the dashboard is on its own port.
# DASHBOARD_URL=http://localhost:3100

# Mail (welcome and password-reset links). If sending fails, the account is still created and the
# dashboard shows the set-password link so the manager can pass it on.
# Gmail / Google Workspace: turn on 2-Step Verification, create an app password at
# https://myaccount.google.com/apppasswords and use it here (not the normal password).
# About 500 emails a day for a normal Gmail account; a mail service such as Brevo or SES is more
# reliable for production.
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=youraccount@gmail.com
MAIL_PASSWORD=<the 16-character app password, no spaces>
MAIL_FROM_ADDRESS=youraccount@gmail.com
MAIL_FROM_NAME="Time Tracker"
```

```bash
php artisan migrate --seed
php artisan tracker:make-superadmin "Your Name" you@yourcompany.com
```

Write down the temporary password it prints and use it for the first dashboard login. Once mail is set up, "Forgot password?" on the login page lets you choose your own.

### 5. Build and deploy the dashboard

From the dev machine or the server (either works):

```bash
cd time-tracker
pnpm install
pnpm --filter dashboard generate
```

Copy the generated static files (`apps/dashboard/.output/public/`) to a path nginx will serve, e.g. `/var/www/tracker-dashboard/`.

### 6. nginx: one domain for the dashboard, API and update files

```nginx
server {
    listen 80;
    server_name tracker.yourcompany.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name tracker.yourcompany.com;
    # certbot fills in ssl_certificate / ssl_certificate_key below

    # Laravel API
    location /api/ {
        root /var/www/time-tracker/apps/api/public;
        try_files $uri /index.php?$query_string;
    }
    location ~ ^/api/.*\.php$ {
        root /var/www/time-tracker/apps/api/public;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }

    # Installer + latest.json — written only by the release workflow over SSH
    location /updates/ {
        root /var/www/time-tracker-updates;
        autoindex off;
    }

    # Dashboard (static Nuxt build)
    location / {
        root /var/www/tracker-dashboard;
        try_files $uri $uri/index.html /index.html;
    }
}
```

Treat this as a starting point, not a copy-paste final — Laravel's exact `public/` front-controller rewrite is easiest to check against Laravel's own current recommended nginx config, since it shifts slightly between versions.

```bash
sudo certbot --nginx -d tracker.yourcompany.com
sudo nginx -t && sudo systemctl reload nginx
```

Certbot sets up auto-renewal via a systemd timer — no manual renewal needed.

### 7. Scheduled jobs

The server keeps all data permanently (no retention pruning), so nothing needs the scheduler yet. If a scheduled job is added later, Laravel's scheduler needs exactly one cron entry:

```bash
sudo crontab -e -u www-data
```

```cron
* * * * * cd /var/www/time-tracker/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

This drives anything added to `routes/console.php`.

### 7b. Screenshot storage and the queue worker (Phase 10)

Screenshots are stored with Spatie Media Library on a private disk called `screenshots`. Which storage it uses is a setting in the server `.env` (never committed):

- **Development:** `SCREENSHOT_DISK=local` (the default). Files go to `apps/api/storage/app/private/screenshots/`.
- **Production:** `SCREENSHOT_DISK=s3`, pointing at the office storage server that runs MinIO (an S3-compatible service; the setup is described step by step in `docs/VPS_S3_STORAGE_PLAN.pdf`):

```dotenv
SCREENSHOT_DISK=s3
AWS_ACCESS_KEY_ID=<the key made for this app only>
AWS_SECRET_ACCESS_KEY=<its secret>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=screenshots
AWS_ENDPOINT=https://<s3-domain>
```

Then `php artisan config:cache`. The desktop app uploads one picture; the 320 px thumbnail is made by a **queued job**, so a queue worker has to run all the time in production (`QUEUE_CONNECTION=database`). Keep it alive with systemd:

```ini
# /etc/systemd/system/tracker-queue.service
[Unit]
Description=Time Tracker queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/time-tracker/apps/api
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tracker-queue
sudo systemctl restart tracker-queue      # after every deploy, so it runs the new code
```

If the worker is stopped, uploads still work and the dashboard shows the full picture in place of the thumbnail until the job runs. `php artisan media-library:regenerate` rebuilds thumbnails.

**Space and backups:** about 150 KB per picture (roughly 2 GB per person per year at a 10-minute interval). Each organization's Settings page (and its profile page for superadmins) shows the space used; files live under `org_<id>/` on the storage disk. The pictures live on the storage server, so back that up (see the PDF), and keep the MySQL dump too: it holds who and when for each picture.

### 8. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Only 22 (SSH — key-only, ideally IP-restricted), 80 and 443 should be reachable from the internet. MySQL (3306) and PHP-FPM should not be.

### 9. Backups

```bash
mysqldump -u tracker -p tracker_prod | gzip > tracker-$(date +%F).sql.gz
```

Put this in a daily cron job that copies the dump **off the server** — a backup that only lives on the machine it's backing up doesn't survive that machine failing. Test a restore before relying on it (Test 7.6 in the dev plan).

### 10. Release deploys (installer + updates)

The GitHub Actions release workflow (Phase 8) uploads the signed installer and `latest.json` to `/var/www/time-tracker-updates/` over SSH using a deploy key — create a restricted SSH user (or restrict the key to that one path) on the server, and store the private half in GitHub Secrets. Application code deploys (new Laravel/dashboard versions) are a manual `git pull && composer install && php artisan migrate && pnpm --filter dashboard generate` for now; see `docs/RELEASE.md` once Phase 8 is under way.
