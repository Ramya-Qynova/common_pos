# RUNBOOK.md

Operations reference for the Advanced Universal POS. Read `docs/PROGRESS.md` for build status and `docs/DECISIONS.md` for why things are built the way they are; this file is about running and operating what's already built.

## 1. Local development

```powershell
# one-time
pwsh scripts/check-env.ps1
cd backend; composer install; copy .env.example .env; php artisan key:generate; cd ..
cd frontend; npm install; cd ..

# every day
cd backend; php artisan serve   # http://localhost:8000
cd frontend; npm run dev        # http://localhost:5173
```

Database: MySQL 8+ on `127.0.0.1:3306`, database `common_pos` (create it once; see `backend/.env.example` for the credentials shape). Run `cd backend; php artisan migrate` after pulling new migrations. Optional: `php artisan demo:seed` creates a demo tenant (shop_code `DEMO`, owner/`demo-password-123`) through the real onboarding flow, with one priced, stocked product and one completed sale.

## 2. Running the quality gates locally (same as CI, `.github/workflows/ci.yml`)

```powershell
node scripts/check-dynamic-only.js

cd backend
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress
php artisan test
cd ..

cd frontend
npm run lint
npm run format
npm run test
npm run build
cd ..
```

## 3. Deployment

### Docker

```powershell
copy backend\.env.example backend\.env
# edit backend\.env: APP_KEY (php artisan key:generate --show), DB_PASSWORD, DB_ROOT_PASSWORD
docker compose up --build -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
```

