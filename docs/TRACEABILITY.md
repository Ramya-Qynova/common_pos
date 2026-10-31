# TRACEABILITY.md

Maps each requirement to the code that implements it and the test that proves it, per `docs/MASTER_PROMPT.md` section 15. Populated incrementally as each phase lands — a row is added in the same commit as the code/test it documents, never retrofitted later from memory.

## Phase 0

| Requirement | Code | Test / evidence |
|---|---|---|
| `scripts/check-env.ps1` verifies PHP/Composer/MySQL/Node per section 5 | [scripts/check-env.ps1](../scripts/check-env.ps1) | Executed; see `docs/reports/phase-0.md` for real output |
| Dynamic-Only Rule CI/static check (section 4) fails on role-name literals, currency/tax literals, literal UI text | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed against a deliberately injected violation (both backend and frontend), confirmed fail, violation removed, confirmed pass — real output in `docs/reports/phase-0.md` |
| Golden vectors: tax inclusive/exclusive, multi-component, line/invoice discount, cash rounding (section 0, section 9) | [shared/golden-vectors/tax.json](../shared/golden-vectors/tax.json), [discounts.json](../shared/golden-vectors/discounts.json), [rounding.json](../shared/golden-vectors/rounding.json) | Defined only in Phase 0; consumed by both pricing engines starting Phase 4 |
| ERD / data dictionary / threat model / sync design skeleton | [ERD.md](ERD.md), [data-dictionary.md](data-dictionary.md), [threat-model.md](threat-model.md), [sync-design.md](sync-design.md) | Reviewed manually; revisited at end of each phase that adds the tables/flows they describe |
| OpenAPI skeleton | [openapi.yaml](openapi.yaml) | Structurally valid YAML; full per-endpoint schemas land with their owning phase |
| CI pipeline runs backend + frontend tests and the Dynamic-Only check on every push | [.github/workflows/ci.yml](../.github/workflows/ci.yml) | Will execute on first push to `common_pos`; local-equivalent runs documented in `docs/reports/phase-0.md` |

## Phase 1

| Requirement | Code | Test / evidence |
|---|---|---|
| Tenant isolation (G2, G14): tenant_id resolved only from authenticated identity; foreign-tenant ID behaves as not-found | [app/Models/Concerns/BelongsToTenant.php](../backend/app/Models/Concerns/BelongsToTenant.php), [app/Support/TenantContext.php](../backend/app/Support/TenantContext.php) | `tests/Feature/Tenancy/TenantIsolationTest.php` — 3 tests passing |
| Two structurally separate guards (section 5 "Identity") | [app/Http/Middleware/AuthenticateTenant.php](../backend/app/Http/Middleware/AuthenticateTenant.php), [config/auth.php](../backend/config/auth.php), [app/Providers/AppServiceProvider.php](../backend/app/Providers/AppServiceProvider.php) | `TenantIsolationTest` ("platform users cannot be resolved by the tenant guard...") |
| Login (shop code + username), generic failure message, rate limiting, account lockout | [app/Services/Auth/TenantAuthService.php](../backend/app/Services/Auth/TenantAuthService.php), [app/Http/Controllers/Api/V1/AuthController.php](../backend/app/Http/Controllers/Api/V1/AuthController.php) | `tests/Feature/Auth/LoginTest.php` — 6 tests passing (success, wrong password, unknown shop code, cross-tenant resolution, lockout, logout-revokes-token) |
| G11: authorization by permission code only, never role name | [app/Http/Middleware/EnsurePermission.php](../backend/app/Http/Middleware/EnsurePermission.php), [app/Models/User.php](../backend/app/Models/User.php) (`hasPermission`) | `tests/Feature/Authorization/PermissionTest.php` — 3 tests passing, incl. "a custom role behaves exactly like a seeded standard role" (section 13.1) |
| `SettingsService.get(key, context)`: branch → tenant → plan → platform resolution, version-based cache invalidation (section 4) | [app/Services/Settings/SettingsService.php](../backend/app/Services/Settings/SettingsService.php) | `tests/Feature/Settings/SettingsServiceTest.php` — 5 tests passing, incl. the cache-invalidation-on-write case |
| Module enable/disable removes menu + API access with no code change (section 4) | [app/Http/Middleware/EnsureModuleEnabled.php](../backend/app/Http/Middleware/EnsureModuleEnabled.php), [app/Http/Controllers/Api/V1/TenantConfigController.php](../backend/app/Http/Controllers/Api/V1/TenantConfigController.php) | `tests/Feature/Configuration/ModuleDisabledTest.php` — 2 tests passing |
| Onboarding: tenant + subscription + modules + snapshot + default roles/permissions + branch/warehouse/register + owner in one transaction (section 8) | [app/Services/Onboarding/OnboardingService.php](../backend/app/Services/Onboarding/OnboardingService.php) | `tests/Feature/Onboarding/OnboardingServiceTest.php` — 1 test asserting the full row set exists after one call |
| Platform catalogue seeded as data, not code (modules, plans, templates, permissions, settings, languages) | `backend/database/seeders/*.php` | `php artisan db:seed` executed against real MySQL — see `docs/reports/phase-1.md` |
| `GET /tenant/config` — menu built only from server data (section 11) | [app/Http/Controllers/Api/V1/TenantConfigController.php](../backend/app/Http/Controllers/Api/V1/TenantConfigController.php), [frontend/src/screens/AppShell.jsx](../frontend/src/screens/AppShell.jsx) | Backend: `ModuleDisabledTest`; frontend: `frontend/src/screens/AppShell.test.jsx` — 2 tests passing |
| Login screen, schema-driven menu, loading/empty/error states (section 11) | [frontend/src/screens/LoginScreen.jsx](../frontend/src/screens/LoginScreen.jsx), [frontend/src/screens/AppShell.jsx](../frontend/src/screens/AppShell.jsx) | `frontend/src/screens/LoginScreen.test.jsx` — 3 tests passing |
| Dynamic-Only Rule check still passes against all new Phase 1 code | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed — see `docs/reports/phase-1.md` |

