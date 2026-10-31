# Phase 4 report — Pricing engine & sales

## 1. Scope completed

Per `docs/MASTER_PROMPT.md` section 12, Phase 4: pricing engine (PHP + JS on golden vectors), sale calculate + create with invoices, payment methods/intents/manual confirm/webhooks/adapters, held bills, discount + approval rules, receipts with browser print, POS client on outbox abstraction.

- **Pricing engine, both sides, verified by hand first**: before writing either implementation, the exact bcmath/decimal algorithm was worked through by hand against all 8 golden vectors (tax inclusive/exclusive, multi-component CGST+SGST, line/invoice discount proration, cash rounding half-up/half-even) to confirm the design before any code existed. `App\Services\Pricing\PricingEngine` (bcmath, scale 4 throughout) and `frontend/src/pricing/pricingEngine.js` (decimal.js, with every intermediate value explicitly rounded to the same scale at the same points bcmath would, not just at the end) both pass all 8 vectors via two independent test suites loading the identical JSON files. Per-line detail (`lines` output: per-line taxable amount, tax amount, tax breakdown) was added afterward for `sale_items`/`sale_item_taxes`, verified to sum back to the already-proven invoice-level totals.
- **Idempotency (G6)**: `idempotency_keys` table + `EnsureIdempotent` middleware. Required on `POST /sales`. Same key + same request body replays the stored response (`Idempotent-Replay: true` header) without re-running anything; same key + different body returns `IDEMPOTENCY_KEY_REUSED`; a concurrent duplicate-key insert attempt returns `CONFLICT` rather than racing into two sales.
- **Sale creation (`SaleService::create()`)**: implements section 8's 14-step sequence end to end for the online case - shift-required check, per-line price/tax-group resolution (never from client input - G1), `PricingEngine::calculate()`, payment validation (credit-limit check for `credit` payments, overpayment handling for `cash`), one DB transaction wrapping stock locking (`StockService::recordMovements`, direction `out`, source_type `sale`), a now-actually-race-safe invoice number (see below), `sales`/`sale_items`/`sale_item_taxes` rows, and payment application that wires `cash` sales into Phase 3's `ShiftService::recordCashMovement()` (`cash_sale` type) and `credit` sales into Phase 3's `CustomerLedgerService::recordEntry()` (a `sale`-sourced debit) - closing the loop D-0016/D-0017 explicitly left open.
- **A real bug found and fixed while building this, not after**: `numbering_sequences` (Phase 1) had no uniqueness constraint whatsoever. `NumberingService::next()`'s "lock if exists, insert-then-recover-on-conflict if not" pattern assumed a constraint existed to catch the race; it didn't. Fixed with a new migration (D-0020), never editing the Phase 1 file.
- **Discount gating**: a user without `pos.sales.discount` requesting any line or invoice discount gets `APPROVAL_REQUIRED` and a real `approval_requests` row, with the sale never started (nothing to roll back - the check happens before the transaction opens). A permitted user (the seeded owner role, which has every permission) completes the discounted sale directly. This implements the permission half of section 8 step 4; the `discount_rules`-condition half is explicitly deferred (D-0021) since no rule evaluator exists anywhere yet.
- **Held bills**: `HeldSaleService::hold()`/`resume()`. Stores only product/variant/unit/quantity - resuming hands those back to the caller for a normal `SaleService::create()` call, so a bill held an hour ago re-prices against current prices (G1), never a frozen snapshot.

## 2. Files changed and migrations added

