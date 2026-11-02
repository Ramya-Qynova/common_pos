# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 12 — FIFO Costing Engine & Cost Layer Depletion Strategy (Release 2, Item 2): **complete** (tagged `phase-12-complete`).
Phases 0 through 12 are **complete** (tagged `phase-0-complete` through `phase-12-complete`). See `docs/reports/phase-12.md` for Phase 12 details.

## Done (Phase 12, on top of Phases 0-11)
- Dynamic Costing Strategy Architecture:
  - Strategy pattern with `CostingStrategyInterface`, `WeightedAverageCostingStrategy`, `FifoCostingStrategy`.
  - `CostingStrategyFactory`: Dynamically selects costing strategy from tenant setting `inventory.costing_method` (`'weighted_average'` vs `'fifo'`).
  - `CostLayer` model enhanced with relationships and decimal casts.
- Stock Movement Integration:
  - Inflow movements create cost layers when tenant is configured for FIFO.
  - Outflow movements deplete oldest cost layers first (`ORDER BY received_at ASC, id ASC`) with pessimistic locking (`lockForUpdate()`), computing blended COGS and updating inventory balance valuation.
- Cost Layers Inspection API & UI:
  - `GET /api/v1/inventory/cost-layers` endpoint returning active layers with quantities and costs.
  - `InventoryScreen.jsx` updated with "Cost Layers" inspection button and modal showing remaining layers.
- Quality Gates & Invariants:
  - 147 backend Pest tests (632 assertions) passing (3 new comprehensive tests in `FifoCostingTest.php`).
  - 44 frontend Vitest tests (11 test suites) passing.
  - Zero dynamic-only rule violations (`node scripts/check-dynamic-only.js`).
  - Pint, ESLint, and Vite production build clean. Tagged `phase-12-complete`.

## Done (Phase 11, on top of Phases 0-10)
- Stock Transfers & In-Transit Tracking:
  - Database schema & models (`StockTransfer`, `StockTransferItem`).
  - `StockTransferService`: Multi-item transfers, atomic dispatch decrements source stock at weighted average cost, receipt increments destination warehouse, transit discrepancy tracking, cancel with automatic stock reversal.
  - `StockTransferController` (`GET`, `POST`, `show`, `dispatch`, `receive`, `cancel`).
  - `TransfersScreen.jsx`: Transfer table with status badges, create modal (manual/auto dispatch), and receive modal with discrepancy input.
- Physical Stock Reconciliation / Stocktake:
  - Database schema & models (`StockReconciliation`, `StockReconciliationItem`).
  - `StockReconciliationService`: Automatic system quantity/cost snapshotting per warehouse, physical count recording with live difference/variance computation, atomic completion posting balancing compensatory movements (`in` for surplus, `out` for deficit) ensuring stock balance strictly equals physical count.
  - `StockReconciliationController` (`GET`, `POST`, `show`, `submitCounts`, `complete`, `cancel`).
  - `ReconciliationsScreen.jsx`: Stocktake management table, start modal, count audit sheet, and completion action.
- Quality Gates & Invariants:
  - 144 backend Pest tests (609 assertions) passing (10 new tests for transfers & reconciliation).
  - 43 frontend Vitest tests (11 test suites) passing.
  - Zero dynamic-only rule violations (`scripts/check-dynamic-only.js`).
  - Pint, ESLint, and Vite production build clean. Tagged `phase-11-complete`.

## Done (Phase 10, on top of Phases 0-9)
- Operational Management Screens:
  - `frontend/src/screens/SalesScreen.jsx`: Paginated sales list, date/invoice search, status filtering, and thermal receipt preview.
  - `frontend/src/screens/ReceiptModal.jsx`: Thermal receipt view with print action and line items breakdown.
  - `frontend/src/screens/ProductsScreen.jsx`: Product catalogue view with stock-on-hand, SKU search, and new product modal.
  - `frontend/src/screens/InventoryScreen.jsx`: Real-time stock balances, low-stock threshold alert (≤ 5 units), and stock adjustment modal.
  - `frontend/src/screens/CustomersScreen.jsx`: Customer profiles with credit limits, current balance tracking, and customer transaction ledger modal.
  - `frontend/src/screens/ReportsScreen.jsx`: Operational financial dashboard showing 7 core business metrics with date filters (today, 7 days, 30 days, all time).
- SPA Navigation & Routing:
  - Dynamic schema-driven menu via `AppShell.jsx` (G11/G12) resolving `config.menu` from `GET /tenant/config`.
  - Client-side routing in `App.jsx` for `/pos`, `/sales`, `/products`, `/catalog`, `/inventory`, `/customers`, and `/reports`.
