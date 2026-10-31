# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 7 — Offline engine: **complete**, tagged `phase-7-complete`. See `docs/reports/phase-7.md` for full detail and real executed test output. Phase 8 (security/performance/backup/hosting) starts next.

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

## Next 5 steps (start of Phase 8)
1. Security tests (section 13.1): SQL injection, XSS, IDOR, token rotation/replay, rate limiting, lockout, secrets never in responses - most of the mechanisms exist (parameterized queries throughout, `AuthenticateTenant`'s per-request revalidation, `auth.max_failed_logins`) but have never been tested adversarially as a dedicated pass.
2. Performance tests vs section 11's targets (local search < 100ms, add-to-cart < 50ms, `POST /sales` p95 < 800ms, sync push ≥ 100 ops/s, initial snapshot < 60s) - measure against realistic volumes, document actual numbers and gaps per the exit gate's own "or exceptions documented."
3. Backup/restore verification - a real backup restored into a clean environment with integrity checks passing (Final Acceptance's own explicit requirement).
4. Docker/hosting + `docs/RUNBOOK.md`.
5. Carry-overs if time allows: exchange service (D-0025), export-center execution (D-0025), a minimal `discount_rules`/`approval_rules` evaluator (D-0021), wiring the Phase 7 offline module into an actual POS screen (D-0040) and the `crypto.js` device key into token storage (replacing D-0011's plain `localStorage`).

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

## Notes for the next session
This is a multi-week-scale build (8 phases, ~90 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
