# PROGRESS.md

Resumability log for the Advanced Universal POS build. Read this and `docs/DECISIONS.md` first on every resume.

## Current phase
Phase 1 — Tenancy, identity, settings, onboarding: **complete**, tagged `phase-1-complete`. See `docs/reports/phase-1.md` for full detail and real executed test output. Phase 2 starts next.

## Done (Phase 1, on top of Phase 0)
- 15 migrations for the platform/tenant/config/org/identity domains, all run clean against real MySQL.
- `TenantContext` + `BelongsToTenant` global scope — the actual tenant-isolation mechanism (G2/G14), proven by `TenantIsolationTest`.
- Two guards: platform (real `Auth::viaRequest`) and tenant (dedicated `AuthenticateTenant` middleware, NOT `Auth::viaRequest` — see D-0009 for why a real test caught a stale-cache bug there).
- `TenantAuthService`: login, account lockout (threshold is itself a setting), token issuance/rotation/revocation, generic failure messages.
- RBAC: 21 permissions seeded, tenant-owned roles (seeded from template at onboarding), `EnsurePermission` middleware — all permission/role logic is by code, never by name (G11).
- `SettingsService`: full branch → tenant → plan → platform resolution, version-based cache invalidation — all four layers individually tested, including the "change a setting, see new behaviour on the very next read" case.
- `EnsureModuleEnabled` + `TenantConfigController`: disabling a module removes its menu entry and would 403 `MODULE_DISABLED` on a module-gated route, with no code change — tested end to end.
- `OnboardingService::createTenant()`: tenant + subscription + modules + template snapshot + roles + branch/warehouse/register + owner, all in one transaction, ending in `status = 'setup'`.
- Platform catalogue seeded as real data rows: roles, permissions, modules, a "standard" plan, a "general_retail" template, 3 languages + starter translations, the full ~35-key settings catalogue.
- Frontend: login screen + a post-login shell whose menu comes **only** from `GET /tenant/config` (no hardcoded menu array anywhere).
- 22 backend tests + 6 frontend tests, all passing for real; Pint/Larastan/ESLint/build all clean; Dynamic-Only Rule check still passes.

## Next 5 steps (start of Phase 2)
1. Catalogue migrations: `products`, `product_variants`, `categories`, `brands`, `units`, `product_units`, `barcodes`, `price_lists`, `price_list_items`, `product_prices`, `product_recipes`.
2. Inventory migrations: `stock_balances`, `stock_ledger`, `stock_adjustments`(+items), `stock_reconciliations`(+items), `stock_transfers`(+items), `batches`, `serial_numbers`, `cost_layers`.
3. Write the concurrency test FIRST (section 13.1: "two concurrent decrements of the last unit cannot both succeed") before writing the stock service — the locking-order rule in `docs/data-dictionary.md` ("Locking order") governs the implementation.
4. Stock service: opening stock / adjustment / weighted-average costing, all under the lock-then-ledger-then-balance pattern from section 8 "Stock operations."
5. Low-stock alerts, import/export job scaffolding, catalogue + inventory screens, phase-2 report, tag `phase-2-complete`.

## How to run tests
- Backend: `cd backend && php artisan test`; style: `php vendor/bin/pint --test`; static analysis: `php vendor/bin/phpstan analyse --no-progress`
- Frontend: `cd frontend && npm run test` (Vitest + RTL); `npm run lint`; `npm run build`
- Dynamic-Only static check: `node scripts/check-dynamic-only.js`
- Env check: `pwsh scripts/check-env.ps1` (or `powershell -File scripts/check-env.ps1` if `pwsh` isn't on PATH)

## Known issues
- PHP available locally is 8.2.12, below the prompt's 8.3 minimum (D-0001) — not a functional blocker.
- `gh` CLI not installed; using plain `git` via the existing Git Credential Manager (D-0002).
- Frontend toolchain pinned to Vite 6 / Vitest 2 / ESLint 9 instead of newer defaults that broke on this machine's Node 22.14 (D-0007).
- Platform guard has no login/logout HTTP endpoints yet (only the auth mechanism) and still uses `Auth::viaRequest`, unlike the tenant guard — fix when platform endpoints are built (D-0009).
- `GET /tenant/config` doesn't yet return translations/tax/payment-method/document-template data — those are added as their owning phase gives them real service logic.
- Numbering, tax, payment methods, lookups, custom fields, document templates, hardware profiles, offline policies, rule tables, and approvals have schema + models only, no service logic yet (by design — see phase table).
- Frontend auth tokens live in plain `localStorage` (D-0011) pending Phase 7's encrypted IndexedDB mechanism.
- CI workflow still not observed running inside GitHub Actions itself (only run locally) — will confirm on this phase's push.

## Notes for the next session
This is a multi-week-scale build (8 phases, ~80 tables, full offline sync engine). Work proceeds phase by phase with real commits/tags per phase; do not assume later phases are done just because this file exists — check the "Current phase" line above and the latest `docs/reports/phase-N.md`.
