# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 3 — Customers, suppliers, shifts: **complete**, tagged `phase-3-complete`. See `docs/reports/phase-3.md` for full detail and real executed test output. Phase 4 starts next.

## Done (Phase 3, on top of Phases 0-2)
- 6 migrations for customers/groups, customer ledger/payments/allocations, loyalty accounts/transactions, suppliers + their ledger/payments/allocations, cashier shifts + cash movements, expense categories/expenses — all run clean against real MySQL.
- `CustomerLedgerService` / `SupplierLedgerService`: append-only ledger + derived balance (same shape as Phase 2's `StockService`), collections/payments, and explicit `allocate()` against a specific outstanding debit row with over-allocation rejected on either side (payment's remaining amount AND debit's outstanding amount).
- Since sales/purchases don't exist until Phase 4/5, debits this phase come from opening-balance/manual-adjustment entries - but allocation targets the ledger's own debit rows directly (D-0016), so a Phase 4 sale's debit will be allocatable through the exact same mechanism with zero schema change.
- `LoyaltyService`: earn/redeem, over-redemption rejected, balance == sum of transactions.
- `ShiftService`: one open shift per register enforced by a real DB constraint (`open_register_marker`, D-0018, not just an app-level check), the full expected-cash formula computed as a SUM over typed `cash_movements` rows, and closed-shift immutability enforced at the model level (`CashierShift::booted()` blocks any further save once `status` was already `closed`).
- `ExpenseService`: recording an expense against an open shift creates a matching cash movement automatically.
- 12 new backend tests (45 total), all passing for real; Pint/Larastan clean; Dynamic-Only Rule check still passes.

## Next 5 steps (start of Phase 4)
1. Pricing engine: write the PHP implementation against `shared/golden-vectors/*.json` (tax inclusive/exclusive, multi-component, discounts, rounding) FIRST, then the JS mirror — both must agree on every vector (section 9 "one pricing engine, tested twice").
2. Sale creation (`POST /sales` equivalent service): ignore client prices/totals (G1), lock stock via `StockService`, generate an invoice number under a locked sequence row, write sales/sale_items/sale_item_taxes/sale_discounts/invoices/sale_payments in one transaction.
3. Idempotency: `idempotency_keys` table + middleware (G6) - same key+payload replays, same key+different payload rejects. Test with a literal duplicate request.
4. Held bills, payment methods wiring (instant/manual_confirm/provider_confirm adapters), receipts.
5. Wire sale completion into `cash_movements` (`cash_sale` type) and `customer_ledger` (a credit-sale debit) - the two phases 3 decisions (D-0016, D-0017) this closes the loop on. Phase-4 report, tag `phase-4-complete`.

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
- No catalogue/inventory/customer/supplier/shift/expense frontend screens or HTTP controllers built yet (D-0015, D-0019) — Phases 2-3 were backend-only (services + tests), matching their own build lists.
- `cost_layers`/FIFO costing and `export_jobs` are schema-only; rule-based approval for stock adjustments isn't wired up (no rule evaluator exists yet).
- Customer/supplier ledger debits only come from manual entries so far (opening balances/adjustments) — Phase 4 (sales) and Phase 5 (purchases) will post their own debits into the same tables (D-0016); `cash_movements`' `cash_sale`/`cash_refund` rows are likewise manual until then (D-0017).

## Notes for the next session
This is a multi-week-scale build (8 phases, ~80 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