- Backend Endpoints & Test Suite:
  - `GET /sales` & `GET /sales/{id}` (`SaleController`).
  - `POST /products` (`ProductController`).
  - `POST /customers` & `GET /customers/{id}/ledger` (`CustomerController`).
  - `GET /inventory/balances` (`InventoryController`).
  - `GET /reports/summary` (`ReportController`).
  - `ManagementEndpointsTest.php` covering all new endpoints.
- Quality Gates & Invariants:
  - 134 backend Pest tests (556 assertions) passing.
  - 41 frontend Vitest tests (11 test files) passing.
  - Zero dynamic-only rule violations (`node scripts/check-dynamic-only.js`).
  - Pint, ESLint, and Vite production build clean. Tagged `phase-10-complete`.

## Done (Phase 9, on top of Phases 0-8)
- POS billing screen (`frontend/src/screens/PosScreen.jsx`, POS-01): branch/register/price-list dynamic selectors from `GET /tenant/config`, shift open/close with real cash tracking, debounced product search, cart operations, optional customer attach, and checkout.
- Offline outbox checkout pipeline: checkout routes through `useOffline().enqueueSale -> outbox.enqueue + SyncClient.flush` unifying online and offline checkout paths (D-0053).
- SPA Routing & AppShell: React Router DOM integration with `<Outlet context={config}>`, global `ConnectionBadge` (online/offline/syncing status + manual sync button).
- Device identity (`frontend/src/offline/device.js`): persistent device UUID generated and supplied at login.
- Backend additions: `ProductController` (`GET /products`), `CustomerController` (`GET /customers`), `ShiftController` (`GET /shifts/current`, `POST /shifts/open`, `POST /shifts/{shift}/close`), extended `TenantConfigController`.
- Bug fix (D-0051): Shift-close confirmation render branch corrected.
- 128 backend tests / 36 frontend tests passing. Pint, Larastan, and Dynamic-Only rule check clean. Tagged `phase-9-complete`.

## Done (Phase 8, on top of Phases 0-7)
- Security (section 13.1): 7 new tests proving token rotation/replay (refreshing revokes the old access token for real), rate limiting (429 after the throttle limit), SQL-injection-shaped input rejected like any bad credential, XSS-shaped input round-trips as inert data, secrets never appear in `GET /auth/me`, and IDOR (another tenant's sale ID resolves to 404, never a 403 that would leak existence). Lockout and tenant isolation already had dedicated coverage since Phase 1.
- Performance (section 11), measured for real, not assumed: `POST /sales` p95 ~67-117ms (target < 800ms, met with margin); full snapshot pull for 2,001 products 0.29s (target < 60s, met with margin - see the fix below); sync push throughput measured at 6-14 ops/s against a target of 100 ops/s per worker - genuinely below target, root cause and remediation documented rather than hidden (D-0044). Local-search/add-to-cart targets documented as not measurable without a POS UI (D-0045).
- A real N+1 query bug found BY the performance test and fixed (D-0043): `SyncPullService` was querying prices once per product; fixed to one query for the whole batch, grounded by hand - 2.48s -> 0.29s for the same 2,001 products.
- Backup/restore, executed for real (not just scripted) against the live database (seeded via the new `php artisan demo:seed` through the real onboarding flow): `scripts/{backup-db,restore-db,verify-backup-restore}.ps1` - backed up, restored into a disposable database, compared all 121 tables' row counts, re-derived the G5 stock-ledger invariant on the restored copy, all passed.
- A real environment bug found and fixed along the way, not application code but worth recording (D-0047): `php artisan sail:install` silently corrupted `backend/.env` (pointing `DB_HOST` at `mysql`, among other things) before failing to write its own files on this Windows machine - caught because the test suite started hanging on DNS resolution failures, fixed by restoring `.env` from `.env.example`.
- `GET /api/v1/health` (monitoring), `docker-compose.yml` + `backend/Dockerfile` + `frontend/Dockerfile` + nginx configs (hosting - syntax-validated, honestly could not be run end-to-end in this sandboxed environment, D-0046), `docs/RUNBOOK.md` filled in for real (was a Phase 0 placeholder).
- 11 new backend tests (122 total), all passing for real; Pint/Larastan/Dynamic-Only clean; frontend suite unaffected (backend-only phase).

## Done (Phase 7, on top of Phases 0-6)
- `SyncPushService`: every one of section 13.2's ten offline-gate scenarios proved by a real test against `POST /sync/push` - idempotent replay by `operation_uuid`, two devices selling the last unit (`STOCK_SHORTFALL`, both accepted), a stale device price reconciled against the server total (`PRICE_MISMATCH`, server total always wins - G1, D-0037), a revoked device's pre-/post-revocation operations split by `client_created_at` vs `revoked_at` (D-0035), a suspended tenant's sales held not discarded, device-scoped offline numbering (finally exercising Phase 1/D-0020's `numbering_sequences.device_key`), clock-skew flagging, a 20-operation batch, and rejected/held operations staying visible via the new `GET /sync/operations`.
- A real bug found via a failing test, not assumed (D-0034): the shared `tenant.active` route gate blocked a suspended tenant from `/sync/push` entirely, so `SyncPushService` could never even run its own "hold for review" logic. Fixed by moving `/sync/push` one level up in `routes/api.php`, outside that gate.
- `SaleService::create()` gained two additive, backward-compatible parameters (`$deviceId`, `$forceAllowNegativeStock`) - every existing Phase 4-6 test still passes unchanged; only `SyncPushService` ever passes them.
- `SyncPullService` (`GET /sync/pull`): full snapshot or incremental-since-version, backed by a new `change_log` table deliberately scoped to `Product`/`ProductPrice` writes only (D-0036) - proven to pick up both a new product and a price-only change.
- `DeviceService`/`DeviceController`: list + revoke (devices already auto-register at login since Phase 1).
- The frontend offline module - `frontend/src/offline/{db,outbox,syncClient,snapshot,crypto}.js` - built on Dexie (a Phase 0 dependency never wired in until now) and genuinely tested against real IndexedDB (`fake-indexeddb` under Vitest): a durable outbox that survives a simulated restart, exponential backoff with jitter, full/incremental snapshot application, a full local-data wipe, and Web Crypto AES-GCM field encryption with a device key. Not yet imported by any screen (D-0040) - no POS billing UI exists yet for it to serve.
- 15 new backend tests (111 total) + 17 new frontend tests (31 total), all passing for real; Pint/Larastan/Dynamic-Only clean.

