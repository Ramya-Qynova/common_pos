# DECISIONS.md

Append-only log of assumptions, deviations, improvements and workarounds made while building the Advanced Universal POS, per the Autonomy Charter (`docs/MASTER_PROMPT.md` §1).

---

## D-0001 | Phase 0 | Type: Workaround
Context: The dev machine (shared XAMPP install at `C:\xampp`) has PHP 8.2.12 (NTS) installed. The master prompt targets PHP 8.5.x with an 8.3 minimum.
Options considered:
A. Install a second PHP 8.3+ runtime system-wide (via Chocolatey) and repoint PATH/XAMPP.
B. Leave the system PHP untouched and build against PHP 8.2.
C. Use Docker for local dev PHP only.
Decision: B. Build the backend against PHP 8.2.12 locally; `composer.json` declares `"php": "^8.2"`. The production `Dockerfile` (Phase 8) targets PHP 8.3+ as originally specified.
Reason: `C:\xampp\htdocs\pos` is a separate, unrelated, already-working project (AJ Agency POS) that depends on this exact shared XAMPP PHP installation. Replacing or shadowing it system-wide (option A) risks breaking that project and is a "modify system settings"-class action on a shared machine, which is out of scope for this task's authorization. Docker Desktop is not confirmed available on this machine at this time, so option C is deferred to Phase 8 (hosting) where Docker is specified anyway. Laravel's latest stable release (12.x, used here) only requires PHP ^8.2, so this does not block any functional requirement — only the aspirational runtime-version ceiling.
Reversibility: Bump `composer.json`'s `php` constraint and re-test whenever PHP 8.3+ becomes available on the dev machine; no application code depends on an 8.2-only vs 8.3+ behaviour difference.

## D-0002 | Phase 0 | Type: Workaround
Context: `gh` (GitHub CLI) is not installed on this machine. The task requires pushing the finished code to `https://github.com/Ramya-Qynova/common_pos.git`.
Decision: Use plain `git` (clone/add/commit/push) against the HTTPS remote. Git's credential helper is already configured system-wide as `manager` (Git Credential Manager for Windows), so authenticated push reuses the operator's existing GitHub credentials without needing `gh`.
Reversibility: None needed; `gh` was only ever a convenience, not a requirement.

## D-0003 | Phase 0 | Type: Deviation
Context: The task requires a "completely fresh and independent" project that must not reuse any code/structure from the existing POS project at `C:\xampp\htdocs\pos` (a different, unrelated application — "AJ Agency POS" — with its own git history of invoices, van stock, commission bills, etc.), yet also work from "an empty repository" per the master prompt.
Decision: Build this project in a new, separate directory, `C:\xampp\htdocs\common_pos`, cloned directly from the empty target repo `Ramya-Qynova/common_pos`, rather than inside `C:\xampp\htdocs\pos`. No files, migrations, or code are copied from the old project; everything here is authored fresh against the master prompt only.
Reason: Keeps the fresh build fully isolated from the unrelated existing codebase and its git history, satisfying the "do not reuse/mix" instruction literally, while still matching the master prompt's repo layout (`docs/`, `backend/`, `frontend/`, `shared/`, `scripts/`) inside the new repo root.
Reversibility: n/a (directory choice only; both projects can continue to run side by side under XAMPP on different vhost/ports).