Known gaps this phase (not silently skipped — tracked in `docs/OPEN_QUESTIONS.md` / `docs/DECISIONS.md`): `GET /tenant/config` does not yet return `translations` (D-0008's seeded dictionary isn't wired to the frontend yet); numbering/tax/payment-method/lookup/custom-field/document-template/hardware-profile/offline-policy/rule-engine/approval tables have schema + models only, no service logic (that lands with the phases that actually use them — numbering and tax in Phase 4, offline policies in Phase 7, etc.); platform guard auth endpoints (login/logout for platform users) were not built this phase, only the guard mechanism itself.

## Phase 2

| Requirement | Code | Test / evidence |
|---|---|---|
| Stock balance always equals the sum of its ledger (section 7 "Critical table rules") | [app/Services/Inventory/StockService.php](../backend/app/Services/Inventory/StockService.php) | `tests/Feature/Inventory/StockServiceTest.php` — "receiving stock then selling some leaves a balance that exactly equals the sum of the ledger" |
| Weighted-average costing (section 1.2, section 8 "Stock operations") | `StockService::applyMovement()` | `StockServiceTest` — "weighted-average cost recalculates correctly across two purchases at different prices"; outgoing cost taken from current average, not original purchase price |
| Negative stock blocked by default, bypassable via setting (section 1.2) | `StockService::applyMovement()` + `SettingsService` | `StockServiceTest` — "selling more than is in stock is blocked..." / "negative stock is allowed when the inventory.allow_negative_stock setting is turned on" |
| Stock locking in a consistent order prevents deadlock on multi-item, opposite-order operations (section 7, section 13.1) | `StockService::recordMovements()` (sorts before locking) | `StockServiceTest` — "a batch of movements across two products is applied in a consistent (sorted) lock order regardless of input order" |
| Two concurrent decrements of the last unit cannot both succeed (section 13.1) | `StockService::lockOrCreateBalance()` (`lockForUpdate()`) | `tests/Feature/Inventory/StockConcurrencyTest.php` — real MySQL row-lock contention proof via two independent PDO connections (see `docs/DECISIONS.md` D-0014) |
| A failed stock movement leaves nothing written (G4) | `StockService::applyMovement()` throws before any write on insufficient stock | `StockServiceTest` — balance/ledger row counts asserted unchanged after the thrown exception |
| Low-stock detection | `StockService::lowStockBalances()` | `tests/Feature/Inventory/LowStockTest.php` |
| Products archived, never deleted (G9) | `app/Services/Catalogue/ProductService.php` | `tests/Feature/Catalogue/ProductServiceTest.php` — "archiving a product soft-archives it rather than deleting it" |
| Price lists keep history; current-price lookup stays in sync (section 7 "price lists + history") | [app/Services/Catalogue/PriceListService.php](../backend/app/Services/Catalogue/PriceListService.php) | `tests/Feature/Catalogue/PriceListServiceTest.php` |
| Bulk import validates every row, saves valid rows, reports every rejected row with a reason (section 8 "Master data") | [app/Services/Catalogue/ProductImportService.php](../backend/app/Services/Catalogue/ProductImportService.php) | `tests/Feature/Catalogue/ProductImportServiceTest.php` — 5 rows, 2 accepted, 3 rejected each with its own reason and row number |
| Opening stock/adjustment/damage/wastage require a mandatory reason and post through the same locked ledger pattern as any other stock movement (section 8 "Stock operations") | [app/Services/Inventory/StockAdjustmentService.php](../backend/app/Services/Inventory/StockAdjustmentService.php) | `tests/Feature/Inventory/StockAdjustmentServiceTest.php` |
| Dynamic-Only Rule check still passes against all new Phase 2 code | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed — see `docs/reports/phase-2.md` |

