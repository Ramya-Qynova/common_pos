# Phase 13: Textile, Medical & Wholesale Business Templates & Vertical Workflows

## 1. Objectives & Scope
Phase 13 implements the third core capability of Release 2 as outlined in Section 2, 6 & 12 of the architecture specification:
- **Industry Vertical Templates (Release 2, Item 3)**:
  - **Textile & Apparel (`textile`)**:
    - Modules: `core`, `catalog`, `inventory`, `pos`, `sales_mgmt`, `customers`, `finance`, `reports`, `variants`.
    - Units: Meters (`mtr`), Yards (`yd`), Rolls (`roll`), Dozens (`dzn`), Pairs (`pair`), Pieces (`pcs`).
    - Categories: Men's Apparel (`MEN`), Women's Apparel (`WOMEN`), Kids & Infants (`KIDS`), Fabrics & Yardage (`FABRIC`), Accessories & Footwear (`ACCESSORIES`).
    - Lookups: Season (`season`: `summer`, `winter`, `monsoon`, `festive`), Fit Type (`fit_type`: `regular`, `slim`, `relaxed`, `oversized`).
    - Custom Fields: Fabric / Material (`fabric`), Style / Design Code (`style_code`), Target Gender (`gender`).
    - Settings: `inventory.costing_method` => `'fifo'`.
    - UI Hints: `catalog.variant_matrix` => true.
  - **Pharmacy & Healthcare (`medical`)**:
    - Modules: `core`, `catalog`, `inventory`, `pos`, `sales_mgmt`, `purchases`, `customers`, `finance`, `reports`, `batch_expiry`, `serial_tracking`.
    - Units: Strip (`strip`), Tablet (`tab`), Bottle (`btl`), Box (`box`), Tube (`tube`), Vial (`vial`), Ampoule (`amp`), Sachet (`sachet`).
    - Categories: Prescription Drugs (`RX`), OTC (`OTC`), First Aid & Surgical (`FIRST_AID`), Medical Devices (`DEVICES`), Vitamins & Supplements (`SUPPLEMENTS`).
    - Lookups: Drug Schedule (`drug_schedule`: `general`, `schedule_h`, `schedule_h1`, `schedule_x`), Storage Condition (`storage_condition`: `ambient`, `refrigerated`, `cold`).
    - Custom Fields: Generic / Salt Name (`generic_name`), Drug License Number (`drug_license_no`), HSN Classification (`hsn_code`).
    - Settings: `inventory.batch_pick_order` => `'fefo'`, `inventory.expiry_block_days` => 30, `inventory.costing_method` => `'fifo'`.
    - UI Hints: `inventory.highlight_expiring` => true.
  - **Wholesale & B2B Distribution (`wholesale`)**:
    - Modules: `core`, `catalog`, `inventory`, `pos`, `sales_mgmt`, `purchases`, `customers`, `finance`, `reports`, `price_lists_advanced`.
    - Units: Carton (`ctn`), Pallet (`plt`), Box (`box`), Bag (`bag`), Kilogram (`kg`), Pack (`pack`), Pieces (`pcs`).
    - Categories: Bulk Staples (`BULK_FOOD`), FMCG Case Packs (`FMCG_WHOLESALE`), Commercial & Institutional (`COMMERCIAL`), Industrial Raw Materials (`RAW_MATERIALS`).
    - Lookups: Payment Terms (`payment_terms`: `immediate`, `net_15`, `net_30`, `net_60`), Transport Mode (`transport_mode`: `road_truck`, `rail_cargo`, `air_cargo`, `express_courier`).
    - Custom Fields: Minimum Order Quantity (`moq`), Case Pack Size (`case_pack_size`), Buyer GSTIN / Tax ID (`tax_id_gstin`).
    - Settings: `sales.allow_credit_sales` => true, `inventory.costing_method` => `'fifo'`.
    - UI Hints: `sales.show_case_pack` => true.
- **Dynamic Configuration & Custom Fields Provisioning**:
  - Enhanced `TemplateUpdateService` (`preview()` & `apply()`) to automatically detect, compare, and provision `custom_fields` definitions from templates directly into `CustomFieldDefinition` rows.
- **API Endpoints Added**:
  - `GET /api/v1/templates`: List active industry templates with metadata and vertical configurations.
  - `GET /api/v1/templates/{id}`: Detailed template inspection.
  - `GET /api/v1/custom-fields`: Tenant-scoped custom fields list, filterable by `entity_type`.
  - `POST /api/v1/custom-fields`: Create custom field definition for tenant with full validation.
- **Frontend Operational UI**:
  - `TemplatesScreen.jsx`: Business template explorer, vertical inspect modal, update preview, and one-click application.
  - Dynamic-Only Rule (G12): 100% dictionary-driven translation keys via `i18n`, zero hardcoded template branches.

---

## 2. Models & Components Created / Enhanced
- `backend/database/seeders/TemplatesSeeder.php`: Seeded versioned data bundles for `textile`, `medical`, and `wholesale`.
- `backend/app/Services/Onboarding/TemplateUpdateService.php`: Extended with custom field diff calculation, provisioning, and audit logging.
- `backend/app/Http/Controllers/Api/V1/TemplateController.php`: Template listing and detail controller.
- `backend/app/Http/Controllers/Api/V1/CustomFieldController.php`: Custom fields management controller.
- `frontend/src/screens/TemplatesScreen.jsx`: Template management and update inspection UI.
- `frontend/src/i18n/index.js`: Comprehensive dictionary keys for templates and custom fields.
- `backend/tests/Feature/Templates/VerticalTemplatesTest.php`: Feature test suite for all vertical templates and endpoints.

---

## 3. Endpoints Added & Tested
- `GET /api/v1/templates`
- `GET /api/v1/templates/{id}`
- `GET /api/v1/custom-fields`
- `POST /api/v1/custom-fields`

---

## 4. Test Verification & Quality Gates
- **Backend Pest Suite**: **151 passed (736 assertions)** across 100% of test files.
- **Backend Pint Formatter**: 0 issues, fully PSR-12 compliant.
- **Frontend Vitest Suite**: **11 test suites, 45 passed (100%)**.
- **Frontend ESLint**: 0 errors.
- **Frontend Vite Build**: Production bundle created successfully (`dist/`).
- **Dynamic-Only Static Check**: 0 violations found.