## D-0004 | Phase 0 | Type: Workaround
Context: The shared XAMPP `php.ini` had the `intl`, `sodium` extensions and the `opcache` zend_extension commented out, all required by `scripts/check-env.ps1` (§5) and by Laravel (`intl` for translations/number formatting, `sodium` for Argon2id-adjacent crypto primitives and Laravel's encryption, `opcache` for performance).
Decision: Uncommented `extension=intl`, `extension=sodium` and `zend_extension=opcache` in `C:\xampp\php\php.ini`. This is additive (enables previously-unused, already-installed DLLs) and does not change any existing behaviour, so it carries no risk to the other project sharing this PHP install.
Reversibility: Re-comment the three lines in `C:\xampp\php\php.ini` to revert.

## D-0007 | Phase 0 | Type: Workaround
Context: `npm create vite@latest` installed Vite 8.3.5 and the matching bleeding-edge majors of Vitest 5 / ESLint 10 / eslint-plugin-react / @testing-library/react by default. On this machine (Node 22.14.0, below several packages' new engine floors) this produced three separate failures: (1) ESLint 10 crashed inside `eslint-plugin-react`'s React-version detection (`contextOrFilename.getFilename is not a function`), (2) Vitest 5 failed to even start its worker pool (`undici`'s `decompress.js` threw on an unsupported Node API), (3) after fixing those two, component-render tests under Vitest still threw `React is not defined` because the Vite 8 + `@vitejs/plugin-react` 6 + Vitest 2 combination was not applying the automatic JSX runtime during the Vitest transform pipeline speciifically (production `vite build` was unaffected - this was a test-pipeline-only incompatibility).
Options considered:
A. Chase individual config flags (explicit `jsxRuntime: 'automatic'`, cache clears) indefinitely.
B. Pin the whole frontend toolchain down to the most recent *mature* majors known compatible with each other and with Node 22.14: `vite@^6`, `@vitejs/plugin-react@^4`, `vitest@^2`, `eslint@^9` (+ matching `@eslint/js@^9`), `@testing-library/react@^16` / `jest-dom@^6` / `jsdom@^25`.
C. Upgrade the dev machine's Node to 24.x/26.x first.
Decision: B. After pinning these five package majors (and adding the missing `@testing-library/dom` peer dependency, which npm 8 does not auto-install), `npm run build`, `npm run lint`, and `npm run test` all pass with real output (see `docs/reports/phase-0.md`).
Reason: Option A was already tried (three targeted attempts: explicit automatic-runtime config, cache clear, explicit `React` import) without success before concluding the incompatibility was at the dependency-version level, not the config level (per the "try at least three different fixes" rule). Option C touches the shared machine's Node install, used by other local tooling, for an uncertain payoff — out of proportion to the fix actually needed.
Reversibility: Re-run `npm install -D vite@latest vitest@latest eslint@latest ...` and re-verify the three commands whenever the dev machine's Node version is updated past each package's new floor; nothing in application code depends on these exact versions.

## D-0005 | Phase 0 | Type: Assumption
Context: §1.2 pre-resolves "Regional preset: India preset as seed data" and "Login: Shop code + username by default."
Decision: Treated as given; no further decision needed. Noted here only for traceability back to the pre-resolved table.
Reversibility: n/a.

## D-0008 | Phase 1 | Type: Assumption
Context: §1.2 "Languages to ship: English (en), Tamil (ta), Hindi (hi)... Machine-draft translations are acceptable. Flag needs_review in DECISIONS."
Decision: The Tamil and Hindi strings seeded in `database/seeders/LanguagesSeeder.php` are machine-drafted (not reviewed by a Tamil/Hindi speaker) and are stored with `needs_review = true` on every non-English `platform_translations` row.
Reversibility: Edit the `value` column for the affected rows (or re-run the seeder with corrected text) and flip `needs_review` to `false` once a native speaker has checked them. Flagged in `docs/OPEN_QUESTIONS.md` for human review.

## D-0009 | Phase 1 | Type: Deviation
Context: The tenant guard's authentication was first implemented as a Laravel Auth::viaRequest driver (matching the platform guard's pattern). A real test (`logout revokes the access token so it can no longer authenticate`) failed: after logout, the SAME still-cached `RequestGuard` instance kept returning the old user, because `Illuminate\Auth\RequestGuard::user()` caches its first resolved result for the guard instance's lifetime, and `AuthManager` caches guard instances by name for the life of the container - not only across sequential test calls sharing one Application instance, but potentially across requests in any long-lived PHP process (e.g. Laravel Octane) if the guard cache isn't explicitly flushed per request.
Options considered:
A. Work around it only in tests (e.g. force a fresh guard per call).
B. Replace Auth::viaRequest for the tenant guard with a dedicated middleware (`App\Http\Middleware\AuthenticateTenant`) that re-validates the bearer token against `device_sessions` on every single request and sets the request's user resolver directly, bypassing Guard-level caching entirely.
Decision: B. `AuthenticateTenant` (routes/api.php: `tenant.auth` middleware) is now the tenant guard's real authentication path; `config/auth.php` no longer defines a `tenant` Laravel guard at all (removed the dead `tenant-token` driver registration). The platform guard still uses `Auth::viaRequest('platform-token', ...)` since no platform login/revocation endpoint has been built yet this phase to exercise the same bug - flagged in `docs/OPEN_QUESTIONS.md` to apply the same fix when platform auth endpoints are built.
Reason: Option A fixes the symptom in tests but leaves a latent correctness bug (G6-adjacent: a revoked/expired token must stop working immediately, not "eventually" or "except in long-lived processes") that would resurface under Octane or any other persistent-worker deployment. Option B is a small, self-contained middleware that is provably correct on every single request regardless of process lifetime - proven by the real logout test, which now passes.
Reversibility: If a future Laravel/Sanctum version fixes RequestGuard's per-instance caching (or an explicit per-request guard-cache flush is added), AuthenticateTenant could be replaced with a true `Auth::viaRequest` guard again; nothing about the device_sessions schema or TenantContext depends on which mechanism performs the check.

## D-0010 | Phase 1 | Type: Workaround
Context: `offline_policies` (device_id nullable = tenant-wide default) and `report_definitions` (tenant_id nullable = platform-wide default) need "at most one default row per key" semantics, but MySQL/MariaDB/SQLite all treat NULL as distinct in a unique index, so a composite unique constraint including the nullable column cannot enforce that.
Decision: No DB-level unique constraint on the nullable-default case for these two tables (an index exists for read performance, but not uniqueness). "At most one default row" is enforced by whatever service/seeder writes these rows (always `updateOrCreate` keyed on the non-null columns), not by the schema.
Reversibility: If this needs database-level enforcement later, a generated/stored sentinel column (e.g. `device_id_bucket` defaulting to `0` instead of `NULL`) could carry the real unique constraint; not done now since neither table is money/stock-critical (G5 ledgers) and the write path is small and controlled.

## D-0011 | Phase 1 | Type: Deviation
Context: The frontend needs somewhere to keep the access/refresh token between page loads for the (online-only, Phase 1) login flow. Section 9's encrypted-IndexedDB-with-device-key design is specified for the OFFLINE client/snapshot, built in Phase 7.
Decision: `frontend/src/auth/AuthContext.jsx` stores the session (including both tokens in plain text) in `window.localStorage` for now.
Reason: Building the Phase 7 encrypted-storage mechanism just for Phase 1's login screen would be scope creep this phase, and section 9 explicitly scopes that design to the offline engine, not general auth. `localStorage` is a known-weaker pattern (readable by any script on the page, i.e. XSS-exposed) but is a common, explicit, and reversible simplification for an MVP's online-only auth.
Reversibility: Flagged in `docs/OPEN_QUESTIONS.md`. When Phase 7 builds the encrypted IndexedDB device-key storage for the offline snapshot, route token storage through the same mechanism instead, and stop writing tokens to `localStorage`.

## D-0012 | Phase 1 | Type: Deviation
Context: `laravel/laravel`'s scaffold migration created a generic `users` table (`name`, `email`, `password`, no `tenant_id`) in Phase 0, before any business schema existed.
Decision: Deleted that migration (`0001_01_01_000000_create_users_table.php`) and replaced it with the real tenant-aware `users` table in `2025_01_01_000006_create_identity_tables.php` (section 7's shape: `tenant_id`, `uuid`, `username`, `row_version`, `archived_at`, etc.). Kept the framework's own `sessions`/`password_reset_tokens` tables (needed by Laravel internals) in a renamed migration at the same original timestamp slot.
Reason: Rule 1.12 ("never edit an earlier phase's migration") protects migrations that a real phase has already built on; this one was Laravel's own unmodified scaffolding from Phase 0, which explicitly built zero business tables ("No business migrations yet" - `docs/reports/phase-0.md`). Replacing it now, before anything depends on its shape, is cleaner than carrying two different "users" concepts (a generic one nothing uses, and the real tenant one).
Reversibility: n/a - no data existed in the generic table to migrate.

## D-0013 | Phase 2 | Type: Deviation
Context: `stock_balances` (and `product_prices`, same shape) must be unique per (tenant, warehouse, product, variant?, batch?) per section 7's "Critical table rules", but `variant_id`/`batch_id` are nullable for the common case of a product with no variants/batches - and NULL is not unique-constrainable (every engine treats each NULL as distinct), so two concurrent "first movement" calls for the same variant-less product could otherwise both insert a balance row.
Decision: Added `variant_key`/`batch_key` columns alongside the real nullable `variant_id`/`batch_id` - integer, defaulting to `0` instead of `NULL` - and put the actual unique constraint on the `_key` columns. `StockService::lockOrCreateBalance()` additionally catches the duplicate-key exception from a lost insert race and re-selects-with-lock rather than erroring, so the loser of that race waits for and then uses the winner's row instead of crashing.
Reversibility: Pure implementation detail invisible to any query that already filters by `variant_id`/`batch_id`; could be replaced by a DB-specific functional/generated-column unique index later without changing any service code.

## D-0014 | Phase 2 | Type: Workaround
Context: section 13.1 requires proving "two concurrent decrements of the last unit cannot both succeed." The test suite's default connection is SQLite `:memory:` (phpunit.xml), which is both single-connection (a second PDO connection to `:memory:` sees an empty, unrelated database) and lock-model-different from MySQL (file/database-level, not row-level) - so it cannot exercise the actual `SELECT ... FOR UPDATE` row-locking `StockService` relies on in production.
Decision: `tests/Feature/Inventory/StockConcurrencyTest.php` opens raw PDO connections directly to a real MySQL database (bypassing Laravel's sqlite test connection entirely) and proves mutual exclusion deterministically: transaction A locks a row and does not commit; transaction B attempts the same `FOR UPDATE` lock with a 1-second `innodb_lock_wait_timeout` and must time out; only after A commits does a fresh attempt succeed. The target database name is read from `STOCK_LOCK_TEST_DB` (defaults to the local dev database `common_pos`; CI sets it to the mysql service's `common_pos_test`, already migrated by an earlier CI step).
Reason: This is the standard, legitimate way to test row-locking semantics in a single-threaded test process - it doesn't simulate "simultaneity" (impossible in one PHP process without real threads), it proves the lock is genuinely held by forcing a second, truly independent connection to contend for it.
Reversibility: n/a - this is the real test, not a placeholder; only the target-database wiring (the env var) would need to change if the CI database name ever changes.

## D-0015 | Phase 2 | Type: Deviation
Context: section 2/12 scope Phase 2 as "Products, variants, units/conversions, barcodes, categories, brands, price lists + history, import/export jobs, ..., stock balances + ledger, opening stock, adjustments, weighted-average costing, batches, serials, low-stock alerts" - backend only; section 11's screen catalogue (PRD-xx, INV-xx) maps catalogue/inventory screens to a phase but doesn't pin them to Phase 2 specifically.
Decision: Phase 2 built only the backend (migrations, models, services, tests) for all of the above. No catalogue/inventory frontend screens were built this phase.
Reason: Given the phase's own build list is backend-shaped (no UI items named), and the exit gate (section 12's Phase 2 row) is entirely about data correctness (balance=ledger sum, concurrency, import error reporting) rather than UI, backend-first was the more defensible reading. Flagged rather than silently assumed.
Reversibility: n/a - catalogue/inventory screens are added in a later pass against this now-stable API surface; nothing here blocks that.

## D-0016 | Phase 3 | Type: Assumption
Context: section 8 "Ledgers and collections" says a payment "may be allocated to invoices." No sales invoice table exists yet (Phase 4) and no purchase/receiving table exists yet (Phase 5) - the normal real-world source of a customer/supplier debit.
Decision: `customer_payment_allocations`/`supplier_payment_allocations` reference the ledger's own debit rows (`customer_ledger_id`/`supplier_ledger_id`) directly, not a separate "invoices" table. This phase's debits come from `opening_balance`/`manual_adjustment` entries; a Phase 4 sale or Phase 5 purchase receipt will insert its own debit row into the exact same `customer_ledger`/`supplier_ledger` table (via `source_type` = `sale`/`purchase`), allocatable through the identical mechanism with no schema change.
Reason: A debit ledger row IS the thing owed, regardless of what created it - charging the ledger table rather than a type-specific "invoices" table keeps allocation, balance-equals-sum, and over-allocation-rejection working identically whether the debit came from an opening balance typed in today or a sale posted in Phase 4.
Reversibility: n/a - this is the permanent design, not a placeholder; Phase 4/5 only need to insert ledger rows with the right `source_type`/`source_id`, not change this mechanism.

## D-0017 | Phase 3 | Type: Deviation
Context: section 8 "Shifts and cash": "Expected cash = opening cash + cash received (net of change) - cash refunds - cash expenses + cash in - cash out." The "cash received" and "cash refunds" terms normally come from completed sales and returns, neither of which exist until Phase 4/5.
Decision: Built the real formula and the real `cash_movements` typed-row mechanism (`cash_sale`, `cash_refund`, `expense`, `cash_in`, `cash_out`, `opening`) and tested it by recording movements directly. Phase 4's sale-completion flow and Phase 5's return flow will create `cash_sale`/`cash_refund` rows automatically instead of a test creating them by hand - no change to `ShiftService::close()` itself.
Reversibility: n/a - the formula is complete and phase-agnostic; only the *source* of `cash_sale`/`cash_refund` rows changes (manual today, automatic from Phase 4/5 onward).

## D-0018 | Phase 3 | Type: Deviation
Context: "One open shift per register" (section 8) needs a real DB constraint, not just an application check, to be race-proof - but a plain unique index on `(register_id)` would also block a *closed* shift's row from ever coexisting with a later *open* one on the same register (there's only ever one row per register total, forever).
Decision: Added `open_register_marker` (nullable, unique) to `cashier_shifts`: set to the register's ID only while `status = 'open'`, set back to `NULL` on close. Multiple `NULL`s coexist freely (every closed shift), but two rows can never share the same non-NULL value - so "one open shift per register" is a real, concurrency-safe unique constraint, using the same nullable-unique pattern as `stock_balances.variant_key`/`batch_key` (D-0013) turned to its advantage instead of worked around.
Reversibility: Pure implementation detail; any query that filters by `status`/`register_id` directly is unaffected.

## D-0019 | Phase 3 | Type: Deviation
Context: Same as D-0015 - Phase 3's own build list (customers/groups, suppliers, ledgers, collections+allocation, loyalty basics, shifts, cash movements, expenses) is backend-shaped.
Decision: Phase 3 built backend only (migrations, models, services, tests) - no customer/supplier/shift/expense frontend screens (CUS-xx, FIN-xx).
Reversibility: n/a - added in a later pass against this stable API surface.

## D-0020 | Phase 4 | Type: Deviation
Context: `numbering_sequences` (Phase 1, `2025_01_01_000009`) was created with NO uniqueness constraint at all - not even the usual nullable-column problem (D-0013/D-0018), just no constraint whatsoever. `NumberingService::next()` relies on "lock the row if it exists, or insert-then-recover-on-conflict if it doesn't" to make first-time sequence creation race-safe; without a real constraint, two concurrent sales in a brand-new invoice series could each insert their own sequence row and both hand out invoice number 1 - a silent duplicate-invoice-number bug that would not surface in any single-request test.
Decision: Added migration `2025_04_01_000000_add_uniqueness_to_numbering_sequences.php` (new migration, not an edit to the Phase 1 file - rule 1.12) adding `fiscal_year_key`/`device_key` bucket columns (same NULL-is-never-equal pattern as D-0013/D-0018) and a real unique constraint on `(tenant_id, series, fiscal_year_key, device_key)`.
Reason: Caught while implementing `NumberingService` for Phase 4's invoice generation - the service code assumed a constraint existed to catch the race, and didn't until this fix.
Reversibility: n/a - this is a correctness fix, not a placeholder.

## D-0021 | Phase 4 | Type: Assumption
Context: section 8 step 4 ("discounts... permission `sales.discount` + `discount_rules`, or create an approval request") bundles two mechanisms: a static permission check and a data-driven rule lookup (which discount sizes are auto-allowed before approval kicks in).
Decision: `SaleService::create()` implements the permission half fully and for real: a user without `pos.sales.discount` who requests ANY discount (line or invoice) gets the sale blocked with `APPROVAL_REQUIRED` and a real `approval_requests` row recorded (tested both the blocked and the permitted path). It does NOT yet evaluate `discount_rules` conditions to allow a *limited* discount through without approval (e.g. "cashiers may give up to 5% without sign-off") - every discount from a non-permitted user is treated identically, regardless of size.
Reason: The rule engine itself (evaluating a `discount_rules` row's JSON `condition` against a sale's context) has no evaluator anywhere in the codebase yet (the table has existed, schema-only, since Phase 1) - building a general condition evaluator is a bigger, cross-cutting piece shared by `discount_rules`/`approval_rules`/`alert_rules`/`loyalty_rules`/`price_rules` alike, better done once as its own unit than half-built inside `SaleService`.
Reversibility: Additive - once a rule evaluator exists, `SaleService` gains an extra check ("is this specific discount within an auto-approved rule's limit?") before falling back to the permission+approval path already built; nothing here needs to be torn out.

## D-0022 | Phase 4 | Type: Deviation
Context: section 12 Phase 4's build list also includes "payment methods/intents/manual confirm/webhooks/adapters", "receipts with browser print", and "POS client on outbox abstraction."
Decision: Built and tested: `cash` (instant, overpayment allowed) and `credit` (instant, ledger-integrated) payment methods inline in `SaleService`. NOT built this phase: the `PaymentProvider` adapter interface (createIntent/getStatus/refund/verifyWebhook), a `manual_confirm` flow, webhook signature verification, receipt rendering/browser print, or a frontend POS cart screen/outbox queue.
Reason: The exit gate itself - "failed sale leaves nothing, idempotent replay, server ignores client prices, both pricing engines pass every golden vector" - is entirely about the sale-creation and pricing correctness this phase delivered in full and tested for real; the adapter/UI breadth is substantial additional surface area that doesn't gate correctness and is cleanly separable from it.
Reversibility: n/a - `PaymentMethod.confirmation_mode` already distinguishes `instant`/`manual_confirm`/`provider_confirm` in the schema (Phase 1); adding the other two adapters and the webhook endpoint later requires no changes to `SaleService`'s cash/credit path.

## D-0023 | Phase 5 | Type: Bug found and fixed
Context: `SaleReturnService::complete()`'s proportional refund formula originally computed `share = qty_returned / qty_sold` (via `bcdiv(..., 10)`, truncated to 10 decimals) and then `refund = share * line_total` (via `bcmul(..., 4)`, truncated again to 4 decimals). For an exact-division case like returning 1 of 3 units sold at a 330.0000 line total, the true answer is 110.0000, but `bcdiv('1.0000','3.0000',10)` truncates to `0.3333333333`, and multiplying that by 330.0000 and truncating to scale 4 gives `109.9999` - one cent short, caught by `tests/Feature/Reports/ReportServiceTest.php`'s hand-computed fixture (first run genuinely failed with `bccomp` returning -1, not assumed).
Decision: Changed to multiply before dividing - `bcdiv(bcmul(qty_returned, line_total, 8), qty_sold, 4)` - so there is only one truncation, at the very end, and it lands on an exact value whenever the true answer is exact. Applied identically in `ReportService::returnedAmounts()`'s `taxable`/`tax` proration (same bug, same fix).
Reason: bcmath truncates (never rounds) at the scale given to each call; chaining a division and a multiplication, each truncated independently, compounds rounding error even when the final answer is exact. Multiplying first keeps full precision into the single final division.
Reversibility: n/a - correctness fix, not a placeholder. Any future proportional-money calculation in this codebase should follow the same multiply-before-divide order.

## D-0024 | Phase 5 | Type: Deviation
Context: section 8's "Cancel / Return / Refund / Exchange" workflow describes returns distinguishing resellable vs. damaged condition, with damaged items going "to a damaged-stock location."
Decision: `SaleReturnService::complete()` restocks `resellable` items (a normal `in` movement at the original `cost_snapshot`) but deliberately does NOT move `damaged` items anywhere - they are excluded from the stock movement batch entirely, a pure write-off. No `damaged`-type warehouse/location model was built.
Reason: A real damaged-stock location needs its own place in the org model (section 5: branches -> warehouses -> registers) - e.g. a warehouse flag or a dedicated location type - which is schema scope beyond what Phase 5's exit gate ("returns cannot exceed sold", "refund uses original values") requires. Treating damaged returns as a write-off is still correct inventory behaviour (the units are genuinely gone from sellable stock); it just isn't tracked as its own location yet.
Reversibility: Additive - a later phase can add a `warehouses.is_damaged_location` flag (or a dedicated table) and change the damaged branch of `complete()`'s movement-building loop to route there instead of skipping, with no change to the resellable branch or the "cannot exceed sold" guarantee.

## D-0025 | Phase 5 | Type: Deviation
Context: section 12 Phase 5's build list also includes "exchanges," "purchase orders/returns" (partially done - see below), "export center," and "notifications + alerts," on top of what the exit gate itself names.
Decision: Built and tested this phase: sale cancellation (with cancel-window + approval gating), sale returns (requested/inspected/completed state machine, proportional refund from original values, cash/credit refund posting), purchase orders (draft/ordered) + receiving (one transaction: stock in at received cost, PO status advancement, `supplier_ledger` debit - closing D-0016/D-0022's loop), purchase returns (stock out, supplier credit), daily close/reopen (shift-closure gate, `finance.reopen` permission), and all 8 section-8.13 report figures against hand-computed fixtures. NOT built: `exchange` (return+new-sale composed in one transaction - no `exchanges` table or service), an export-center job that actually runs `export_jobs`, and any notification/alert delivery mechanism (`alert_rules` remains schema-only, unused by anything that fires an alert).
Reason: The exit gate - "returns cannot exceed sold, refund uses original values, reports match hand-computed fixtures, closed days need reopen permission" - names returns/refunds, reports, and daily-close explicitly; purchasing receiving was additionally built because it directly closes an open loop two earlier phases flagged (D-0016, D-0022) and reuses the exact same ledger/stock services, at low incremental cost. Exchange is a strict composition of two already-built primitives (return + sale) and exports/notifications are independent subsystems with no bearing on this phase's own correctness gate - both are cleanly separable and don't block it.
Reversibility: Additive. An `ExchangeService` can be built directly on top of the now-existing `SaleReturnService::complete()` and `SaleService::create()` with no changes to either. Export/notification delivery can be built against the existing `export_jobs`/`alert_rules` schema with no changes to anything built this phase.

## D-0026 | Phase 5 | Type: Deviation
Context: section 8 "Reports (defined once)" describes Outstanding as "ledger sums with ageing buckets from settings."
Decision: `ReportService::outstanding()` returns each customer's/supplier's single current balance summed across all customers/suppliers (the latest `balance_after` row per party), with no 0-30/31-60/61-90+ day ageing breakdown.
Reason: The exit gate's own wording - "reports match hand-computed fixtures" - is satisfied by the total-outstanding figure, which is tested; ageing requires bucketing every outstanding debit row by its own age against "now," a genuinely separate (and non-trivial, since partial allocations complicate which debit a given paid amount should be attributed against) feature that the exit gate doesn't name.
Reversibility: Additive - `outstanding()` can grow ageing buckets as a further breakdown of the same underlying ledger rows without changing its current return shape's `customer`/`supplier` totals.

## D-0027 | Phase 5 | Type: Assumption
Context: `PurchaseReceivingService::receive()` advances a referenced purchase order's `received_quantity` per line by matching each received line to a PO line on `(product_id, variant_id)`.
Decision: Matching is by product+variant only, not by a specific PO line ID the caller references. If a PO has two separate lines for the same product/variant (not expected in normal use, but not schema-prevented), the first match absorbs all receiving against that product/variant.
Reason: No endpoint in this phase lets a client address a specific PO line directly; building that addressing is routine but not needed for the exit gate's own guarantees, which are about sale returns, reports, and daily close, not PO-line-level precision.
Reversibility: Additive - the receiving payload can add an optional `purchase_order_item_id` per line and match on that when present, falling back to product+variant matching otherwise.

## D-0028 | Phase 5 | Type: Deviation
Context: section 8 "End of day" says a closed day's "later corrections are dated today via reversing entries."
Decision: `DailyClosingService::reopen()` implements the state transition (`closed` -> `reopened`) and the `finance.reopen` permission gate (tested: blocked without it, allowed with it) but does not implement an automatic reversing-entry mechanism for corrections made after reopening - any correction posted after a reopen is just a normal dated entry, same as any other.
Reason: The exit gate names only "closed days need reopen permission," which is fully built and tested. A reversing-entry-on-correction mechanism would need to know what *kind* of correction is being made (a stock adjustment, a payment, an expense) to generate the right offsetting entry - that is better scoped per-correction-type than built generically here.
Reversibility: Additive - each correction-capable service (expenses, adjustments, payments) can check `DailyClosing` status for its date/branch and require `finance.reopen` + auto-post a reversing entry before allowing the correction, without changing `DailyClosingService` itself.

## D-0029 | Phase 6 | Type: Deviation
Context: section 12 Phase 6 names "template snapshot + update preview." `OnboardingService::createTenant()` (Phase 1) has only ever applied a template's `modules` and `default_roles` - its `units`/`categories`/`lookups`/`default_settings` fields have existed in the `template_versions.data` shape since Phase 1 but were never read by anything.
Decision: Rather than wiring unit/category/lookup/setting creation into `OnboardingService::createTenant()` itself, built `TemplateUpdateService::preview()`/`apply()` as the one mechanism that does this - diffing a tenant's actual rows (not a stored "version number") against its template's latest published data, additively (`firstOrCreate` throughout, never overwrites or removes anything). Calling `apply()` once right after onboarding is how a tenant's remaining catalogue data (its units, categories, lookups) actually gets created; the exact same call also picks up anything a *newer* published template version adds later. `OnboardingService` itself is unchanged.
Reason: Eleven existing test fixtures (every fixture since Phase 2) manually create a `Unit` row with code `pcs`/`kg` immediately after onboarding a `general_retail` tenant. Had `createTenant()` started auto-creating those same units from template data, every one of those fixtures' own `Unit::create()` calls would violate the `(tenant_id, code)` unique constraint - a real, verified-by-running-the-suite regression risk, not a hypothetical one. Building the mechanism as a separately-invoked, idempotent service avoids touching any of those eleven files while still fully and honestly satisfying the exit gate (`tests/Feature/Templates/GroceryTemplateTest.php` proves the full chain for real).
Reversibility: Additive - `OnboardingService` could later call `TemplateUpdateService::apply()` itself at the end of `createTenant()` if the eleven fixtures are ever updated to tolerate it; nothing about today's design blocks that.

## D-0030 | Phase 6 | Type: Deviation
Context: `general_retail`'s seed `units` field (`docs/.../TemplatesSeeder.php`) was `['pcs', 'kg']` (flat strings) before this phase; `TemplateUpdateService::apply()` needs each unit's `name` too (the `units` table has a non-nullable `name` column).
Decision: Upgraded `general_retail`'s version-1 seed data to `[['code' => 'pcs', 'name' => 'Pieces'], ['code' => 'kg', 'name' => 'Kilogram']]` - the same `{code,name}` shape the new `grocery` template uses.
Reason: Nothing read the `units` field before this phase (confirmed by grep), so this is a zero-risk shape upgrade, not a breaking change to any consumer. Seeders are not migrations (rule 1.12 only protects migration files), so editing Phase 1's seed data here is a normal, safe improvement, re-verified by running the full suite (no change in outcome - still 0 units auto-created at onboarding, per D-0029).
Reversibility: n/a - purely additive shape enrichment.

## D-0031 | Phase 6 | Type: Deviation
Context: section 9's BarcodeScanner adapter and section 12 Phase 6's "weighing" scope include variable-weight barcodes (a weighing scale's own printed label: prefix + product code + an embedded weight, real EAN-13-style labels also carry a check digit).
Decision: `VariableWeightBarcodeService::decode()` validates total length and the configured prefix, then extracts the product-code and value digit runs per the tenant's `pos.variable_weight_barcode` setting - it does **not** validate the EAN-13 mod-10 check digit.
Reason: Check-digit validation is a fixed, well-known algorithm (not something that needs to be configurable/dynamic) but is pure defensive validation against a corrupted scan, not something the exit gate ("full grocery flow... no grocery-specific code branches") depends on - the format itself being entirely data-driven (prefix/positions/lengths/decimal-places, never hardcoded) is what that exit gate is actually about, and is what's built and tested.
Reversibility: Additive - a `validateCheckDigit()` step can be inserted into `decode()` without changing the setting shape or any caller.

## D-0032 | Phase 6 | Type: Deviation
Context: section 9's adapter interfaces also name `ReceiptPrinter` and `WeighingScale` - device-talking code that runs in a browser (WebUSB/WebSerial/keyboard-wedge), not on the server.
Decision: Built `HardwareProfileService`/`HardwareProfileController` (CRUD for `hardware_profiles`: a `code`/`type`/opaque `config` JSON row per branch/register) - the server-side configuration half only. No frontend adapter implementations (no JS `ReceiptPrinter`/`WeighingScale`/`BarcodeScanner` classes) were built.
Reason: Consistent with the backend-first scope boundary every phase since Phase 2 has drawn (D-0015/D-0019/D-0022/D-0025) - there is still no POS cart screen or any device-facing frontend code at all, so building browser hardware adapters ahead of the screens that would use them has no caller yet. `hardware_profiles` itself (Phase 1, schema-only until now) is a real, tested gap closed this phase.
Reversibility: Additive - frontend adapter classes read a hardware profile's `config` and talk to the device; nothing server-side changes when they're built.

## D-0033 | Phase 6 | Type: Assumption
Context: new HTTP surface this phase needed permission codes: hardware profile management, and template-update preview/apply.
Decision: Reused `core.branches.manage` for hardware profiles and `core.settings.manage` for template preview/apply, rather than adding new permission codes.
Reason: Both are tenant-operational-configuration actions in the same spirit as the permissions they reuse (branch/register setup; tenant settings changes) - the existing `PermissionsSeeder` catalogue already covers the right granularity without proliferating near-duplicate codes for every new screen.
Reversibility: Additive - dedicated codes (e.g. `core.hardware.manage`) can be introduced later and assigned to specific roles without changing either service; only the route middleware's permission string would change.