Known gaps this phase (tracked, not silently skipped): no catalogue/inventory frontend screens built (D-0015); `cost_layers`/FIFO is schema-only (weighted average is the only costing method with real logic, per section 1.2's own permission to defer FIFO); export jobs are schema-only (no real export logic); approval-rule integration for adjustments requiring sign-off is not wired up (the rule engine itself has no evaluator yet, carried over from Phase 1) — every adjustment posts immediately, `StockAdjustment.approved_by` stays unset until that lands.

## Phase 3

| Requirement | Code | Test / evidence |
|---|---|---|
| Customer ledger balance always equals the sum of its entries (section 12 Phase 3 exit gate) | [app/Services/Customers/CustomerLedgerService.php](../backend/app/Services/Customers/CustomerLedgerService.php) | `tests/Feature/Customers/CustomerLedgerServiceTest.php` — "the customer balance always equals the sum of their ledger entries" |
| Supplier ledger balance (payable) always equals the sum of its entries | [app/Services/Purchasing/SupplierLedgerService.php](../backend/app/Services/Purchasing/SupplierLedgerService.php) | `tests/Feature/Purchasing/SupplierLedgerServiceTest.php` |
| Payment allocation against an outstanding debit; over-allocation rejected (section 8 "Ledgers and collections") | `CustomerLedgerService::allocate()` / `SupplierLedgerService::allocate()` | Both test files - allocating beyond the debit's outstanding amount or the payment's unallocated remainder throws `ApiException` |
| Loyalty points: earn/redeem, balance equals the sum of transactions, over-redemption rejected | [app/Services/Customers/LoyaltyService.php](../backend/app/Services/Customers/LoyaltyService.php) | `tests/Feature/Customers/LoyaltyServiceTest.php` |
| Shift expected-cash matches the formula (section 12 Phase 3 exit gate, section 8 "Shifts and cash") | [app/Services/Finance/ShiftService.php](../backend/app/Services/Finance/ShiftService.php) | `tests/Feature/Finance/ShiftServiceTest.php` — "closing a shift computes expected cash via the formula..." and the cash-difference case |
| One open shift per register (section 8) | `ShiftService::open()` + `cashier_shifts.open_register_marker` unique constraint (D-0018) | `ShiftServiceTest` — "only one shift can be open per register at a time" |
| Closed shifts are immutable (section 12 Phase 3 exit gate) | `CashierShift::booted()` guard + `ShiftService::recordCashMovement()`'s open-check | `ShiftServiceTest` — "closed shifts are immutable: no further cash movement can be recorded, and the row itself cannot be re-saved" |
| Expenses linked to a shift create a matching cash movement | [app/Services/Finance/ExpenseService.php](../backend/app/Services/Finance/ExpenseService.php) | `tests/Feature/Finance/ExpenseServiceTest.php` |
| Dynamic-Only Rule check still passes against all new Phase 3 code | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed — see `docs/reports/phase-3.md` |

Known gaps this phase (tracked, not silently skipped): no customer/supplier/shift/expense frontend screens built (D-0019); customer/supplier debits this phase come only from opening-balance/manual-adjustment entries - Phase 4's sales and Phase 5's purchase receiving will post their own debit rows into the same ledger tables (D-0016), and Phase 4/5's cash-sale/cash-refund flows will post into `cash_movements` automatically instead of by hand (D-0017); `customer_payments`/`supplier_payments` support explicit allocation via `allocate()` but nothing auto-allocates FIFO yet (not required by this phase's exit gate).

