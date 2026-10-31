# Advanced Universal POS

Multi-tenant, multi-branch, online + offline point-of-sale for any type of shop. Built from [`docs/MASTER_PROMPT.md`](docs/MASTER_PROMPT.md) — read that file for the full specification; this README is just the setup and orientation.

**Status:** Phase 0 (Foundation) in progress. See [`docs/PROGRESS.md`](docs/PROGRESS.md) for exactly what is done vs. pending, and [`docs/reports/`](docs/reports/) for the per-phase reports with real executed test output.

## Stack

React + JSX (no TypeScript) PWA with Vite · Laravel 12 (PHP ^8.2) REST API at `/api/v1` · MySQL/MariaDB InnoDB · IndexedDB via Dexie for offline. See `docs/DECISIONS.md` for environment-specific pins (PHP/Node/Vite versions) and why.

## Setup (Windows PowerShell)

```powershell
pwsh scripts/check-env.ps1

cd backend
composer install
copy .env.example .env
php artisan key:generate
# create the `common_pos` database in your local MySQL/MariaDB first, then:
php artisan migrate
cd ..

cd frontend
npm install
copy .env.example .env
cd ..
```

Run it:

```powershell
cd backend; php artisan serve   # http://localhost:8000
```
```powershell
cd frontend; npm run dev        # http://localhost:5173
```

## Repository layout

```
docs/     MASTER_PROMPT.md, DECISIONS.md, PROGRESS.md, OPEN_QUESTIONS.md,
          ERD.md, data-dictionary.md, threat-model.md, sync-design.md,
          openapi.yaml, TRACEABILITY.md, RUNBOOK.md, reports/phase-N.md
backend/  Laravel app
frontend/ React PWA
shared/   golden-vectors/ - pricing/tax/rounding test vectors shared by both engines
scripts/  check-env.ps1, check-dynamic-only.js
```

## Quality gates

```powershell
node scripts/check-dynamic-only.js   # fails the build on role-literal / currency-literal / hardcoded-UI-text violations

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

All of the above run in CI on every push (`.github/workflows/ci.yml`).

## Contributing to this build

Every rule, label, tax, menu entry and policy must be data, editable from the app — never a literal in code (`docs/MASTER_PROMPT.md` section 4). `scripts/check-dynamic-only.js` enforces the most common violations automatically; anything it doesn't catch is still a review requirement.
