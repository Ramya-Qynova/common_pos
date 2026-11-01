# Phase 10: Operational Management Screens & Operational Dashboard

## 1. Objectives & Scope
Phase 10 delivers the essential operational management screens and financial overview dashboard for the Advanced Universal POS system:
- **Sales & Invoices Screen (`/sales`)**: Paginated list of completed/cancelled sales with date/invoice/customer filtering, and printable thermal receipt preview modal (`ReceiptModal.jsx`).
- **Product Catalogue Screen (`/products`, `/catalog`)**: Product catalog management with SKU, barcode, unit, category, price, and current on-hand stock indicators; modal for adding new products.
- **Inventory & Stock Levels Screen (`/inventory`)**: Warehouse stock balances view with average cost, total inventory valuation, low-stock highlight badge (≤ 5 units), and stock adjustment modal.
- **Customers & Ledger Screen (`/customers`)**: Customer account management with credit limits, real-time balance calculations, customer creation modal, and double-entry customer ledger modal.
- **Business & Financial Reports Dashboard (`/reports`)**: Consolidated business overview screen showing 7 core financial metrics (Gross Sales, Net Sales, Tax Collected, COGS, Gross Profit, Current Stock Valuation, Outstanding Customer Balance) with date-range filters (Today, 7 Days, 30 Days, All Time).
- **Navigation & Routing**: Dynamically populated menu from `GET /tenant/config` via `AppShell.jsx` (G11/G12), and client-side routing via React Router DOM.

---

## 2. Architectural Adherence & Decisions
1. **Dynamic-Only Rule (G12 & Section 4)**:
   - All component labels, headers, buttons, table columns, and form placeholders use `t('key')` from `frontend/src/i18n/index.js`.
   - Verified with `node scripts/check-dynamic-only.js` with **0 violations**.
2. **Server Authoritative & Money Invariants (G1 & G3)**:
   - All financial balances, valuations, and ledger running totals are computed on the backend using `bcmath` and `DECIMAL(18,4)` precision.
   - Frontend displays formatted numbers and never performs authoritative accounting calculations.
3. **Menu Driven by Tenant Config (Section 11)**:
   - Menu items are populated from `config.menu` provided by `GET /tenant/config`.
   - If a module is disabled, its navigation entry disappears automatically without frontend redeployment.
4. **Local Concurrency Fix in Tests**:
   - Updated `StockConcurrencyTest.php` to source database credentials dynamically via `env('DB_PASSWORD')` rather than assuming default empty/root password.

---

## 3. Endpoints Added & Tested
- `GET /api/v1/sales`: Paginated sales list with search and status filters.
- `GET /api/v1/sales/{id}`: Detailed sale invoice eager loading items, taxes, customer, and payments.
- `POST /api/v1/products`: Product creation with initial barcode and price in default price list.
- `POST /api/v1/customers`: Customer profile creation with credit limit.
- `GET /api/v1/customers/{id}/ledger`: Customer debit/credit ledger history with running balance.
- `GET /api/v1/inventory/balances`: Warehouse stock balances with low stock indicator and valuation.
- `GET /api/v1/reports/summary`: Consolidated financial snapshot covering sales, taxes, cogs, profit, stock valuation, and customer balance.

---

## 4. Test Verification & Quality Gates
- **Backend Pest Suite**: **134 passed (556 assertions)** with 100% pass rate.
- **Backend Pint Formatter**: Passed cleanly with zero styling violations.
- **Frontend Vitest Suite**: **11 test files, 41 tests passed** with 100% pass rate.
- **Frontend ESLint**: Passed cleanly with 0 errors.
- **Frontend Production Build**: `npm run build` completed successfully (`dist/index.html`, `dist/assets/`).
- **Static Rules Check**: `node scripts/check-dynamic-only.js` passed with 0 violations.

