# 11 — Deployment

## 1. Local environment (provisioned 2026-09-01)

All Phase 1 Step 0 blockers are resolved.

| Component | Status | Location |
|---|---|---|
| PHP (project) | **8.4.25** (ZTS, VC2022 x64) | `C:\php84` |
| PHP (pre-existing, untouched) | 8.2.33 (ZTS, VC2019 x64) | `C:\php` |
| Composer | **2.10.3** | `C:\php\composer.phar` (+ `composer.bat` and a bash shim) |
| MariaDB | **12.3.2** | `C:\Program Files\MariaDB 12.3` |
| Node / npm | 24.14.1 / 11.11.0 | |
| Git | 2.54.0 | |

### PHP 8.4 installed alongside 8.2

PHP 8.4 went to `C:\php84` rather than replacing `C:\php`, so the pre-existing 8.2 install
and the user's other project (`newshub_cms`) keep working unchanged. The project's PHP is
selected per shell:

```bash
export PATH="/c/php84:$PATH"      # bash
$env:PATH = "C:\php84;$env:PATH"  # PowerShell
```

Extensions enabled in `C:\php84\php.ini`: `curl`, `zip`, `intl`, `exif`, `gd`, `mbstring`,
`openssl`, `fileinfo`, `pdo_mysql`, `mysqli`, `pdo_sqlite`, `sqlite3`, `sodium`, `bz2`.
The same set was also enabled in `C:\php\php.ini`, backed up first to
`C:\php\php.ini.bak-20260901`.

