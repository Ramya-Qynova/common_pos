# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 2 — Catalogue & inventory: **complete**, tagged `phase-2-complete`. See `docs/reports/phase-2.md` for full detail and real executed test output. Phase 3 starts next.

## Done (Phase 2, on top of Phases 0-1)
- 6 migrations for products/variants/units/barcodes/categories/brands, price lists + history, stock balances/ledger/batches/serials/cost-layers, stock adjustments, import/export jobs — all run clean against real MySQL.
- `StockService`: the one lock→ledger→balance pattern (section 8), weighted-average costing, consistent multi-line lock ordering (deadlock-avoidance), INSUFFICIENT_STOCK blocking (bypassable via a setting).
- A real NULL-uniqueness bug caught before shipping (`variant_key`/`batch_key` sentinel columns — D-0013), and a real concurrency proof against actual MySQL row locks (D-0014), not a simulated one — this is what the "two concurrent decrements" exit-gate line actually rests on.
- `StockAdjustmentService`, `ProductService`, `PriceListService` (history-preserving price changes), `ProductImportService` (every rejected row reported with its reason).
- Low-stock detection.
- 13 new backend tests (35 total), all passing for real; Pint/Larastan clean; Dynamic-Only Rule check still passes.

## Next 5 steps (start of Phase 3)
1. Migrations: `customers`, `customer_groups`, `customer_ledger`, `customer_payments`, `customer_payment_allocations`, `loyalty_accounts`, `loyalty_transactions`, `suppliers`, `supplier_ledger`, `supplier_payments`, `supplier_payment_allocations`, `cashier_shifts`, `cash_movements`, `expenses`, `expense_categories`.
2. Write the ledger-invariant tests FIRST (customer/supplier balance == sum of ledger entries) before the services, same pattern as Phase 2's stock work.
3. `CustomerLedgerService` / `SupplierLedgerService`: collections + payment allocation (over-allocation rejected), advances as unallocated credit — mirrors `StockService`'s append-only-ledger-plus-derived-balance shape.
4. `ShiftService`: one open shift per register (enforced), expected-cash formula (opening + cash in − cash out − cash refunds − cash expenses + cash received net of change), closed shifts immutable.
5. Expenses/cash movements, phase-3 report, tag `phase-3-complete`.

## How to run tests
- Backend: `cd backend && php artisan test`; style: `php vendor/bin/pint --test`; static analysis: `php vendor/bin/phpstan analyse --no-progress`
- The real-MySQL concurrency test (`StockConcurrencyTest`) needs a local MySQL reachable at `127.0.0.1:3306` as `root`/`root` with a `common_pos` database already migrated — set `STOCK_LOCK_TEST_DB` to point elsewhere if needed.
- Frontend: `cd frontend && npm run test` (Vitest + RTL); `npm run lint`; `npm run build`
- Dynamic-Only static check: `node scripts/check-dynamic-only.js`
- Env check: `pwsh scripts/check-env.ps1` (or `powershell -File scripts/check-env.ps1` if `pwsh` isn't on PATH)

## Known issues
- PHP available locally is 8.2.12, below the prompt's 8.3 minimum (D-0001) — not a functional blocker.
- `gh` CLI not installed; using plain `git` via the existing Git Credential Manager (D-0002).
- Frontend toolchain pinned to Vite 6 / Vitest 2 / ESLint 9 instead of newer defaults that broke on this machine's Node 22.14 (D-0007).
- Platform guard has no login/logout HTTP endpoints yet (only the auth mechanism) and still uses `Auth::viaRequest`, unlike the tenant guard — fix when platform endpoints are built (D-0009).
- `GET /tenant/config` doesn't yet return translations/tax/payment-method/document-template data.
- Numbering, tax, payment methods, lookups, custom fields, document templates, hardware profiles, offline policies, rule tables (no evaluator yet), and approvals have schema + models only, no service logic.
- Frontend auth tokens live in plain `localStorage` (D-0011) pending Phase 7's encrypted IndexedDB mechanism.
- No catalogue/inventory frontend screens or HTTP controllers built yet (D-0015) — Phase 2 was backend-only (services + tests), matching its own build list.
- `cost_layers`/FIFO costing and `export_jobs` are schema-only; rule-based approval for stock adjustments isn't wired up (no rule evaluator exists yet).

## Notes for the next session
This is a multi-week-scale build (8 phases, ~80 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