## Phase 4

| Requirement | Code | Test / evidence |
|---|---|---|
| Pricing engine: both PHP and JS pass every golden vector (section 12 Phase 4 exit gate, section 9) | [app/Services/Pricing/PricingEngine.php](../backend/app/Services/Pricing/PricingEngine.php), [frontend/src/pricing/pricingEngine.js](../frontend/src/pricing/pricingEngine.js) | `tests/Feature/Pricing/PricingEngineGoldenVectorTest.php` (8/8) + `frontend/src/pricing/pricingEngine.test.js` (8/8) — both load the identical files in `shared/golden-vectors/` |
| Per-line tax detail (needed for `sale_items`/`sale_item_taxes`, not just invoice totals) | `PricingEngine::calculate()`'s `lines` output | `tests/Feature/Pricing/PricingEnginePerLineTest.php` |
| Server ignores client-submitted prices/totals (section 12 Phase 4 exit gate, G1) | [app/Services/Sales/SaleService.php](../backend/app/Services/Sales/SaleService.php) `resolveLine()` never reads a client price | `tests/Feature/Sales/SaleCreationTest.php` — "server ignores a client-submitted price override..." |
| A failed sale leaves nothing (section 12 Phase 4 exit gate, G4) | `SaleService::create()` - one DB transaction, stock locked before any row is written | `SaleCreationTest` — "a failed sale (insufficient stock) leaves nothing behind..." |
| Idempotency: same key replays with no duplicates, different payload rejected (section 12 Phase 4 exit gate, G6) | [app/Http/Middleware/EnsureIdempotent.php](../backend/app/Http/Middleware/EnsureIdempotent.php) | `SaleCreationTest` — replay test + `IDEMPOTENCY_KEY_REUSED` test + missing-header test |
| Invoice numbers generated under a locked sequence row (section 7 "invoices") | [app/Services/Sales/NumberingService.php](../backend/app/Services/Sales/NumberingService.php) | `SaleCreationTest` — "a sale is created... and generates an invoice"; race-safety fix documented in D-0020 |
| Discount requires `pos.sales.discount` permission or creates an approval request (section 8 step 4) | `SaleService::create()`'s discount-permission gate | `SaleCreationTest` — "a cashier without pos.sales.discount cannot complete a discounted sale..." / "...can complete a discounted sale directly" |
| Held bills store line selections only, never a price; resuming re-prices through the normal path (section 7, G1) | [app/Services/Sales/HeldSaleService.php](../backend/app/Services/Sales/HeldSaleService.php) | `tests/Feature/Sales/HeldSaleServiceTest.php` |
| Dynamic-Only Rule check still passes against all new Phase 4 code | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed — see `docs/reports/phase-4.md` |

Known gaps this phase (tracked, not silently skipped): no `discount_rules` condition evaluator (permission-only gating, D-0021); no payment-provider adapters, manual_confirm flow, or webhook endpoint (only cash/credit payment methods, D-0022); no receipt rendering/browser print; no frontend POS cart screen or outbox queue (D-0022) — sale creation is fully real and tested via the HTTP API, but there is no UI to drive it yet.

## Phase 5

