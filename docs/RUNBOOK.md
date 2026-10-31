# RUNBOOK.md

Operational runbook. Filled in fully in Phase 8 ("Docker/hosting, runbook") once backups, monitoring and the Docker Compose stack exist. This Phase 0 version covers local development only.

## Local development

```powershell
# one-time
pwsh scripts/check-env.ps1
cd backend; composer install; copy .env.example .env; php artisan key:generate; cd ..
cd frontend; npm install; cd ..

# every day
cd backend; php artisan serve   # http://localhost:8000
cd frontend; npm run dev        # http://localhost:5173
```

Database: MySQL/MariaDB on `127.0.0.1:3306`, database `common_pos` (create it once: see `backend/.env.example` for the credentials shape). Run `cd backend; php artisan migrate` after pulling new migrations.

## Running the quality gates locally (same as CI, `.github/workflows/ci.yml`)

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

## Incident response, backups, deployment, scaling

_(Phase 8 — not yet built. This section is a placeholder so the file exists where the master prompt expects it; do not treat its absence of content as "done".)_
