# Phase 6 report — Hardware adapters & Vegetable/Grocery template

## 1. Scope completed

Per `docs/MASTER_PROMPT.md` section 12, Phase 6: hardware adapters, Vegetable/Grocery template (weighing, daily rate update, wastage), template snapshot + update preview. Exit gate: "Template data drives the full grocery flow with **no grocery-specific code branches**."

- **The `grocery` template, as pure data**: added to `TemplatesSeeder` alongside `general_retail`, built on the exact same `templates`/`template_versions` engine - `modules` (adds `weighing`, `batch_expiry`), `units` (kg, gram, pcs), `categories` (Vegetables, Fruits, Leafy Greens), `lookups` (a `wastage_reason` type with Spoiled/Expired/Damaged-in-Transit values), and `default_settings` (a tenant-level override turning on `pos.variable_weight_barcode`). Nothing in `app/` ever compares a template code or name - verified by `node scripts/check-dynamic-only.js` and directly demonstrated by a test that runs the identical `TemplateUpdateService::apply()` call against a `general_retail` tenant and gets only `general_retail`'s own data back.
- **`TemplateUpdateService::preview()`/`apply()`** - the "template snapshot + update preview" mechanism named in the build list. It diffs a tenant's *actual* rows (Units, Categories, LookupTypes, TenantModules, Roles, Settings) against its template's latest published version's data, and additively (`firstOrCreate` throughout - never overwrites or removes a tenant's own customization) creates whatever is missing. This single mechanism serves two real needs: finishing a tenant's catalogue setup right after onboarding (which has only ever applied `modules`+`default_roles` - see D-0029), and picking up whatever a *newer* published template version adds later - proven directly by publishing a real `TemplateVersion` 2 mid-test (adding a `DAIRY` category) and watching `preview()` surface exactly that one addition, then `apply()` add it without touching anything already there.
- **Weighing, as a module flag + a setting, never a template check**: `VariableWeightBarcodeService::resolve()` tries an exact `barcodes` match first (works for every tenant, any template); only if that fails does it check `Tenant::isModuleEnabled('weighing')` (built in Phase 1, exercised for the first time this phase) and, if enabled, decode the scanned code against the tenant's own `pos.variable_weight_barcode` setting (prefix/positions/lengths/decimal-places - all data, platform default is *disabled*). A test proves the identical scanned barcode resolves on a `grocery` tenant and resolves to nothing on a `general_retail` tenant with the same barcode row present - the module flag is what gates it, not a template comparison.
- **The exit gate's own claim, proven directly**: a weight-derived fractional quantity (`0.4560` kg, decoded from a fabricated EAN-13-style barcode) is submitted to the completely unmodified Phase 4 `SaleService`/`PricingEngine` and produces a correct total - no grocery-specific branch exists anywhere in the sale path, because G3's decimal-string quantity handling already supported fractional units for any product, in any phase.
- **Daily rate update**: `PriceListService::bulkSetPrices()` - a generic batch wrapper over the existing history-preserving `setPrice()`, one transaction, one audit entry. Tested changing two products' prices in one call with full history preserved.
- **Wastage**: Phase 2's `StockAdjustmentService` was already fully generic (`type`/`reason` are free strings) but had no HTTP route. `StockAdjustmentController` gives it one. Tested identically on `grocery` and `general_retail` tenants with the same request shape - wastage is not a special case anywhere.
- **Hardware adapters (server half)**: `HardwareProfileService`/`HardwareProfileController` - CRUD for `hardware_profiles` (Phase 1, schema-only until now): a `code`/`type`/opaque `config` JSON row per branch/register, never branching on a vendor or device model.

## 2. Files changed and migrations added

1 new migration: `barcodes.type` column (`2025_06_01_000001_add_type_to_barcodes_table.php`, additive, not an edit to the Phase 2 file - rule 1.12). New services: `App\Services\Onboarding\TemplateUpdateService`, `App\Services\Catalogue\VariableWeightBarcodeService`, `App\Services\Hardware\HardwareProfileService`; `App\Services\Catalogue\PriceListService` gained `bulkSetPrices()`; `App\Services\Finance\ShiftService` unchanged. New controllers/routes: `GET /barcodes/resolve`, `POST /price-lists/{id}/bulk-update`, `POST /stock-adjustments`, `GET`+`POST /template/update-preview`+`/template/update`, `GET`+`POST /hardware-profiles`. Seed data: `grocery` template added to `TemplatesSeeder`; `general_retail`'s `units` field upgraded from flat strings to `{code,name}` objects (D-0030); `pos.variable_weight_barcode` added to `PlatformSettingsSeeder` (disabled by default).

