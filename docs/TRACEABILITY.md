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

## Screen-ID mapping (section 11)

| Screen ID (guide) | Built as | Notes |
|---|---|---|
| AUTH-01 (Login) | `frontend/src/screens/LoginScreen.jsx` | Shop code + username + password, generic error message, lockout message |
| n/a (post-login shell) | `frontend/src/screens/AppShell.jsx` | Not a numbered screen in the guide; the dynamic-menu shell every other screen will render inside, starting Phase 2 |

The rest of section 11's catalogue (SA-xx, ON-xx, DASH-xx, POS-xx, SAL-xx, PRD-xx, INV-xx, PUR-xx, CUS-xx, FIN-xx, REP-xx, USR-xx, SET-xx, SYN-xx, TPL-VEG-xx, APR-xx, NOT-xx) has no screen yet — each is mapped here in the commit that actually builds it, starting with Phase 2's catalogue/inventory screens.
