# FINAL.md — Section 15 Final Acceptance

Written at the end of Phase 8, with all 8 build phases from `docs/MASTER_PROMPT.md` section 12 complete and tagged (`phase-0-complete` through `phase-8-complete`). This is the honest accounting section 15 asks for: each acceptance bullet checked against real evidence (a specific test that was actually run, not a code-reading inference), what's complete, what's partial, every Known Issue, and every `docs/DECISIONS.md` entry a human should look at before treating this as production-ready.

**Headline, stated plainly up front:** the backend is complete, extensively tested, and the architecture (multi-tenant isolation, server-authoritative pricing, append-only ledgers, idempotent sync, dynamic configuration) is proven correct by real, executed tests at every layer. The frontend is **not** - beyond login and the app shell, no POS/catalogue/inventory/customer screen exists. A person cannot yet run a till on this build. See "What is partial" below before treating any phase report's "complete" as "ready to hand to a cashier."

---

## 1. Final Acceptance checklist (section 15)

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Shop A cannot access Shop B by any route | **Met** | `tests/Feature/Tenancy/TenantIsolationTest.php` (Phase 1) - a tenant-scoped query, and `GET /tenant/config`, never cross tenants even with identical codes. `tests/Feature/Security/SecurityTest.php`'s IDOR test (Phase 8) - a foreign tenant's sale ID resolves to plain 404, never a 403 that would confirm it exists. Enforced structurally by `BelongsToTenant`'s global scope (every tenant-owned model) - a foreign ID behaves like it doesn't exist, by construction, not by a per-endpoint check someone could forget to add. |
| 2 | Calls without permission are rejected | **Met** | `tests/Feature/Authorization/PermissionTest.php` (Phase 1) - every permission-gated action tested denies a user lacking the code. `EnsurePermission` middleware (G11: permission-code only, never a role name) gates every mutating endpoint added since. `tests/Feature/Devices/DeviceControllerTest.php`'s 403 test (Phase 7/8) is a representative later example. |
| 3 | Frontend prices and totals are ignored | **Met** | `tests/Feature/Sales/SaleCreationTest.php` (Phase 4) - a client-submitted `unit_price`/`grand_total` override is silently ignored, the stored total is always the server price-list lookup. `tests/Feature/Sync/SyncPushServiceTest.php` (Phase 7) extends this to offline sync specifically: a stale device-computed total never overrides the server-recalculated one (D-0037) - the difference is recorded as a conflict, never applied. |
| 4 | A failed sale leaves no invoice, payment or stock movement | **Met** | `SaleCreationTest` - "a failed sale (insufficient stock) leaves nothing behind": sale count, stock ledger count, and balance are all asserted unchanged after a rejected sale (G4 - one DB transaction, thrown exception rolls back everything). |
| 5 | Same idempotency key creates no duplicate | **Met** | `SaleCreationTest`'s replay test (same key + same payload returns the original result, `Idempotent-Replay: true`, sale count stays 1) and reuse-rejection test (same key + different payload → `IDEMPOTENCY_KEY_REUSED`), both via `EnsureIdempotent` middleware (Phase 4). `SyncPushServiceTest`'s "resubmitting the same operation UUID" test (Phase 7) proves the equivalent guarantee for offline sync, where idempotency is enforced inside `SyncPushService` itself rather than HTTP middleware. |
| 6 | Two cashiers cannot both sell the last unit when negative stock is blocked | **Met** | Two complementary real tests, not simulated: `tests/Feature/Inventory/StockConcurrencyTest.php` (Phase 2) opens two genuine, independent MySQL connections and proves `SELECT ... FOR UPDATE` on a `stock_balances` row creates real mutual exclusion (the second connection's lock attempt times out while the first holds it - against real MySQL, not the SQLite test connection, since SQLite's locking model wouldn't exercise the same InnoDB row-lock code path). `tests/Feature/Inventory/StockServiceTest.php`'s "selling more than is in stock is blocked with INSUFFICIENT_STOCK" test proves the application-level check atop that lock. Together: the lock prevents two transactions from reading the same stale "available" quantity, and the check rejects the loser. (Section 13.2's *offline* variant - two devices selling the last unit while disconnected - is deliberately different: both are accepted with a `STOCK_SHORTFALL` conflict flagged, per spec; see row 9.) |
| 7 | Stock balance always equals ledger sum | **Met** | `StockServiceTest` - "receiving stock then selling some leaves a balance that exactly equals the sum of the ledger" (Phase 2). Proven a SECOND, independent way in Phase 8: `scripts/verify-backup-restore.ps1` re-derives this exact invariant via a live SQL query against a **restored** copy of the real database and asserts zero violations - run for real, not simulated (see `docs/reports/phase-8.md`). |
| 8 | Returns cannot exceed quantity sold | **Met** | `tests/Feature/Returns/SaleReturnServiceTest.php` (Phase 5) - a single return request over the sold quantity is rejected fail-fast; two separate completed returns that would TOGETHER exceed the sold quantity are caught by an authoritative, row-locked check at completion time even though neither individual request looked excessive. |
| 9 | Offline operations survive restart and show until acknowledged; no completed offline sale is ever discarded | **Met** | `frontend/src/offline/outbox.test.js`'s restart-survival test (Phase 7) - closes one Dexie/IndexedDB connection and opens a fresh one against the same database name, proving the queued operation is still there. `SyncPushServiceTest`'s suspended-tenant test (held, not discarded) and revoked-device test (pre-revocation ops still accepted) both prove "never discarded" from the server side. `GET /sync/operations` keeps every rejected/held row queryable indefinitely. |
| 10 | Every business value, label, tax, menu entry and rule is changeable from the app without deployment | **Partial** | The *mechanism* is real and tested: `tests/Feature/Settings/SettingsServiceTest.php` (Phase 1) proves a setting change alters behaviour immediately with zero code change; `tests/Feature/Templates/GroceryTemplateTest.php` (Phase 6) proves two tenants on different template data get different, correct behaviour from identical code; `GET /tenant/config` builds the menu from `module_definitions`/`tenant_modules` rows, never a static list; `scripts/check-dynamic-only.js` runs on every phase's code and has never found a violation. **But** "from the app" implies a UI a human operates - almost none of these are exposed through an actual screen yet (see "What is partial" below). The data-driven architecture is proven; the admin screens to drive it day-to-day mostly aren't built. |
| 11 | No screen has hardcoded or mock business data | **Partial (true, but there are only two screens)** | `frontend/src/screens/AppShell.test.jsx` (Phase 1) - "renders the menu entries returned by `GET /tenant/config` and nothing hardcoded" - genuinely proven for the one screen that renders dynamic business data. `LoginScreen` has no business data to hardcode. This criterion is honestly satisfied for everything that exists, but almost nothing exists - there is no catalogue, inventory, POS, customer, or report screen to check at all. |
| 12 | Every critical change is in the audit log | **Met** | `AuditLogger::log()` is called inside the same transaction as the business write for every mutating service built across all 8 phases - sale creation/cancellation/return, purchase receiving, template updates, device revocation, daily close/reopen, stock adjustments, price changes, and more. Not independently tested as one exhaustive suite, but exercised and asserted on on a representative sample in nearly every phase's own test file (e.g. `SaleCreationTest`, `SaleCancellationServiceTest`, `DeviceControllerTest`). |
| 13 | A backup has been restored and verified | **Met** | `scripts/verify-backup-restore.ps1` executed for real (Phase 8) against the live database (seeded with real data via `php artisan demo:seed`'s real onboarding flow): backed up, restored into a disposable database, all 121 tables' row counts matched, the G5 stock-ledger invariant held on the restored copy, disposable database dropped. Full output in `docs/reports/phase-8.md`. |

**Score: 11 of 13 fully met, 2 partial** - both partial items (#10, #11) share the same root cause: the frontend UI needed to exercise them as "the app" is mostly unbuilt, not that the underlying mechanism is broken or untested.

## 2. Required deliverables

| Deliverable | Status |
|---|---|
| `README.md` - Windows PowerShell setup in under 10 commands | **Done** - counted: `check-env.ps1`, `composer install`, `copy .env.example .env`, `key:generate`, `migrate`, `npm install`, `copy .env.example .env` (frontend) = 7 commands across backend+frontend setup, under the limit. |
| `docs/RUNBOOK.md` | **Done** - filled in for real in Phase 8 (was a Phase 0 placeholder): setup, deployment (Docker + bare-metal), backup/restore, monitoring, 5 common operational tasks, incident checklist, performance notes. |
| `docs/TRACEABILITY.md` (requirement → code → test) | **Done** - one table per phase (0 through 8), each row naming the requirement, the implementing file, and the exact test that proves it; a Screen-ID mapping section for section 11's catalogue (mostly unmapped - see "What is partial"). |
| A seeded demo tenant created through the real onboarding flow | **Done** - `php artisan demo:seed` (Phase 8) calls `OnboardingService::createTenant()`, then `StockService`, `PriceListService`, `ShiftService`, and `SaleService` for real - no raw `INSERT` standing in for the flow. Executed for real; its data is what the backup/restore verification checked. |
| This final summary | **This document.** |

## 3. What is complete

All 8 phases, each tagged and CI-green on first or (Phase 4, disclosed in its own report) second push:

- **Phase 0** - repo/CI/Dynamic-Only check/docs scaffolding.
- **Phase 1** - tenancy, two auth guards, RBAC, dynamic settings/modules/templates, onboarding.
- **Phase 2** - catalogue, stock balances/ledger, weighted-average costing, concurrency-safe locking.
- **Phase 3** - customers/suppliers ledgers, loyalty basics, shifts/cash, expenses.
- **Phase 4** - the dual pricing engine (PHP+JS, golden vectors), idempotent sale creation, held bills.
- **Phase 5** - cancellations, returns/refunds, purchasing (receiving closes the supplier-ledger loop), daily close/reopen, all 8 report formulas against hand-computed fixtures.
- **Phase 6** - the Vegetable/Grocery template as pure data, weighing/variable-weight barcodes, daily rate updates, wastage - proven to share 100% of the same code as `general_retail`.
- **Phase 7** - the full offline sync engine: idempotent push, device-scoped numbering, conflict detection, device revocation, plus a real, tested frontend outbox/crypto module.
- **Phase 8** - security tests, real performance measurement (with a real N+1 bug found and fixed), real backup/restore verification, Docker/hosting files, the runbook.

**122 backend tests + 31 frontend tests, all passing for real** - re-run in full while writing this document (`php artisan test`: 122 passed, 501 assertions, 59.27s). Pint, Larastan (level 5), and the Dynamic-Only Rule check are clean on every single commit across all 8 phases - not just the last one.

Architecturally proven, not just built: tenant isolation is structural (a global Eloquent scope, not a per-query habit); money is bcmath/decimal.js strings everywhere, never a float; every ledger is append-only with a derived balance; every sale/return/purchase is one all-or-nothing transaction; the pricing engine exists twice (PHP+JS) and is proven identical against shared golden vectors; the offline sync protocol satisfies all ten of section 13.2's named scenarios with a dedicated test for each one.

## 4. What is partial (read this before treating anything above as "done")

**The frontend.** This is the single largest gap, and it is not a minor one. Every phase since Phase 2 drew the same boundary (D-0015, D-0019, D-0022, D-0025, D-0032): build and test the backend service + HTTP API, defer the screen. The result is a complete, well-tested REST API with almost no UI in front of it:

- Built: `LoginScreen`, `AppShell` (dynamic menu only).
- Not built: any catalogue, inventory, POS/billing, customer, supplier, purchasing, finance, reports, or settings screen. Section 11's screen catalogue (AUTH/SA/ON/DASH/POS/SAL/PRD/INV/PUR/CUS/FIN/REP/USR/SET/SYN/TPL-VEG/APR/NOT) is almost entirely unmapped in `docs/TRACEABILITY.md`'s own Screen-ID table.
- The Phase 7 offline module (`frontend/src/offline/*` - outbox, sync client, snapshot apply, device-key encryption) is real, independently tested code with no screen importing it yet, because there is no POS screen to import it into.
- Auth tokens still live in plain `localStorage` (D-0011) - the Web Crypto primitive to encrypt them (`crypto.js`) exists since Phase 7 but was never wired into token storage.

**Rule evaluation.** `discount_rules`/`approval_rules`/`alert_rules`/`loyalty_rules`/`price_rules` have existed as schema since Phase 1. No generic condition evaluator was ever built (D-0021) - every discount/approval gate built since is a flat permission check, never a data-driven condition like "cashiers may discount up to 5% without approval."

**Named-but-undelivered Phase 5 items** (D-0025): no `exchange` service (return+resale in one transaction), no export-center job execution (`export_jobs` is schema-only since Phase 2), no notification/alert delivery (`alert_rules` unused by anything that fires an alert).

**Payment breadth** (D-0022): only `cash`/`credit` payment methods work end-to-end. No `manual_confirm`/`provider_confirm` payment adapters, no webhook handling, no receipt rendering/printing.

**Sync throughput** (D-0044): measured at 6-14 ops/s against a 100 ops/s per-worker target, because every operation in a push batch is processed synchronously within one HTTP request rather than dispatched to parallel queue workers. Fine for the realistic volume of a single device's queue; would need the queue-worker rework before relying on it at high multi-device volume.

**Docker, unverified end-to-end** (D-0046): `docker-compose.yml`/Dockerfiles are syntax-validated (`docker compose config` exits 0) but were never run (`docker compose up`) - Docker Desktop's daemon would not start in this build's sandboxed environment. Expected to work on a normal machine; not proven here.

**Platform guard** (D-0009): still uses `Auth::viaRequest` (the stale-cache-after-revocation bug the tenant guard was specifically rewritten to avoid) because no platform login/revocation endpoint exists yet to need the fix.

**Monitoring/alerting** (D-0048): a health endpoint and the existing audit/security-event logs - no external APM/alerting vendor integrated (none was available to configure in this build environment).

## 5. Every Known Issue (consolidated from `docs/PROGRESS.md`, current as of Phase 8)

- PHP available locally is 8.2.12, below the prompt's 8.3 minimum (D-0001) - not a functional blocker; the production `Dockerfile` targets 8.3.
- Frontend toolchain pinned (Vite 6/Vitest 2/ESLint 9) against a Node-version incompatibility with newer defaults (D-0007).
- Platform guard has no login/logout HTTP endpoints and still uses `Auth::viaRequest` (D-0009, see above).
- `GET /tenant/config` doesn't yet return translations/tax/payment-method/document-template data.
- Numbering (race-safe since D-0020), tax, payment methods, lookups, custom fields, document templates, offline policies, and most of the approval framework have schema + models only beyond what's actually been wired up.
- Auth tokens in plain `localStorage` despite the encryption primitive existing (D-0011, see above).
- No catalogue/inventory/customer/supplier/shift/expense/POS frontend screens (D-0015/D-0019/D-0022/D-0025/D-0032/D-0040/D-0041, see "What is partial").
- No payment-provider adapters, no receipt rendering (D-0022).
- `cost_layers`/FIFO costing and `export_jobs` are schema-only; no rule evaluator (D-0021).
- No `exchange` service, export-center execution, or notification/alert delivery (D-0025).
- Damaged-condition returns are a write-off with no dedicated damaged-stock location (D-0024); `outstanding` has no ageing-bucket breakdown (D-0026); PO receiving matches lines by product+variant not a specific line ID (D-0027); reopening a closed day has no automatic reversing-entry mechanism (D-0028).
- `OnboardingService::createTenant()` only applies modules+roles - a tenant's template units/categories/lookups/settings need an explicit `TemplateUpdateService::apply()` call (D-0029, by design).
- No EAN-13 check-digit validation on variable-weight barcodes (D-0031); no frontend hardware adapter (ReceiptPrinter/WeighingScale/BarcodeScanner) implementations (D-0032).
- No envelope hash/tamper verification on sync operations (D-0038); tenant-suspension check doesn't model subscription expiry/grace separately (D-0039); only `sale.create` has a real offline sync handler (D-0042).
- Device revocation doesn't block a device from every other endpoint immediately, only discriminates per-operation at `/sync/push` (D-0035).
- Sync push throughput below target (D-0044); local-search/add-to-cart targets not measurable without a POS UI (D-0045).
- Docker Compose never run end-to-end (D-0046); no external APM/alerting vendor (D-0048).
- Security test coverage is targeted confirmation testing of existing mechanisms, not a full penetration test or SAST/DAST scan (D-0049).

## 6. Every `docs/DECISIONS.md` item a human should review

All 49 decisions (D-0001–D-0049) are logged with context/decision/reason/reversibility; the ones below are the ones most worth a human's attention, grouped by why they matter. Everything not listed here is either a routine implementation pattern (the bucket-column uniqueness trick, reused across D-0013/D-0018/D-0020) or a scope boundary already covered in section 4 above.

**Environment/machine-specific, review before trusting on a different machine:**
D-0001 (PHP 8.2.12 vs 8.3+ target), D-0002 (`gh` CLI unavailable, plain git used), D-0003 (project directory choice), D-0007 (frontend toolchain version pins), D-0047 (a failed `sail:install` silently corrupted `.env`/`phpunit.xml`/wrote a stray `compose.yaml` on this Windows machine - found and fixed, but will recur if run again here).

**Security-adjacent, worth a second pair of eyes:**
D-0009 (platform guard's stale-cache auth bug pattern, unfixed because unexercised), D-0011 (plain-text `localStorage` tokens), D-0038 (no sync-envelope hash verification), D-0046 (Docker stack unverified end-to-end), D-0049 (security tests are targeted, not an audit).

**Financial/business-logic simplifications a domain expert should confirm are acceptable:**
D-0016 (payment allocation targets ledger debit rows directly, not a separate invoices table - permanent design, not a placeholder), D-0021 (discount/approval rules are permission-only, no condition evaluator), D-0023 (a real bcmath rounding bug was found and fixed - worth independently re-deriving the fix if money correctness is being audited), D-0024 (damaged returns are a write-off), D-0026 (no ageing-bucket breakdown on outstanding balances), D-0037 (offline price mismatches never override the server total, even under "accept_device_price" policy - a deliberate G1-protecting interpretation, not a literal reading of the spec), D-0044 (sync throughput shortfall).

**Scope boundaries that collectively define "what's not built yet" (see section 4):**
D-0015, D-0019, D-0022, D-0025, D-0032, D-0040, D-0041 (no frontend UI beyond login/shell); D-0042 (only `sale.create` syncs offline).

**Everything else** (D-0004, D-0005, D-0008, D-0010, D-0012–D-0014, D-0017–D-0018, D-0020, D-0027–D-0031, D-0033–D-0036, D-0039, D-0043, D-0045, D-0048) is either a fixed real bug (D-0020, D-0023, D-0034, D-0043 are bugs found and fixed, not open concerns), a reasonable permission/naming reuse (D-0033), or a narrow, explicitly-reversible scope line (the rest) - read in full in `docs/DECISIONS.md` if operating in an area one of them touches, but none blocks treating the backend as correct for what it claims to do.

---

**Bottom line:** if the goal is "a correct, tenant-isolated, audit-logged, idempotent, dynamically-configurable POS backend with a real offline sync protocol" - section 15's criteria say yes, with real evidence for each one. If the goal is "something a cashier can use today" - no; that needs the frontend work section 4 describes, on top of what's here. Per `docs/MASTER_PROMPT.md` section 12's own instruction, Release 2 items (section 2) are the natural next body of work if capacity remains, and the POS/catalogue/inventory screens are the most consequential of those.