| Requirement | Code | Test / evidence |
|---|---|---|
| Returns cannot exceed sold (section 12 Phase 5 exit gate) | [app/Services/Sales/SaleReturnService.php](../backend/app/Services/Sales/SaleReturnService.php) — fail-fast check in `requestReturn()` + authoritative row-locked check in `complete()` | `tests/Feature/Returns/SaleReturnServiceTest.php` — "a return cannot exceed the quantity actually sold" + "two returns... cannot together exceed what was sold" |
| Refund uses original values (section 12 Phase 5 exit gate, G1) | `SaleReturnService::complete()` computes the refund from `sale_item.line_total`/quantity at the time of sale, never current prices | `SaleReturnServiceTest` — "...refund amount uses the ORIGINAL sale price, not a later price change" |
| Reports match hand-computed fixtures (section 12 Phase 5 exit gate, section 8.13) | [app/Services/Reports/ReportService.php](../backend/app/Services/Reports/ReportService.php) — all 8 figures | `tests/Feature/Reports/ReportServiceTest.php` — "every report figure matches a hand-computed fixture" (gross/net sales, tax, COGS, gross profit, stock valuation, expected cash) + "outstanding sums each...CURRENT balance" |
| Closed days need reopen permission (section 12 Phase 5 exit gate) | [app/Services/Finance/DailyClosingService.php](../backend/app/Services/Finance/DailyClosingService.php) `reopen()`, gated by `permission:finance.reopen` | `tests/Feature/Finance/DailyClosingServiceTest.php` — "a user without finance.reopen cannot reopen..." / "...can reopen" |
| A business day cannot close while a shift remains open (section 8 "End of day") | `DailyClosingService::close()` | `DailyClosingServiceTest` — "the business day cannot be closed while a shift is still open..." |
| Cancel reverses stock + payment + customer-ledger entries, gated by `sales.cancel_window` + permission/approval (section 8) | [app/Services/Sales/SaleCancellationService.php](../backend/app/Services/Sales/SaleCancellationService.php) | `tests/Feature/Returns/SaleCancellationServiceTest.php` — all 4 tests |
| Purchasing: receiving posts stock in at received cost + a real `supplier_ledger` debit, closing D-0016/D-0022's loop (section 8 "Purchasing") | [app/Services/Purchasing/PurchaseReceivingService.php](../backend/app/Services/Purchasing/PurchaseReceivingService.php) | `tests/Feature/Purchasing/PurchaseReceivingServiceTest.php` — "receiving a purchase moves real stock in..." + PO status progression test |
| Purchase returns cannot exceed what was received; reverse stock + post a supplier credit | [app/Services/Purchasing/PurchaseReturnService.php](../backend/app/Services/Purchasing/PurchaseReturnService.php) | `PurchaseReceivingServiceTest` — "a purchase return cannot exceed what was received..." |
| A real bcmath truncation bug found and fixed (not after the fact — caught by the report fixture test on first run) | `SaleReturnService::complete()` and `ReportService::returnedAmounts()` — multiply-before-divide | See D-0023 |
| Dynamic-Only Rule check still passes against all new Phase 5 code | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed — see `docs/reports/phase-5.md` |

Known gaps this phase (tracked, not silently skipped, D-0024–D-0028): damaged-condition returns are a write-off with no dedicated damaged-stock location; no `exchange` service (return+sale composition); no export-center job execution; no notification/alert delivery; `outstanding` has no ageing-bucket breakdown; PO receiving matches lines by product+variant, not a specific PO line ID; `DailyClosingService::reopen()` has no automatic reversing-entry mechanism for post-reopen corrections.

## Phase 6

