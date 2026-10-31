# Phase 1 report — Tenancy, identity, settings, onboarding

## 1. Scope completed

Per `docs/MASTER_PROMPT.md` section 12, Phase 1: platform/tenant/config migrations, tenancy + global scope, two guards, auth/tokens/devices/security events, roles/permissions/branch access, settings service + cache, modules, templates, custom fields (schema), rules (schema), lookups (schema), translations (seeded), numbering/tax/payment methods (schema), audit + approval frameworks (schema), onboarding backend + go-live precursor, and a first matching screen pair (login + dynamic shell).

Built and verified for real this phase:

- **Schema** (15 migrations, `2025_01_01_0000{01..15}_*`): platform identity, plan/module/template catalogue, tenant core, three-layer settings, org (branches/warehouses/registers), tenant RBAC, device/session/security-event, audit log, numbering/tax/payment methods, lookups/custom fields, translations (split into `platform_translations` + `translations` to avoid the same NULL-uniqueness problem documented for `offline_policies` below, D-0010), document templates/hardware profiles/offline policies, the five rule-engine tables, status/report definitions, approval requests/approvals. All 15 ran clean against a real local MySQL 9.4 database (`php artisan migrate:fresh`).
- **Tenant isolation**: `App\Support\TenantContext` (request-scoped singleton, populated only from an authenticated identity — G2) + `App\Models\Concerns\BelongsToTenant` (global scope + auto-fill on create). Proven, not asserted, by `TenantIsolationTest`.
- **Two guards** (section 5 "Identity"): the platform guard uses a real Laravel `Auth::viaRequest` driver against `platform_sessions`. The tenant guard deliberately does **not** — a real test caught `Illuminate\Auth\RequestGuard` caching a resolved user past token revocation, so tenant authentication is a dedicated middleware (`AuthenticateTenant`) that re-validates on every request. Full root-cause writeup: D-0009.
- **Auth**: `TenantAuthService` — login (shop_code + username + password), account lockout (failed-attempt counter + `locked_until`, threshold itself a setting), token issuance (random 32-byte access+refresh pair, SHA-256 hashed at rest, G15), refresh-with-rotation, logout-revokes. Generic 401 on any failure reason (no user enumeration).
- **RBAC** (G11): `permissions` catalogue (21 codes seeded), `roles`/`role_permissions` as tenant-owned rows (seeded from the template snapshot at onboarding, not hardcoded), `EnsurePermission` middleware.
- **Settings**: `SettingsService` with the full branch → tenant → plan → platform resolution chain and version-based cache invalidation (not key deletion) — all three override layers and the invalidation-on-write behaviour are individually tested.
- **Modules**: `TenantConfigController`'s `GET /tenant/config` builds its `menu` array only from enabled `tenant_modules` joined to `module_definitions.menu` — proven by disabling a module mid-test and re-fetching.
- **Onboarding**: `OnboardingService::createTenant()` — one DB transaction creating the tenant, subscription, enabled modules, template snapshot, roles (from the template's `default_roles`), branch/warehouse/register, and owner user with branch access, ending in `status = 'setup'`.
- **Seeders** (platform catalogue only, never tenant/business rows — rule 1.13): platform roles, 21 permissions, 17 module definitions, a "standard" plan (all modules + limits), a "general_retail" template (8 default modules, 3 default roles: owner/admin/cashier), 3 languages + a starter en/ta/hi translation dictionary, and the full ~35-key platform settings catalogue from section 6 with India-preset defaults.
- **Frontend**: `AuthContext` (login/logout, session persisted to `localStorage` — a deliberate Phase 1 simplification, D-0011), `LoginScreen` (shop code/username/password, generic + lockout-specific error messages), `AppShell` (fetches `GET /tenant/config` on mount, renders the menu from server data only — no hardcoded menu array anywhere in the component).

## 2. Files changed and migrations added

15 new migration files (see `docs/data-dictionary.md` "Table index — Phase 1" for the full list and table-to-file mapping); the Phase 0 default `users`/`password_reset_tokens`/`sessions` migration was removed and split (D-0012). ~45 new model files under `backend/app/Models/` (incl. `Platform/`). New service classes: `TenantContext`, `SettingsService`, `TenantAuthService`, `TokenPair`, `OnboardingService`, `AuditLogger`. New middleware: `AuthenticateTenant`, `EnsureTenantActive`, `EnsureModuleEnabled`, `EnsurePermission`, `EnsureBranchAccess`. New controllers: `AuthController`, `TenantConfigController`. New `routes/api.php`. 7 new seeder classes + rewritten `DatabaseSeeder`. Updated `config/auth.php`, `bootstrap/app.php` (API routing, middleware aliases, standard exception → `ApiResponse` rendering), `phpstan.neon` (unchanged, still passes at level 5). Frontend: `api/client.js`, `auth/AuthContext.jsx`, `screens/{LoginScreen,AppShell}.{jsx,css}`, updated `App.jsx`, `i18n/index.js`.

## 3. Endpoints, permission codes, setting keys, translation keys added

**Endpoints**: `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`, `GET /api/v1/tenant/config`.

**Permission codes** (21): `core.settings.manage`, `core.users.manage`, `core.roles.manage`, `core.branches.manage`, `core.audit.view`, `catalog.products.manage`, `inventory.adjust`, `inventory.transfer`, `inventory.view`, `pos.sales.create`, `pos.sales.discount`, `pos.sales.cancel`, `pos.sales.return`, `pos.shift.open`, `pos.shift.close`, `purchases.manage`, `customers.manage`, `finance.expenses.manage`, `finance.daily_close`, `finance.reopen`, `reports.view`, `approvals.approve`.

**Setting keys** (35, full section 6 catalogue — see `backend/database/seeders/PlatformSettingsSeeder.php` for every default value).

**Translation keys** (17, × 3 languages): `app.boot.{title,subtitle}`, `auth.login.{title,shop_code,username,password,submit,failed,locked}`, `menu.{pos,catalog,inventory,sales,purchases,customers,finance,reports}`.

## 4. Tests added, with real executed results

**Backend** (`cd backend && php artisan test`):
```
Tests:    22 passed (68 assertions)
Duration: 8.00s
```
Breakdown: `OnboardingServiceTest` (1), `LoginTest` (6), `PermissionTest` (3), `ModuleDisabledTest` (2), `SettingsServiceTest` (5), `TenantIsolationTest` (3), plus the 2 Phase-0 example tests still passing.

**Backend style** (`php vendor/bin/pint --test`): `{"tool":"pint","result":"passed"}`
**Backend static analysis** (`php vendor/bin/phpstan analyse --no-progress`, level 5): `[OK] No errors`

**Frontend** (`cd frontend && npm run test`):
```
Test Files  3 passed (3)
     Tests  6 passed (6)
```
(`App.test.jsx`, `LoginScreen.test.jsx` ×3, `AppShell.test.jsx` ×2.)

**Frontend lint** (`npm run lint`): 1 warning (`react-refresh/only-export-components` on `AuthContext.jsx`, a non-blocking HMR-only hint about exporting both a component and a hook from one file — exit code 0), 0 errors.
**Frontend build** (`npm run build`): `✓ 38 modules transformed`, built successfully.

**Dynamic-Only Rule check** (`node scripts/check-dynamic-only.js`): `Dynamic-Only Rule check: no violations found.` `EXIT:0` — re-run against the full Phase 1 codebase (not re-demonstrating the inject/remove cycle again; that was proven once in Phase 0 and the checker itself didn't change).

## 5. Dynamic-Only static-check results

Clean (section 4 above). No role-name literals, currency/tax literals, or hardcoded UI text were introduced — all 21 permission checks go through `hasPermission($code)`, all settings reads go through `SettingsService`, and the two screens render only translation-keyed and server-supplied text.

## 6. Known issues, deviations

- D-0008: Tamil/Hindi translations are machine-drafted, flagged `needs_review`.
- D-0009: Tenant auth deliberately bypasses `Auth::viaRequest` (stale-cache-after-revocation bug, caught by a real test, fixed with dedicated middleware). Platform guard still uses `Auth::viaRequest` since no platform login endpoint exists yet to exercise the same bug — flagged for when it is built.
- D-0010: `offline_policies`/`report_definitions` "one default row" rule is enforced by the write path, not a DB constraint (NULL-uniqueness limitation, shared by MySQL/MariaDB/SQLite).
- D-0011: Frontend stores tokens in plain `localStorage`, not yet the Phase 7 encrypted-IndexedDB mechanism.
- D-0012: Removed Laravel's generic default `users` migration/model (never used by any business logic) in favour of the real tenant-aware schema.
- `GET /tenant/config` does not yet return `settings`-resolved `translations`, `payment_methods`, `tax_groups`, or `document_templates` — only modules/menu/branches/permissions/a handful of locale+sales settings. Section 8 lists a fuller response; the rest is added as each owning piece (tax, payment methods, etc.) gets real service logic in its phase, rather than exposing empty/placeholder data now.
- Numbering, tax, payment methods, lookups, custom fields, document templates, hardware profiles, offline policies, the five rule tables, and approvals have migrations + Eloquent models only — no service logic, no seeded rows beyond what onboarding needs, no tests beyond "the table exists." This is intentional per the phase table (their real logic lands in Phases 2, 4, 6, 7 respectively) but is flagged here so it's never mistaken for "done."
- No platform-guard login/logout endpoints were built (only the guard mechanism) — there is currently no way to actually authenticate as a platform user over HTTP. Needed before Phase 1's "Platform" screen group (SA-xx) or any platform-admin-driven onboarding UI can be built.
- CI (`.github/workflows/ci.yml`) has still not been observed running inside GitHub Actions itself — carried over from the Phase 0 report; will be confirmed on this phase's push.

## 7. Remaining work / next phase

Phase 1's stated exit gate — "Tenant isolation, permission, login, lockout and dynamic-configuration tests pass. Changing a setting or module changes behaviour with no deployment" — is met and demonstrated by the 22 passing tests above. The gaps listed in section 6 are deliberate scope boundaries matching the phase table, not shortfalls against this phase's own exit gate.

Phase 2 per section 12: products, variants, units/conversions, barcodes, categories, brands, price lists + history, import/export jobs, warehouses, registers (already have the tables; Phase 2 adds the business logic), stock balances + ledger, opening stock, adjustments, weighted-average costing, batches, serials, low-stock alerts. Its exit gate: stock balance always equals ledger sum; two concurrent decrements of the last unit cannot both succeed; import reports every rejected row. Work begins immediately following this report.
