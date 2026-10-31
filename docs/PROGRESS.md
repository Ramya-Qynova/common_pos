# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 4 — Pricing engine & sales: **complete**, tagged `phase-4-complete`. See `docs/reports/phase-4.md` for full detail and real executed test output. Phase 5 starts next.

## Done (Phase 4, on top of Phases 0-3)
- Dual pricing engine: `backend/app/Services/Pricing/PricingEngine.php` (bcmath) and `frontend/src/pricing/pricingEngine.js` (decimal.js, intermediate values rounded to match bcmath's scale at every step) - both pass all 8 vectors in `shared/golden-vectors/*.json`, run by two independent test suites against the identical files. Per-line tax detail added (needed for `sale_items`), verified to sum back to the invoice-level totals.
- `SaleService::create()`: the full section-8 "POS sale (online)" sequence - server-resolved prices/tax (G1, no client price is ever read), stock locked via `StockService` before anything is written, invoice number from a (now actually race-safe - see below) locked sequence row, payments validated (credit-limit check, overpayment-method handling), cash sales wired into Phase 3's `ShiftService` cash movements, credit sales wired into Phase 3's `CustomerLedgerService`.
- `EnsureIdempotent` middleware (G6): required `Idempotency-Key` header, same key+payload replays the stored response, same key+different payload rejected, concurrent same-key requests don't race into two sales.
- A real latent bug found and fixed: `numbering_sequences` (built in Phase 1) had no uniqueness constraint at all - two concurrent sales in a brand-new series could have handed out duplicate invoice numbers. Fixed with a new migration (not an edit to the Phase 1 file), same nullable-bucket-column pattern used elsewhere.
- Discount permission gating: a user without `pos.sales.discount` requesting any discount is blocked with `APPROVAL_REQUIRED` and a real `approval_requests` row is created; a permitted user (e.g. the seeded owner role) completes the discounted sale directly.
- `HeldSaleService`: hold/resume, storing only product/variant/unit/quantity (never a price) so resuming re-prices through the exact same `SaleService::create()` path.
- 19 new backend tests (64 total) + the JS pricing engine test (14 frontend tests total), all passing for real; Pint/Larastan clean; Dynamic-Only Rule check still passes.

## Next 5 steps (start of Phase 5)
1. Cancellations/returns/refunds/exchanges: reverse stock (via `StockService`, resellable items back at original `cost_snapshot`), reverse customer-ledger credit-sale debits, generate credit notes.
2. Purchasing: purchase orders, receiving (posts a `supplier_ledger` debit - closes the loop D-0016 flagged), purchase returns.
3. Daily close/reopen (`finance.reopen` permission), report formulas from section 8.13 against hand-computed fixtures.
4. Export center, notifications/alerts.
5. Consider closing D-0021 (a minimal rule evaluator for `discount_rules`/`approval_rules`) if time allows - it would benefit Phase 5's return/cancellation approval flows too, not just discounts.

## How to run tests
- Backend: `cd backend && php artisan test`; style: `php vendor/bin/pint --test`; static analysis: `php vendor/bin/phpstan analyse --no-progress`
- The real-MySQL concurrency test (`StockConcurrencyTest`) needs a local MySQL reachable at `127.0.0.1:3306` as `root`/`root` with a `common_pos` database already migrated — set `STOCK_LOCK_TEST_DB` to point elsewhere if needed.
- Frontend: `cd frontend && npm run test` (Vitest + RTL, includes the JS pricing engine golden-vector test); `npm run lint`; `npm run build`
- Dynamic-Only static check: `node scripts/check-dynamic-only.js`
- Env check: `pwsh scripts/check-env.ps1` (or `powershell -File scripts/check-env.ps1` if `pwsh` isn't on PATH)

## Known issues
- PHP available locally is 8.2.12, below the prompt's 8.3 minimum (D-0001) — not a functional blocker.
- `gh` CLI not installed; using plain `git` via the existing Git Credential Manager (D-0002).
- Frontend toolchain pinned to Vite 6 / Vitest 2 / ESLint 9 instead of newer defaults that broke on this machine's Node 22.14 (D-0007).
- Platform guard has no login/logout HTTP endpoints yet (only the auth mechanism) and still uses `Auth::viaRequest`, unlike the tenant guard — fix when platform endpoints are built (D-0009).
- `GET /tenant/config` doesn't yet return translations/tax/payment-method/document-template data.
- Numbering (now race-safe, D-0020), tax, payment methods, lookups, custom fields, document templates, hardware profiles, offline policies, rule tables (no evaluator yet, D-0021), and most of the approval framework have schema + models only beyond what Phase 4 actually uses (discount approval requests).
- Frontend auth tokens live in plain `localStorage` (D-0011) pending Phase 7's encrypted IndexedDB mechanism.
- No catalogue/inventory/customer/supplier/shift/expense/POS frontend screens or UI built yet (D-0015, D-0019, D-0022) — every phase since Phase 2 has been backend-only (services + tests + HTTP API where applicable), matching each phase's own build list; a frontend pass against this now-substantial API surface is still pending.
- No payment-provider adapters (manual_confirm, provider_confirm/webhooks), no receipt rendering (D-0022).
- `cost_layers`/FIFO costing and `export_jobs` are schema-only; discount/approval rule conditions (`discount_rules` JSON) aren't evaluated, only the `pos.sales.discount` permission check (D-0021).

## Notes for the next session
This is a multi-week-scale build (8 phases, ~90 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
