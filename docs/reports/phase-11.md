# Phase 11: Multi-Warehouse Stock Transfers, Transit Tracking & Stock Reconciliation

## 1. Objectives & Scope
Phase 11 implements the first core capability of Release 2 as outlined in Section 2 & 12 of the architecture specification:
- **Inter-Warehouse Stock Transfers**:
  - Full lifecycle state machine (G7): `draft` → `dispatched` → `received` (or `partially_received` / `cancelled`).
  - Strict append-only ledger movements (G5): Decrements source warehouse at `average_cost` upon dispatch; tracks in-transit stock; increments destination warehouse upon receipt.
  - Transit discrepancy & shrinkage handling: Discrepancies between dispatched and received quantities are recorded directly on transfer items and flagged.
  - Safe cancellation: Reverses dispatched stock back to source warehouse without mutating past ledger rows.
- **Physical Stocktake & Reconciliation**:
  - Full reconciliation lifecycle: `in_progress` → `completed` (or `cancelled`).
  - Automatic system quantity & average cost snapshotting per warehouse upon initialization.
  - Physical count recording with live difference and monetary variance computation (`difference * unit_cost`).
  - Automatic balancing ledger movements (surplus creates compensatory `'in'` movement; deficit creates compensatory `'out'` movement) ensuring `StockBalance` matches the physical count 100%.
- **Frontend Operational UI**:
  - `TransfersScreen.jsx`: Transfer management table with status badges, create transfer modal (with immediate or manual dispatch), and receive modal with quantity discrepancy input.
  - `ReconciliationsScreen.jsx`: Stocktake management table, warehouse stocktake creation, physical count input sheet, and one-click completion & adjustment.
- **Dynamic-Only Rule Enforcement (G12)**:
  - 100% dictionary-driven translation keys via `frontend/src/i18n/index.js`.
  - Zero literal UI string violations verified via `scripts/check-dynamic-only.js`.

---

## 2. Database Migrations Added
- `2026_11_01_230000_create_stock_transfers_and_reconciliations_tables.php`:
  - `stock_transfers` & `stock_transfer_items`: Multi-item transfers with quantity dispatched, quantity received, discrepancy quantity, unit cost snapshot, and user audit trails.
  - `stock_reconciliations` & `stock_reconciliation_items`: Stocktake sessions with system quantity snapshots, physical count inputs, differences, and monetary variances.

---

## 3. Endpoints Added & Tested
- **Transfers**:
  - `GET /api/v1/stock-transfers`: Paginated list of transfers with status/warehouse filters.
  - `POST /api/v1/stock-transfers`: Create draft or auto-dispatched transfer.
  - `GET /api/v1/stock-transfers/{id}`: Detailed view of transfer items and status.
  - `POST /api/v1/stock-transfers/{id}/dispatch`: Dispatch transfer and reduce source stock.
  - `POST /api/v1/stock-transfers/{id}/receive`: Receive transfer items into destination warehouse.
  - `POST /api/v1/stock-transfers/{id}/cancel`: Cancel transfer and restore source stock if dispatched.
- **Reconciliations**:
  - `GET /api/v1/stock-reconciliations`: List of stocktake sessions.
  - `POST /api/v1/stock-reconciliations`: Start new stocktake session.
  - `GET /api/v1/stock-reconciliations/{id}`: Detailed count sheet with system balances.
  - `POST /api/v1/stock-reconciliations/{id}/submit-counts`: Record physical count numbers.
  - `POST /api/v1/stock-reconciliations/{id}/complete`: Complete stocktake and apply adjustments.
  - `POST /api/v1/stock-reconciliations/{id}/cancel`: Cancel stocktake.

---

## 4. Test Verification & Quality Gates
- **Backend Pest Suite**: **144 passed (609 assertions)** across 100% of test files.
- **Backend Pint Formatter**: 0 issues, fully PSR-12 compliant.
- **Frontend Vitest Suite**: **11 test suites, 43 passed (100%)**.
- **Frontend ESLint**: 0 errors.
- **Frontend Vite Build**: Production bundle created successfully (`dist/`).
- **Dynamic-Only Static Check**: 0 violations found.