## 3. Endpoints, permission codes, setting keys added

**Endpoints**: `GET /api/v1/barcodes/resolve`, `POST /api/v1/price-lists/{priceList}/bulk-update`, `POST /api/v1/stock-adjustments`, `GET /api/v1/template/update-preview`, `POST /api/v1/template/update`, `GET /api/v1/hardware-profiles`, `POST /api/v1/hardware-profiles`.

**Permission codes**: none new - hardware profiles and template-update reuse `core.branches.manage` and `core.settings.manage` respectively (D-0033); `catalog.products.manage` and `inventory.adjust` (both seeded Phase 1) are enforced by real code for the first time via the new price-list and stock-adjustment routes.

**Setting keys**: `pos.variable_weight_barcode` (new, seeded disabled platform-wide; the `grocery` template overrides it to enabled with real format defaults as tenant-level data).

## 4. Tests added, with real executed results

**Backend** (`cd backend && php artisan test`):
```
Tests:    96 passed (379 assertions)
Duration: 40.85s
```
New this phase (13): `GroceryTemplateTest` (5), `VariableWeightBarcodeServiceTest` (4), `WastageAdjustmentTest` (3), `PriceListBulkUpdateTest` (1).

**Backend style** (`php vendor/bin/pint --test`): `{"tool":"pint","result":"passed"}` (one auto-fix pass for import ordering in the new test files, same discipline as every prior phase).
**Backend static analysis** (`php vendor/bin/phpstan analyse --no-progress`, level 5): `[OK] No errors` on the first run - no generic-type gaps this phase.
**Dynamic-Only Rule check**: `Dynamic-Only Rule check: no violations found.`

**Frontend** (unaffected - backend-only phase, same as Phases 2/3/5): `npm run lint` (0 errors, 1 pre-existing warning), `npm run format` (passed), `npm run test` (14/14 passed), `npm run build` (succeeded).

**Full regression check**: before writing any Phase 6 code, the 83 Phase 0-5 tests were re-run after the `TemplatesSeeder`/`PlatformSettingsSeeder` edits and again after every new file - all 83 stayed green throughout, confirming the deliberately-chosen architecture (D-0029: catalogue-data application lives in `TemplateUpdateService`, not `OnboardingService`) avoided the real regression risk that was identified and designed around, not discovered after the fact.

## 5. Dynamic-Only static-check results

Clean. The one thing worth calling out explicitly: `grocery` and `general_retail` are both handled by the identical `TemplateUpdateService`/`OnboardingService` code paths - grep confirms no file under `backend/app` ever contains a template-code or template-name comparison.

## 6. Known issues, deviations

- D-0029: template catalogue-data application lives in `TemplateUpdateService`, not `OnboardingService::createTenant()` - a deliberate choice to avoid a real, verified regression risk across 11 existing test fixtures.
- D-0030: `general_retail`'s seed `units` shape upgraded from flat strings to `{code,name}` objects (safe - nothing read the old shape).
- D-0031: no EAN-13 check-digit validation on variable-weight barcodes.
- D-0032: hardware adapters are server-config CRUD only; no frontend device-talking code (no POS screens exist yet at all).
- D-0033: hardware-profile and template-update permissions reuse existing codes rather than adding new ones.

## 7. Remaining work / next phase

Phase 6's exit gate - "template data drives the full grocery flow with no grocery-specific code branches" - is met and demonstrated directly: the grocery template is pure data, the same generic services produce different (correct) results for `grocery` vs. `general_retail` tenants, and a weight-derived quantity flows through the completely unmodified sale path. The gaps in section 6 are deliberate scope boundaries, not shortfalls against this phase's own exit gate.

Phase 7 per section 12: the offline engine - device policy, snapshot + incremental pull, outbox push, idempotency, conflicts + resolution screens, revoke + wipe, encrypted local storage, offline numbering. Exit gate: all section 13.2 offline tests pass. This is also where real frontend work resumes (the first since Phase 1's login/shell screens) - the IndexedDB outbox abstraction section 9 says the POS client should be built on from the start. Work begins immediately following this report.
