# Phase 9 report — POS billing screen, real SPA routing, offline outbox wired to UI

## 1. Scope completed

Per `docs/PROGRESS.md` ("Next steps: Build actual POS/catalogue/inventory/customer/etc. frontend screens - the single biggest remaining gap: every phase since Phase 2 has been backend-only by deliberate scope"):

- **POS billing screen (`frontend/src/screens/PosScreen.jsx`, POS-01)**:
  - Branch, register, and price list selection populated dynamically from `GET /tenant/config` (D-0050).
  - Shift open and close panel with opening cash balance and cash difference calculation (`GET /shifts/current`, `POST /shifts/open`, `POST /shifts/{shift}/close`).
  - Shift-required gating (honours `sales.shift_required` tenant setting).
  - Debounced product catalogue search (`GET /products?search=...`) with stock indicators and unit price display.
  - Cart item management: add, increment/decrement quantity, and remove item.
  - Optional customer search and attachment (`GET /customers?search=...`).
  - Checkout pipeline routed through the Phase 7 offline outbox (`useOffline().enqueueSale -> outbox.enqueue + SyncClient.flush`) rather than direct `POST /sales`, unifying online and offline billing into the same robust flow (D-0053).
- **SPA Routing & AppShell Navigation**:
  - React Router DOM (`BrowserRouter`, `Routes`, `Route`) replacing full-page reload navigation.
  - `<Outlet context={config}>` passing resolved configuration down to child screens without redundant network fetches.
  - Global connection status badge (`ConnectionBadge`) showing Online, Offline, Syncing, or Sync Error with pending queue counts and manual "Sync Now" button.
- **Device Identity**:
  - `frontend/src/offline/device.js` generates persistent `device_uuid` and passes it on login (`AuthContext.login()`), tying the client device to backend device sessions and device-scoped numbering.
- **Bug found and fixed via live testing (D-0051)**:
  - In `PosScreen.jsx`, shift close confirmation leaked into the next shift's opened state due to state branch ordering. Moved confirmation to the post-close branch and verified with regression tests.

## 2. Files changed and migrations added

No new migrations (uses existing Phase 1–8 tables).

**Backend added/modified**:
- `backend/app/Http/Controllers/Api/V1/ProductController.php`
- `backend/app/Http/Controllers/Api/V1/CustomerController.php`
- `backend/app/Http/Controllers/Api/V1/ShiftController.php`
- `backend/app/Http/Controllers/Api/V1/TenantConfigController.php`
- `backend/routes/api.php`
- Tests: `ProductControllerTest.php`, `CustomerControllerTest.php`, `ShiftControllerTest.php`, `TenantConfigControllerTest.php`

**Frontend added/modified**:
- `frontend/src/screens/PosScreen.jsx` & `PosScreen.css`
- `frontend/src/screens/PosScreen.test.jsx`
- `frontend/src/offline/device.js` & `device.test.js`
- `frontend/src/offline/OfflineContext.jsx`
- `frontend/src/screens/AppShell.jsx` & `AppShell.css` & `AppShell.test.jsx`
- `frontend/src/auth/AuthContext.jsx`
- `frontend/src/i18n/index.js`
- `frontend/src/App.jsx`

## 3. Endpoints, permission codes, setting keys, translation keys added

**Endpoints**:
- `GET /api/v1/products` (permission: `catalog.products.read`, module: `catalog`)
- `GET /api/v1/customers` (permission: `customers.view`, module: `customers`)
- `GET /api/v1/shifts/current` (permission: `pos.shifts.view`, module: `pos`)
- `POST /api/v1/shifts/open` (permission: `pos.shifts.open`, module: `pos`)
- `POST /api/v1/shifts/{shift}/close` (permission: `pos.shifts.close`, module: `pos`)
- Enhanced `GET /api/v1/tenant/config` with registers, warehouses, price lists, payment methods, units.

## 4. Tests added with real executed results

- **Frontend tests** (`cd frontend && npm run test`):
  `36 passed (36)` across 10 test files.
- **Backend tests**:
  128 passed across all feature test suites.
- **Static & Dynamic-Only checks**:
  - `node scripts/check-dynamic-only.js`: no violations.
  - `npm run lint`: 0 errors.

## 5. Decisions logged

- **D-0050**: Extended `GET /tenant/config` with active registers, warehouses, price lists, payment methods, and units.
- **D-0051**: Fixed shift-close confirmation rendering bug.
- **D-0052**: Client-side cart preview displays subtotal; authoritative grand total calculated by server/sync engine (G1).
- **D-0053**: POS checkout always enqueues via offline outbox abstraction, unifying online and offline billing.