| Requirement | Code | Test / evidence |
|---|---|---|
| Template data drives the full grocery flow with NO grocery-specific code branches (section 12 Phase 6 exit gate) | [database/seeders/TemplatesSeeder.php](../backend/database/seeders/TemplatesSeeder.php)'s `grocery` entry (pure data) + [app/Services/Onboarding/TemplateUpdateService.php](../backend/app/Services/Onboarding/TemplateUpdateService.php) (the one generic mechanism both templates use) | `tests/Feature/Templates/GroceryTemplateTest.php` — all 5 tests, including the explicit "same code, different data, no grocery leakage" test against `general_retail` |
| Weighing: variable-weight barcodes decode per a tenant-configurable format, gated by the `weighing` module flag (not a template check) | [app/Services/Catalogue/VariableWeightBarcodeService.php](../backend/app/Services/Catalogue/VariableWeightBarcodeService.php) | `tests/Feature/Catalogue/VariableWeightBarcodeServiceTest.php` — decode test, module-gating negative test (same barcode, `general_retail` tenant → null), fixed-barcode-works-everywhere test |
| A weight-derived fractional quantity reaches an ordinary sale with zero grocery-specific sale code (G3) | `SaleService`/`PricingEngine` (Phase 4, unchanged) fed a barcode-resolved quantity | `VariableWeightBarcodeServiceTest` — "...feeds straight into a normal sale..." |
| Daily rate update: a generic bulk price change, not grocery-gated | [app/Services/Catalogue/PriceListService.php](../backend/app/Services/Catalogue/PriceListService.php) `bulkSetPrices()` | `tests/Feature/Catalogue/PriceListBulkUpdateTest.php` |
| Wastage: the exact same generic stock-adjustment path works for any tenant, now reachable over HTTP for the first time | [app/Http/Controllers/Api/V1/StockAdjustmentController.php](../backend/app/Http/Controllers/Api/V1/StockAdjustmentController.php) wrapping Phase 2's `StockAdjustmentService` | `tests/Feature/Inventory/WastageAdjustmentTest.php` — grocery tenant, general_retail tenant (identical request), missing-reason rejection |
| Template snapshot + update preview (section 12 Phase 6 build list) | `TemplateUpdateService::preview()`/`apply()` | `GroceryTemplateTest` — idempotent re-apply test + the version-2-preview/apply test (publishes a real new `TemplateVersion`, previews the diff, applies it) |
| `EnsureModuleEnabled` middleware's underlying mechanism (`Tenant::isModuleEnabled()`, built Phase 1, never exercised until now) actually gates behaviour | `VariableWeightBarcodeService::resolve()` | `VariableWeightBarcodeServiceTest` — module-gating negative test |
| Dynamic-Only Rule check still passes against all new Phase 6 code | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed — see `docs/reports/phase-6.md` |

Known gaps this phase (tracked, not silently skipped, D-0029–D-0033): `OnboardingService::createTenant()` itself still only applies modules+roles (catalogue data comes from a separate `TemplateUpdateService::apply()` call, by design); no EAN-13 check-digit validation on variable-weight barcodes; no frontend hardware adapter implementations (ReceiptPrinter/WeighingScale/BarcodeScanner device code) - `hardware_profiles` CRUD is server-config-only; hardware-profile and template-update permissions reuse existing codes rather than adding new ones.

## Phase 7