This brings up `app` (PHP-FPM, `backend/Dockerfile`), `nginx` (serves the API on `:8000`, `docker/nginx/default.conf`), `frontend` (static build on `:5173`, `frontend/Dockerfile`), `mysql` (`:3307` on the host, so it doesn't collide with a local MySQL on `:3306`), and `redis` (`:6380` on the host). Validate the compose file at any time with `docker compose config` - it does not need the daemon running.

**Known limitation (honestly disclosed, not guessed around):** this was written and syntax-validated (`docker compose config --quiet` exits 0) but could not be run end-to-end in the sandboxed environment this project was built in - Docker Desktop's daemon fails to start there (`docker info` reports "Docker Desktop is unable to start"). On a normal developer machine or CI runner with a working Docker daemon, `docker compose up --build` is expected to work as described; verify it once there before relying on it for a real deployment.

### Bare-metal / XAMPP-style (what this project was actually developed and tested against)

PHP-FPM or `php artisan serve` behind any web server, pointed at `backend/public`; a real MySQL 8+ server; Redis optional (the app runs fine on the `database`/`file` cache/session/queue drivers - see `backend/.env.example`). Run `php artisan migrate --force` and `php artisan db:seed --force` once.

## 4. Backup and restore

`scripts/backup-db.ps1` dumps the database (plain `mysqldump`, `--single-transaction`, no proprietary format) to `backups/<db>-<timestamp>.sql` (gitignored - dumps may contain real tenant data). `scripts/restore-db.ps1` restores a dump into a **disposable** `<db>_restore_test` database by default - it never overwrites the live database unless you explicitly pass `-TargetDatabase` naming it.

**Run a real backup:**
```powershell
pwsh scripts/backup-db.ps1
```

**Verify a backup actually restores correctly** (section 15 Final Acceptance's own requirement - do this periodically, not just once):
```powershell
pwsh scripts/verify-backup-restore.ps1
```
This backs up the live database, restores the dump into a throwaway database, compares every table's row count between the two, re-derives the G5 stock-ledger invariant (every `stock_balances` row still equals the signed sum of its own `stock_ledger` rows) against the **restored copy specifically**, then drops the throwaway database. Exits non-zero on any mismatch. The last real run of this (see `docs/reports/phase-8.md`) passed on all 121 tables plus the ledger invariant.

**Windows/XAMPP caveat found while building this:** XAMPP's bundled `mysql.exe`/`mysqldump.exe` (`C:\xampp\mysql\bin`) are MariaDB-flavoured and lack the `caching_sha2_password` plugin a real MySQL 8/9 server's default auth needs - they fail to connect at all with `Plugin caching_sha2_password could not be loaded`. All three scripts auto-detect a proper MySQL client under `C:\Program Files\MySQL\MySQL Server*\bin` first and fall back to the XAMPP path only if none is found. If both `mysql.exe`/`mysqldump.exe` fail to connect on your machine, this is almost certainly the cause - install MySQL's own client tools (or the full MySQL Server, which bundles them) rather than relying on XAMPP's.

## 5. Monitoring

`GET /api/v1/health` (public, no auth) returns `{"status": "ok"|"degraded", "checks": {...}}` - `200` when healthy, `503` when not. Checks the database always, and Redis only when it's actually configured as the cache/session/queue driver (so a bare-metal deployment on the `database`/`file` drivers isn't falsely flagged for a dependency it doesn't use). Point an uptime monitor or load balancer health probe at this.

Beyond the health endpoint, `audit_logs` and `security_events` (queryable via `GET /api/v1/devices`, `core.audit.view`-gated endpoints, and direct DB access) are this build's audit trail - every permission-gated action logs who did what. No external APM/log-aggregation service is wired in (none was available to configure in this environment); `LOG_CHANNEL=stack` writes to `backend/storage/logs/laravel.log` by default, which any standard log shipper can tail.

## 6. Common operational tasks

- **Revoke a device** (lost/stolen tablet): `POST /api/v1/devices/{id}/revoke` (`core.users.manage`). Its pre-revocation offline sales still sync once on next contact (section 13.2); anything it tries to create after is rejected with `DEVICE_REVOKED`. See D-0035 for why this isn't an immediate blanket lockout.
- **Resolve a sync conflict**: `GET /api/v1/sync/conflicts` lists open `STOCK_SHORTFALL`/`PRICE_MISMATCH`/`CLOCK_SKEW`/`DATA_CHANGED` rows; `POST /api/v1/sync/conflicts/{id}/resolve` (`core.audit.view`) marks one resolved once a human has acted on it (e.g. adjusted stock, reconciled a price difference).
- **Reopen a closed business day**: `POST /api/v1/daily-closings/{id}/reopen` (`finance.reopen`), with a required reason.
- **Apply a template update** (new units/categories/lookups/modules/settings a template defines but a tenant doesn't have yet): `GET /api/v1/template/update-preview` then `POST /api/v1/template/update` (`core.settings.manage`) - always additive, never removes a tenant's own customization.
- **Seed a demo tenant**: `php artisan demo:seed` (see section 1).

## 7. Incident checklist

1. `GET /api/v1/health` - database (and Redis, if configured) reachable?
2. `php artisan migrate:status` - any pending migrations that didn't run?
3. `backend/storage/logs/laravel.log` - the most recent stack trace.
4. `GET /api/v1/sync/operations?status=rejected` / `?status=held` - anything queueing up that shouldn't be (e.g. a tenant stuck `held` because it was suspended by mistake)?
5. If data corruption is suspected: stop writes, run `scripts/verify-backup-restore.ps1` against the most recent backup to confirm a clean restore point exists, then restore for real (adapt `restore-db.ps1`'s `-TargetDatabase` to the live database name only after a deliberate, confirmed decision to do so - it is never the default).

## 8. Performance notes

Section 11's targets were measured for real in Phase 8 (`tests/Feature/Performance/PerformanceTest.php`, numbers transcribed in `docs/reports/phase-8.md`): `POST /sales` and the full snapshot pull both comfortably beat their targets on this dev machine. Sync push throughput measured below the 100 ops/s target because every operation in a batch is processed synchronously, in order, inside one HTTP request (correct and simple, but not parallel) - see D-0044. If sync throughput becomes a real bottleneck in production, the fix is to dispatch each operation to a queue worker instead of processing the batch inline, which the "per worker" wording in the target already anticipates.
