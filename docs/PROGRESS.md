# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 5 — Returns, purchasing, daily close, reports: **complete**, tagged `phase-5-complete`. See `docs/reports/phase-5.md` for full detail and real executed test output. Phase 6 starts next.

## Done (Phase 5, on top of Phases 0-4)
- `SaleCancellationService`: reverses stock/payment/customer-ledger within `sales.cancel_window`, gated by `pos.sales.cancel` + the same APPROVAL_REQUIRED/approval_requests pattern Phase 4 built for discounts.
- `SaleReturnService`: requested → inspected → completed state machine (inspection skipped when `returns.require_inspection` is false); "cannot exceed sold" enforced twice (fail-fast at request, authoritative row-locked check at completion); refund computed from the ORIGINAL sale_item values, never current prices; resellable items restock at `cost_snapshot`, damaged items are a write-off (D-0024); cash refunds post to the sale's shift, credit refunds reverse the customer-ledger debit.
- A real bcmath truncation bug found and fixed via the report fixture test (D-0023): proportional refund/report calculations now multiply before dividing, not the other way round.
- Purchasing: `PurchaseOrderService` (draft/ordered), `PurchaseReceivingService` (one transaction: stock in at received cost, PO status advancement, a real `supplier_ledger` debit — finally closing D-0016/D-0022's loop), `PurchaseReturnService` (mirrors the sale-return "cannot exceed" guarantee on the payable side).
- `DailyClosingService`: close (blocked while any shift on the branch is open) / reopen (gated by `finance.reopen`).
- `ReportService`: all 8 section-8.13 figures (gross/net sales, tax collected, COGS, gross profit, stock valuation, outstanding, expected cash), each verified against a hand-computed fixture in `tests/Feature/Reports/ReportServiceTest.php`.
- 19 new backend tests (83 total), all passing for real; Pint/Larastan clean; Dynamic-Only Rule check still passes; frontend suite unaffected (backend-only phase).

## Done (Phase 4, on top of Phases 0-3)
- Dual pricing engine: `backend/app/Services/Pricing/PricingEngine.php` (bcmath) and `frontend/src/pricing/pricingEngine.js` (decimal.js, intermediate values rounded to match bcmath's scale at every step) - both pass all 8 vectors in `shared/golden-vectors/*.json`, run by two independent test suites against the identical files. Per-line tax detail added (needed for `sale_items`), verified to sum back to the invoice-level totals.
- `SaleService::create()`: the full section-8 "POS sale (online)" sequence - server-resolved prices/tax (G1, no client price is ever read), stock locked via `StockService` before anything is written, invoice number from a (now actually race-safe - see below) locked sequence row, payments validated (credit-limit check, overpayment-method handling), cash sales wired into Phase 3's `ShiftService` cash movements, credit sales wired into Phase 3's `CustomerLedgerService`.
- `EnsureIdempotent` middleware (G6): required `Idempotency-Key` header, same key+payload replays the stored response, same key+different payload rejected, concurrent same-key requests don't race into two sales.
- A real latent bug found and fixed: `numbering_sequences` (built in Phase 1) had no uniqueness constraint at all - two concurrent sales in a brand-new series could have handed out duplicate invoice numbers. Fixed with a new migration (not an edit to the Phase 1 file), same nullable-bucket-column pattern used elsewhere.
- Discount permission gating: a user without `pos.sales.discount` requesting any discount is blocked with `APPROVAL_REQUIRED` and a real `approval_requests` row is created; a permitted user (e.g. the seeded owner role) completes the discounted sale directly.
- `HeldSaleService`: hold/resume, storing only product/variant/unit/quantity (never a price) so resuming re-prices through the exact same `SaleService::create()` path.
- 19 new backend tests (64 total) + the JS pricing engine test (14 frontend tests total), all passing for real; Pint/Larastan clean; Dynamic-Only Rule check still passes.

## Next 5 steps (start of Phase 6)
1. Vegetable/Grocery template (section 12 Phase 6): weighing-unit support, variable-weight barcodes, template-specific catalogue defaults, dynamic pricing per kg/unit.
2. Offline sync engine groundwork (section 9): IndexedDB snapshot/outbox on the frontend, `GET /sync/pull`/`POST /sync/push` endpoints, operation envelope + idempotent replay.
3. Exchange service (D-0025) — compose `SaleReturnService::complete()` + `SaleService::create()` in one transaction, settling the difference by payment or refund.
4. Export center (D-0025) — make `export_jobs` actually run (currently schema-only since Phase 2) and route large reports through it per section 8.13.
5. Consider closing D-0021 (a minimal rule evaluator for `discount_rules`/`approval_rules`) — would also unlock the `discount_rules` size-based auto-approval half of Phase 4's gating and any Phase 5+ approval flow that wants data-driven conditions instead of a flat permission check.

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
- No `exchange` service, no export-center job execution, no notification/alert delivery (`alert_rules` still schema-only) (D-0025).
- Damaged-condition sale returns are a write-off with no dedicated damaged-stock location (D-0024); `ReportService::outstanding()` has no ageing-bucket breakdown (D-0026); PO receiving matches lines by product+variant rather than a specific PO line ID (D-0027); `DailyClosingService::reopen()` has no automatic reversing-entry mechanism for post-reopen corrections (D-0028).

## Notes for the next session
This is a multi-week-scale build (8 phases, ~90 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
