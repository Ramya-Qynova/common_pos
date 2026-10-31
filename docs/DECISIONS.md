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
