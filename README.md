# Advanced Universal POS

Multi-tenant, multi-branch, online + offline point-of-sale for any type of shop. Built from [`docs/MASTER_PROMPT.md`](docs/MASTER_PROMPT.md) — read that file for the full specification; this README is just the setup and orientation.

**Status:** Release 1 (MVP) & Release 2 (Extended Enterprise) **100% complete** — all 18 phases (Phases 0 through 17) fully delivered and audited. See [`docs/PROGRESS.md`](docs/PROGRESS.md) for full phase logs, [`docs/reports/FINAL.md`](docs/reports/FINAL.md) for the Section 15 final acceptance audit, and [`docs/RUNBOOK.md`](docs/RUNBOOK.md) for operations (backup/restore, deployment, incident response).

## Enterprise Capabilities (Release 1 + Release 2)
- **Universal Multi-Tenancy**: Dynamic schema isolation, sub-branch & warehouse routing, strict global query scoping.
- **Offline-First PWA**: IndexedDB / Dexie storage, device-scoped sequence numbering, automatic reconciliation push upon reconnect.
- **Authoritative Server Pricing**: Dual-engine parity (PHP + JS golden vectors), client prices completely ignored (`G1`).
- **Inventory Management**: Weighted average & FIFO costing layers, real-time stock balances strictly tied to append-only ledger (`G5`).
- **Multi-Location Logistics**: Inter-warehouse stock transfers with dispatch/receive in-transit discrepancies, physical stocktake count reconciliation.
- **Industry Vertical Templates**: General Retail, Grocery (weighing scale & barcodes), Textile (size/color matrix), Medical (FEFO & batch expiry), Wholesale (B2B tier pricing & credit limits).
- **Two-Factor Authentication (2FA)**: RFC 6238 TOTP with Google Authenticator QR setup, single-use hashed recovery codes, temporary challenge tokens.
- **Advanced Financial Analytics**: Comprehensive Profit & Loss (P&L), component-level tax breakdown (CGST/SGST/IGST/VAT), stock valuation, shift & drawer cash variance audit.
- **Integrations & Data Exchange**: Outgoing signed webhooks with HMAC-SHA256 headers (`X-POS-Signature`), API access key management, and RFC 4180 Generic Accounting CSV export center (Sales Journal, General Ledger, Inventory Valuation).
- **Dynamic-Only UI (G12)**: 100% dictionary-driven localized UI text (English & Tamil), zero hardcoded literal business data.

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

Optional: `cd backend; php artisan demo:seed` creates a demo tenant (shop_code `DEMO`, owner/`demo-password-123`) through the real onboarding flow, with one priced, stocked product and one completed sale - useful for trying the API without building fixtures by hand.

Docker (alternative to the above, once Docker Desktop's daemon is running): `docker compose up --build` from the repo root starts the full stack (app, nginx, mysql, redis, frontend) per `docker-compose.yml`. See `docs/RUNBOOK.md` for the full deployment procedure and known Windows/XAMPP caveats.

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