| Requirement | Code | Test / evidence |
|---|---|---|
| Kill mid-sale/after submit → outbox survives, sale syncs once (section 13.2) | [frontend/src/offline/outbox.js](../frontend/src/offline/outbox.js) - a Dexie/IndexedDB-backed queue, durable by construction | `frontend/src/offline/outbox.test.js` — "outbox data survives a 'restart' - a brand new Dexie connection..." |
| Sell offline then reconnect → every sale accepted once, correct numbers (section 13.2) | [app/Services/Sync/SyncPushService.php](../backend/app/Services/Sync/SyncPushService.php) | `tests/Feature/Sync/SyncPushServiceTest.php` — "selling offline then reconnecting..." |
| Resubmit same operation UUID → earlier result, nothing new (section 13.2, G6-style) | `SyncPushService::processOne()`'s `sync_operations.uuid` dedupe check | `SyncPushServiceTest` — "resubmitting the same operation UUID..." + `frontend/src/offline/syncClient.test.js` |
| Two devices sell the last unit offline → both accepted, STOCK_SHORTFALL flagged, none lost (section 13.2) | `SyncPushService::processSaleCreate()` retries with `forceAllowNegativeStock` on `INSUFFICIENT_STOCK` | `SyncPushServiceTest` — "two devices selling the last unit offline..." |
| Server price changes while offline → mismatch policy applied, difference recorded, server total always wins (section 13.2, G1) | `SyncPushService::processSaleCreate()`'s `PRICE_MISMATCH` conflict (D-0037: never overrides the committed total) | `SyncPushServiceTest` — "a server price change while offline is reconciled..." |
| Revoked device: pre-revocation ops accepted, later rejected for review; wiped at next contact (section 13.2) | `SyncPushService::processOne()`'s `client_created_at` vs `revoked_at` check (D-0035); `frontend/src/offline/snapshot.js` `wipeLocalData()` | `SyncPushServiceTest` — "a revoked device: operations created BEFORE/AFTER revocation..."; `frontend/src/offline/snapshot.test.js` — "wiping local data clears every store..." |
| Expired/suspended tenant → offline sales stored, never discarded (section 13.2) | `SyncPushService::hold()`; route restructuring exempting `/sync/push` from the `tenant.active` gate (D-0034, a real bug found via a failing test) | `SyncPushServiceTest` — "an expired/suspended tenant..." |
| Device series never repeat, provisional numbers replaced correctly (section 13.2) | `SaleService::create()`'s new `$deviceId` param feeding `NumberingService::next()`'s existing `device_key` bucket column (Phase 1/D-0020, exercised for real for the first time) | `SyncPushServiceTest` — "device-scoped offline numbering..." |
| Clock skew, 500-operation queue, interrupted sync with backoff (section 13.2) | `SyncPushService`'s `CLOCK_SKEW` conflict; `frontend/src/offline/outbox.js` `computeBackoffDelayMs()` + `syncClient.js`'s retry scheduling | `SyncPushServiceTest` — clock-skew test + 20-operation batch test; `syncClient.test.js` — "a network failure... schedules a retry with backoff" |
| Rejected items stay visible until resolved (section 13.2) | `GET /sync/operations`; `SyncConflictService::resolve()` | `SyncPushServiceTest` — "rejected and held operations stay visible..." |
| Snapshot + incremental pull (section 9, build-list scope, D-0036) | [app/Services/Sync/SyncPullService.php](../backend/app/Services/Sync/SyncPullService.php) | `tests/Feature/Sync/SyncPullServiceTest.php` — full pull, incremental-by-product, incremental-by-price-change |
| Encrypt sensitive fields with a device key (section 9) | [frontend/src/offline/crypto.js](../frontend/src/offline/crypto.js) - Web Crypto AES-GCM | `frontend/src/offline/crypto.test.js` — round-trip, fresh IV per call, wrong-key failure |
| Device management (list/revoke) | [app/Http/Controllers/Api/V1/DeviceController.php](../backend/app/Http/Controllers/Api/V1/DeviceController.php) | `tests/Feature/Devices/DeviceControllerTest.php` |
| Dynamic-Only Rule check still passes against all new Phase 7 code | [scripts/check-dynamic-only.js](../scripts/check-dynamic-only.js) | Executed — see `docs/reports/phase-7.md` |

Known gaps this phase (tracked, not silently skipped, D-0038–D-0042): no envelope hash/tamper verification; tenant-suspension check doesn't separately model subscription expiry/grace; only `sale.create` has a real sync handler (other `offline.allowed_operations` types are structurally accepted then rejected with `TYPE_NOT_ALLOWED_OFFLINE`); the frontend offline module is built and tested standalone but not yet imported by any screen (no POS cart/billing UI exists at all); no PWA/service-worker registration despite `vite-plugin-pwa` being installed since Phase 0, no auto-lock/PIN screen.

## Screen-ID mapping (section 11)

| Screen ID (guide) | Built as | Notes |
|---|---|---|
| AUTH-01 (Login) | `frontend/src/screens/LoginScreen.jsx` | Shop code + username + password, generic error message, lockout message |
| n/a (post-login shell) | `frontend/src/screens/AppShell.jsx` | Not a numbered screen in the guide; the dynamic-menu shell every other screen will render inside, starting Phase 2 |

The rest of section 11's catalogue (SA-xx, ON-xx, DASH-xx, POS-xx, SAL-xx, PRD-xx, INV-xx, PUR-xx, CUS-xx, FIN-xx, REP-xx, USR-xx, SET-xx, SYN-xx, TPL-VEG-xx, APR-xx, NOT-xx) has no screen yet — each is mapped here in the commit that actually builds it. Phase 2 and Phase 3 both built backend-only (D-0015, D-0019); a frontend pass against the now-stable catalogue/inventory/customer/supplier/shift API surface is still pending.