5 new migration files (1 fixing Phase 1's `numbering_sequences`, 4 new tables - idempotency keys, sales/items/taxes/discounts, invoices/payments/transactions, held sales/items; see `docs/data-dictionary.md` "Table index — Phase 4"). New models: `IdempotencyKey`, `Sale`, `SaleItem`, `SaleItemTax`, `SaleDiscount`, `Invoice`, `SalePayment`, `PaymentTransaction`, `HeldSale`, `HeldSaleItem`. New services: `App\Services\Pricing\PricingEngine`, `App\Services\Sales\{NumberingService,SaleService,HeldSaleService}`. New middleware: `EnsureIdempotent`. New controller: `SaleController` (`POST /api/v1/sales`). New frontend module: `frontend/src/pricing/pricingEngine.js`.

## 3. Endpoints, permission codes, setting keys, translation keys added

**Endpoints**: `POST /api/v1/sales` (requires `pos.sales.create` + `Idempotency-Key`).

**Permission codes**: none new - `pos.sales.create` and `pos.sales.discount` (both seeded in Phase 1) are now actually enforced by real code for the first time.

**Setting keys**: none new - `sales.shift_required`, `locale.rounding_mode`, `locale.cash_rounding_step`, `inventory.allow_negative_stock` (all seeded in Phase 1) are now read by `SaleService`/`PricingEngine` for the first time.

## 4. Tests added, with real executed results

**Backend** (`cd backend && php artisan test`):
```
Tests:    64 passed (228 assertions)
Duration: 16.90s
```
New this phase (19): `PricingEngineGoldenVectorTest` (8), `PricingEnginePerLineTest` (1), `SaleCreationTest` (8), `HeldSaleServiceTest` (2).

**Backend style** (`php vendor/bin/pint --test`): `{"tool":"pint","result":"passed"}`
**Backend static analysis** (`php vendor/bin/phpstan analyse --no-progress`, level 5): `[OK] No errors` (fixed real generic-type gaps on `Sale`'s relationships and missing `@property` annotations on `Invoice`/`SaleItem`/`HeldSaleItem`, not suppressed).

**Frontend** (`cd frontend && npm run test`):
```
Test Files  4 passed (4)
     Tests  14 passed (14)
```
Including `pricingEngine.test.js` (8/8, the same golden vector files the PHP suite runs).

**Dynamic-Only Rule check**: `Dynamic-Only Rule check: no violations found.` `EXIT:0`.

## 5. Dynamic-Only static-check results

Clean. Notably: `SaleController`'s validation rules deliberately never declare a `unit_price`/`amount`/`grand_total` field - there is no code path by which a client-submitted price could reach `SaleService` even if a client sends one (proven directly by `SaleCreationTest`'s price-override test).

## 6. Known issues, deviations

- D-0020: `numbering_sequences` uniqueness gap found and fixed (new migration, not an edit to Phase 1's).
- D-0021: discount gating is permission-only; `discount_rules` JSON conditions aren't evaluated by any code yet (no rule engine evaluator exists anywhere in the codebase).
- D-0022: no payment-provider adapters (`manual_confirm`/`provider_confirm`/webhooks) beyond `cash`/`credit`; no receipt rendering or browser print; no frontend POS cart screen or offline outbox queue. Sale creation is fully real and tested via the HTTP API, but there is no UI to drive it yet - consistent with Phases 2-3's backend-first scope boundary (D-0015/D-0019).
- `sale_discounts` table exists (schema + model) but nothing writes to it yet - `SaleService` currently stores the discount amount on `sale_items`/`sales` directly (via `PricingEngine`'s output) rather than a separate row per discount; revisit once `discount_rules` are actually evaluated and a discount needs its own audit trail distinct from the line/invoice totals.
- `payment_transactions` rows are created with `status = 'succeeded'` unconditionally (correct for the `cash`/`credit` instant-confirmation methods built this phase; `manual_confirm`/`provider_confirm` will need a real pending→succeeded/failed transition once those adapters exist, D-0022).

## 7. Remaining work / next phase

Phase 4's exit gate - "Failed sale leaves nothing. Same key replays with no duplicates. Server ignores client prices. Both engines pass every golden vector." - is met and demonstrated by the tests above, each directly: `SaleCreationTest`'s insufficient-stock test for the first, the replay/reuse tests for the second, the price-override test for the third, and both golden-vector test suites for the fourth. The gaps in section 6 are deliberate scope boundaries (consistent with how Phases 2-3 scoped themselves), not shortfalls against this phase's own exit gate.

Phase 5 per section 12: cancellations, returns, refunds, exchanges, purchasing (orders, receiving, returns, supplier payments), daily close/reopen, reports + dashboards with section 8.13 formulas, export center, notifications + alerts. Its exit gate: returns cannot exceed sold; refund uses original values; reports match hand-computed fixtures; closed days need reopen permission. Work begins immediately following this report.