`curl` and `zip` were the two that mattered: `curl` for reliable outbound HTTPS to social
and AI APIs (Guzzle's stream fallback has poor timeout control and no connection reuse),
and `zip` so Composer installs from dist archives instead of slow source clones.

### MariaDB

MariaDB 12.3.2 was already installed but had **no Windows service registered** and was not
running — it had last been started manually on 2026-08-26. Start it with:

```bash
"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe" --console
```

MariaDB 12.3 comfortably exceeds the 10.6 floor for `SELECT … FOR UPDATE SKIP LOCKED`, so
the database queue driver is safe.

**Reboot survival — solved 2026-09-01 via a scheduled task.**

Registering a real Windows service needs an elevated shell (`sc create` returns
`OpenSCManager FAILED 5: Access is denied` without one). Instead, a **per-user scheduled
task** runs at logon, which needs no elevation and achieves the same outcome:

| | |
|---|---|
| Task name | `MariaDB-SMM` |
| Trigger | At logon, current user |
| Action | `D:\mariadb-smm\start-mariadb.cmd` |

The script is idempotent — it checks port 3307 first and exits if the instance is already
listening, so running it twice is harmless. Verified by stopping MariaDB and confirming
the task brought it back.

Manage it with:

```powershell
Get-ScheduledTask -TaskName 'MariaDB-SMM'
Start-ScheduledTask  -TaskName 'MariaDB-SMM'
Unregister-ScheduledTask -TaskName 'MariaDB-SMM' -Confirm:$false
```

**Trade-off:** a logon task starts the database only after the user signs in, whereas a
true service starts at boot before any login. That is fine for a development machine and
wrong for a server. If you later open an elevated prompt, prefer the service:

```
sc create MariaDBSMM binPath= "\"C:\Program Files\MariaDB 12.3\bin\mariadbd.exe\" --defaults-file=\"D:\mariadb-smm\data\my.ini\" MariaDBSMM" start= auto
```

Databases: `smm_dev` and `smm_test`, owned by a dedicated `smm` user rather than `root`.
The pre-existing `newshub_cms` database was left untouched.

**Running the test suite against MariaDB rather than SQLite is deliberate.** SQLite
diverges on foreign-key enforcement, enum handling and JSON columns — precisely the areas
this schema leans on hardest.

### Dev instance tuning (`D:\mariadb-smm\data\my.ini`)

The suite rebuilds 45 tables per run, and on stock settings that took **848 seconds** —
far too slow for a merge gate people will actually run. The dev instance therefore trades
durability for DDL speed:

| Setting | Value | Effect |
|---|---|---|
| `innodb_flush_log_at_trx_commit` | `0` | Redo flushed once a second, not per commit |
| `innodb_doublewrite` | `0` | Pages written once instead of twice |
| `skip_log_bin` | — | No binary log |
| `skip_name_resolve` | — | No reverse DNS per connection |
| `innodb_buffer_pool_size` | `512M` | Fits the whole schema in memory |

Result: **848s → 103s**, an 8x improvement, with all 28 tests still passing.

> **These settings are unsafe for production data and must never be copied to a server
> holding any.** They are acceptable here only because both databases are disposable —
> rebuilt by `migrate:fresh` and by `RefreshDatabase` on every run. The production
> checklist in §5 assumes stock durability.

### Remaining production verification

1. **PHP 8.3+ availability — CONFIRMED 2026-09-01.** The Hostinger plan offers PHP 8.3+,
   so the Laravel 13 / PHP 8.4 target is validated end to end. Set the hPanel PHP version
   explicitly to 8.4 (or 8.3) before deploying, and confirm the **CLI** binary matches —
   hPanel's PHP selector governs the web SAPI, and cron often defaults to a different,
   older binary. A cron running PHP 8.2 against this codebase fails silently every minute.

Still to confirm in hPanel **before the first deploy**:

2. **MariaDB 10.6+ / MySQL 8.0+** — anything older silently breaks queue locking and
   produces duplicate job processing, which means duplicate social posts.
3. The same extension set is present, particularly `curl`, `zip`, `intl`, `gd`, `exif`.
4. The exact CLI binary path for the cron entry (see §4).

## 2. Repository and branching

```
main         production, tagged releases only
develop      integration
feature/*    work branches
```

`.gitignore` must cover `.env`, `/vendor`, `/node_modules`, `/storage/*.key`,
`/public/build`, `/public/storage`, `/storage/app/private/*`, `.phpunit.result.cache`
**from the first commit**. A secret committed once is compromised permanently, even after
removal.

## 3. Shared hosting layout (Hostinger, V1)

Shared hosting serves from `public_html`. The application must live **outside** it:

```
/home/USER/
├── app/                    ← Laravel root (NOT web-accessible)
│   ├── app/ bootstrap/ config/ database/ resources/ routes/ storage/ vendor/
│   ├── .env                (chmod 600)
│   └── artisan
└── public_html/            ← document root
    ├── index.php           (modified — see below)
    ├── .htaccess
    ├── build/              (Vite assets)
    └── favicon.ico
```

`public_html/index.php` — the two changed lines:

```php
require __DIR__.'/../app/vendor/autoload.php';
$app = require_once __DIR__.'/../app/bootstrap/app.php';
```

`.htaccess` additions beyond Laravel's default:

```apache
# Never serve dotfiles
<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# Force HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Verify after every deploy that `https://domain/.env` returns 404 and
`https://domain/storage/logs/laravel.log` is unreachable.

## 4. Cron

**One** entry, and the whole product depends on it:

```bash
* * * * * cd /home/USER/app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Confirm the correct PHP CLI binary path with Hostinger — it is frequently version-specific
(e.g. `/opt/alt/php82/usr/bin/php`) and differs from the web SAPI binary. A cron running
PHP 7.4 against a Laravel 12 app fails silently every minute.

If additional cron entries are permitted, add a second staggered publishing worker
(`07-QUEUE-ARCHITECTURE.md` §5).

**Monitor the cron itself.** If it stops, nothing publishes and no error surfaces anywhere.
The scheduler writes a heartbeat on each run; the admin queue-health screen shows "last
successful run" per command, and an alert fires if `schedule:run` has not reported in 10
minutes.

## 5. Environment configuration

```ini
APP_NAME="..."            APP_ENV=production        APP_DEBUG=false
APP_URL=https://...       APP_KEY=                  APP_TIMEZONE=UTC

DB_CONNECTION=mysql       DB_HOST=localhost         DB_DATABASE=  DB_USERNAME=  DB_PASSWORD=

SESSION_DRIVER=database   SESSION_SECURE_COOKIE=true  SESSION_ENCRYPT=true
CACHE_STORE=database      QUEUE_CONNECTION=database
LOG_CHANNEL=daily         LOG_LEVEL=warning

FILESYSTEM_DISK=local     MEDIA_DISK=private

MAIL_MAILER=smtp          MAIL_HOST=  MAIL_PORT=  MAIL_USERNAME=  MAIL_PASSWORD=

RAZORPAY_KEY_ID=          RAZORPAY_KEY_SECRET=      RAZORPAY_WEBHOOK_SECRET=
ANTHROPIC_API_KEY=        AI_DEFAULT_PROVIDER=anthropic

# Platform-default social apps (tenants may override with their own)
META_APP_ID=   META_APP_SECRET=
LINKEDIN_CLIENT_ID=  LINKEDIN_CLIENT_SECRET=
X_CLIENT_ID=  X_CLIENT_SECRET=
GOOGLE_CLIENT_ID=  GOOGLE_CLIENT_SECRET=
```

`APP_KEY` differs per environment. Encrypted columns are unreadable without the exact key
that wrote them — losing it means losing every OAuth token and credential in the database.
Back it up separately from the database, and never in the same store.

## 6. Deploy procedure (shared hosting)

Build locally (Node is often unavailable or memory-limited on shared hosting), then upload.

```bash
# Local
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# Upload app/ (excluding node_modules, .env, storage/logs) and public/build -> public_html/build

# On server
php artisan down --render="errors::503"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link      # only if a public disk is used
php artisan up
```

Post-deploy verification, every time:

1. `/.env` returns 404
2. Login works on all three surfaces
3. `php artisan schedule:list` shows the expected commands
4. A test job dispatches and completes
5. Admin queue-health screen is green
6. No `production.ERROR` entries in the first 10 minutes

## 7. Backups

- Database: daily automated dump, retained 30 days, stored **off-server**.
- Media: weekly full plus daily incremental.
- `.env` and `APP_KEY`: stored in a password manager, separate from database backups.
- **Restore is tested monthly.** A backup that has never been restored is a hypothesis.

## 8. Monitoring (V1, no external APM)

- `LOG_LEVEL=warning`, daily rotation, 14-day retention.
- Admin dashboard: queue depth, failed jobs, dispatch-to-publish p95, connections needing
  reconnect, scheduler heartbeat.
- Failure notifications to the platform operator for: scheduler silent >10 min, failed jobs
  above threshold, webhook processing failures, and any publish failure rate above 10% in an
  hour.

## 9. VPS migration

Trigger conditions — migrate when **any** of these holds:

- Dispatch-to-publish p95 above 5 minutes sustained
- More than ~50 active tenants, or ~5,000 scheduled posts/month
- Video transcoding becomes a product requirement
- Shared-host process or memory limits cause recurring job kills

Target stack: Nginx, PHP-FPM 8.2+, MySQL 8, Redis, Supervisor, cron, S3-compatible object
storage, Let's Encrypt.

Sequence:

1. Provision, install the stack, deploy the same codebase, restore the database.
2. Drain the database queue on the old host (`queue:work database --stop-when-empty`)
   **before** switching drivers — otherwise queued jobs are stranded in a driver nothing is
   reading.
3. Switch `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` to `redis`.
4. Start Supervisor workers; install Horizon.
5. Migrate media to object storage: change the default `MEDIA_DISK`, then run a background
   job that copies existing files and updates `media.disk` per row. Because `disk` is stored
   per row, old and new files coexist and there is no flag day.
6. Enable FFmpeg-backed video processing.
7. Raise `dispatch_batch_size` and `job_time_budget`.
8. Cut DNS over after verification; keep the shared host warm for 48 hours.

**No application code changes** are expected in this migration. That claim is verified by a
test asserting no driver-specific class is referenced outside `config/`.

## 10. Incident runbooks

**Nothing is publishing** — check the scheduler heartbeat, then the cron entry and its PHP
binary path, then queue depth, then whether targets are stuck in `processing` past the lock
TTL.

**Provider credential compromised** — deactivate the credential, rotate at the provider,
mark dependent connections `needs_reconnect`, notify the affected tenant, audit-log
everything.

**`APP_KEY` compromised** — the most severe case. Generate a new key, re-encrypt every
encrypted column with a maintenance command that reads with the old key and writes with the
new, then force reconnection of all social accounts. Plan for downtime.

**Suspected cross-tenant leak** — enable maintenance mode, review `audit_logs` for the
affected entities, identify the code path, patch, add the regression test, then notify
affected tenants.