## Done (Phase 6, on top of Phases 0-5)
- `grocery` template added to `TemplatesSeeder` as pure data (weighing + batch_expiry modules, kg/gram/pcs units, Vegetables/Fruits/Leafy-Greens categories, a `wastage_reason` lookup, a tenant-level override enabling `pos.variable_weight_barcode`) - built on the exact same `general_retail` template engine, zero new code paths.
- `TemplateUpdateService::preview()`/`apply()`: the "template snapshot + update preview" mechanism named in section 12 - diffs a tenant's actual rows against its template's latest published data and additively (never destructively) creates whatever's missing; the same call both finishes a fresh onboarding's catalogue data AND picks up a newer published template version later (proved by publishing a real v2 mid-test).
- `VariableWeightBarcodeService`: decodes a scanned barcode's embedded weight using the tenant's `pos.variable_weight_barcode` setting (never a hardcoded format); gated by the `weighing` module flag via `Tenant::isModuleEnabled()` (built Phase 1, exercised for real for the first time) - not a template-name check. A plain fixed barcode still resolves by exact match for every tenant regardless of that module.
- Proved the exit gate directly: a weight-derived fractional quantity flows into the unmodified Phase 4 `SaleService`/`PricingEngine` with no grocery-specific sale code at all (G3's decimal-string handling already supports it).
- `PriceListService::bulkSetPrices()` (daily rate update) and `StockAdjustmentController` (wastage's first HTTP route, wrapping Phase 2's already-generic `StockAdjustmentService`) - both proved to work identically on a `general_retail` tenant as a `grocery` one.
- `HardwareProfileService`/`HardwareProfileController`: CRUD for `hardware_profiles` (Phase 1, schema-only until now).
- A real, zero-regression-risk design choice found while building this (D-0029): template catalogue-data application deliberately lives in `TemplateUpdateService`, not `OnboardingService::createTenant()` itself, because 11 existing test fixtures manually create `Unit` rows that would collide with auto-created ones.
- 13 new backend tests (96 total), all passing for real; Pint/Larastan/Dynamic-Only clean; frontend suite unaffected (backend-only phase).

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

## Next steps (post-Phase-8: Final Acceptance, then Release 2 per section 2 if capacity remains)
1. Write `docs/reports/FINAL.md` (section 15 Final Acceptance) - run through every bullet in section 15 with evidence, list what's complete/partial, and surface every DECISIONS.md entry a human should review.
2. Build actual POS/catalogue/inventory/customer/etc. frontend screens (D-0015/D-0019/D-0022/D-0025/D-0032/D-0040/D-0041) - the single biggest remaining gap: every phase since Phase 2 has been backend-only by deliberate scope, and the API surface is now extensive enough that a real UI pass is the natural next body of work.
3. Wire the Phase 7 offline module into those screens once they exist (outbox-first writes per section 9), and move auth tokens from plain `localStorage` onto the `crypto.js` device key (D-0011).
4. Carry-overs: exchange service (D-0025), export-center execution (D-0025), a minimal `discount_rules`/`approval_rules` evaluator (D-0021), queue-dispatched sync push workers if throughput becomes a real constraint (D-0044).
5. A real Docker daemon run of `docker compose up --build` to close D-0046's one remaining gap (syntax-validated only, not run end-to-end in this sandboxed build environment).

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
- Frontend auth tokens live in plain `localStorage` (D-0011) - Phase 7 built a real, tested Web Crypto device-key encryption primitive (`frontend/src/offline/crypto.js`) but has not yet rewired token storage onto it; that's a small, additive follow-up now that the primitive exists.
- No catalogue/inventory/customer/supplier/shift/expense/POS frontend screens or UI built yet (D-0015, D-0019, D-0022) — every phase since Phase 2 has been backend-only (services + tests + HTTP API where applicable), matching each phase's own build list; a frontend pass against this now-substantial API surface is still pending.
- No payment-provider adapters (manual_confirm, provider_confirm/webhooks), no receipt rendering (D-0022).
- `cost_layers`/FIFO costing and `export_jobs` are schema-only; discount/approval rule conditions (`discount_rules` JSON) aren't evaluated, only the `pos.sales.discount` permission check (D-0021).
- No `exchange` service, no export-center job execution, no notification/alert delivery (`alert_rules` still schema-only) (D-0025).
- Damaged-condition sale returns are a write-off with no dedicated damaged-stock location (D-0024); `ReportService::outstanding()` has no ageing-bucket breakdown (D-0026); PO receiving matches lines by product+variant rather than a specific PO line ID (D-0027); `DailyClosingService::reopen()` has no automatic reversing-entry mechanism for post-reopen corrections (D-0028).
- `OnboardingService::createTenant()` still only applies modules+roles - a tenant's template-provided units/categories/lookups/settings only exist after an explicit `TemplateUpdateService::apply()` call (D-0029, by design, not yet wired into onboarding itself).
- No EAN-13 check-digit validation on variable-weight barcodes (D-0031); no frontend hardware adapter implementations (ReceiptPrinter/WeighingScale/BarcodeScanner device-talking code) - `hardware_profiles` is server-config CRUD only (D-0032).
- No envelope hash/tamper verification on sync operations (D-0038); tenant-suspension check doesn't separately model subscription expiry/grace (D-0039); only `sale.create` has a real sync push handler, every other `offline.allowed_operations` type is rejected with `TYPE_NOT_ALLOWED_OFFLINE` (D-0042).
- The frontend offline module (outbox/syncClient/snapshot/crypto) is built and genuinely tested but not imported by any screen yet - no POS billing UI exists at all (D-0040); no PWA/service-worker registration despite `vite-plugin-pwa` being installed since Phase 0, no auto-lock/PIN screen (D-0041).
- Device revocation does not revoke a device's existing tokens or block it from every other endpoint immediately - only `/sync/push` discriminates pre-/post-revocation per operation (D-0035); a broader immediate-lockout gate is a reasonable defense-in-depth addition, not yet built.
- Sync push throughput measured at 6-14 ops/s against a 100 ops/s target - root cause is synchronous per-request processing, remediation is queue-dispatched workers (D-0044). Local-search/add-to-cart performance targets not measurable without a POS UI (D-0045).
- `docker compose up --build` was never run end-to-end - Docker Desktop's daemon could not start in this build environment; the compose file and Dockerfiles are syntax-validated only (D-0046).
- No external APM/log-aggregation/alerting vendor integrated - `GET /health` + existing `audit_logs`/`security_events` + `storage/logs/laravel.log` are the real signals this build produces (D-0048).
- Security test coverage (section 13.1) is targeted confirmation testing of mechanisms already built, not a full penetration test or SAST/DAST scan (D-0049).

## Notes for the next session
This is a multi-week-scale build (8 phases, ~90 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
