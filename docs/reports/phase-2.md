# Phase 2 report — Catalogue & inventory

## 1. Scope completed

Per `docs/MASTER_PROMPT.md` section 12, Phase 2: products, variants, units/conversions, barcodes, categories, brands, price lists + history, import/export jobs, stock balances + ledger, opening stock, adjustments, weighted-average costing, batches, serials, low-stock alerts.

- **Schema** (6 migrations, `2025_02_01_0000{01..06}_*`): catalogue lookups (categories/brands/units), products/units/variants/barcodes/recipes, price lists + history + current-price cache, stock balances/ledger/batches/serials/cost-layers, stock adjustments, import/export jobs. All ran clean against real MySQL.
- **`StockService`** — the one stock-operation pattern from section 8: lock balance row(s) in a consistent order → insert an append-only ledger row with mandatory `unit_cost` → update the balance → audit, all in one transaction. Handles batches of movements across multiple products by sorting into lock order *before* locking anything, so a multi-item operation given in either order lands in the same lock sequence (prevents deadlock, section 13.1). Weighted-average cost recalculates on every incoming movement; outgoing movements are costed at the current average (not the original purchase price).
- **A genuine NULL-uniqueness bug caught and fixed before it shipped**: `stock_balances`/`product_prices` need "one row per tenant+warehouse+product+variant?+batch?", but NULL isn't unique-constrainable in any of MySQL/MariaDB/SQLite. Added `variant_key`/`batch_key` columns (0 instead of NULL) carrying the real constraint, plus insert-race handling in `StockService` (D-0013).
- **A real concurrency proof, not a simulated one**: `StockConcurrencyTest` opens two independent raw PDO connections to a real MySQL database and proves `SELECT ... FOR UPDATE` genuinely blocks a second transaction (1-second lock-wait timeout, must time out) until the first commits (D-0014) — this is what actually backs the exit gate's "two concurrent decrements of the last unit cannot both succeed," since the default SQLite test connection cannot exercise row-level locking at all.
- **`StockAdjustmentService`** — opening stock/adjustment/damage/wastage posts through the exact same `StockService` pattern, with a mandatory reason enforced before anything is written.
- **`ProductService`** — create (SKU uniqueness per tenant) and archive (G9: never hard-deleted).
- **`PriceListService`** — a price change never overwrites the current row: it closes the previous `price_list_items` row (`effective_to`) and inserts a new one, then refreshes the `product_prices` current-value cache. Full history stays queryable.
- **`ProductImportService`** — validates every row independently (required fields, per-tenant SKU uniqueness, known unit code, duplicate-within-file detection), saves every valid row, and returns a per-row rejection report with row number + reason — nothing silently skipped.
- **Low-stock detection** — `StockService::lowStockBalances()` against a per-balance `reorder_point`.

## 2. Files changed and migrations added

6 new migrations (see `docs/data-dictionary.md` "Table index — Phase 2"). New models: `Category`, `Brand`, `Unit`, `ProductUnit`, `Product`, `ProductVariant`, `Barcode`, `ProductRecipe`, `PriceList`, `PriceListItem`, `ProductPrice`, `Batch`, `SerialNumber`, `StockBalance`, `StockLedger`, `CostLayer`, `StockAdjustment`, `StockAdjustmentItem`, `ImportJob`, `ExportJob`. New services: `StockService`, `StockAdjustmentService`, `ProductService`, `PriceListService`, `ProductImportService`. `.github/workflows/ci.yml` gained a `STOCK_LOCK_TEST_DB` env var for the new concurrency test.

## 3. Endpoints, permission codes, setting keys, translation keys added

**None new** — Phase 2 built services and their tests, not HTTP endpoints. (Permission codes `inventory.adjust`, `inventory.transfer`, `inventory.view`, `catalog.products.manage` already existed from Phase 1's seeded catalogue and are the ones `StockAdjustmentService`/`ProductService` are designed to sit behind once wrapped in controllers.) No new setting keys this phase; `inventory.allow_negative_stock` (seeded in Phase 1) is read and tested here.

## 4. Tests added, with real executed results

**Backend** (`cd backend && php artisan test`):
```
Tests:    35 passed (100 assertions)
Duration: 15.88s
```
Breakdown of the 13 new tests: `StockServiceTest` (5 — ledger-sum invariant, weighted-average costing, insufficient-stock blocking, negative-stock-allowed-via-setting, consistent lock ordering), `StockConcurrencyTest` (1 — real MySQL row-lock proof), `StockAdjustmentServiceTest` (2), `ProductServiceTest` (2), `PriceListServiceTest` (1), `ProductImportServiceTest` (1), `LowStockTest` (1).

**Backend style** (`php vendor/bin/pint --test`): `{"tool":"pint","result":"passed"}`
**Backend static analysis** (`php vendor/bin/phpstan analyse --no-progress`, level 5): `[OK] No errors` (after adding `@property string` docblocks to `StockBalance` — Larastan otherwise infers `float` from a `decimal()` migration column, which would have been a real G3 violation if the code had actually used that inferred type; it doesn't, since `StockService` is bcmath-only throughout).

**Dynamic-Only Rule check**: `Dynamic-Only Rule check: no violations found.` `EXIT:0`.

## 5. Dynamic-Only static-check results

Clean. No new role-name literals, currency/tax literals, or hardcoded UI text (no frontend code was touched this phase - see section 6).

## 6. Known issues, deviations

- D-0013: `stock_balances`/`product_prices` uniqueness carried by `variant_key`/`batch_key` sentinel columns, not the nullable real FK columns, because NULL isn't unique-constrainable.
- D-0014: The "two concurrent decrements" exit-gate proof runs against a real MySQL database via raw PDO (not the sqlite test connection), reading its target database name from `STOCK_LOCK_TEST_DB`.
- D-0015: No catalogue/inventory frontend screens (PRD-xx, INV-xx) were built this phase — Phase 2's own build list and exit gate are both backend-shaped; flagged in `docs/OPEN_QUESTIONS.md` in case that reading is wrong.
- `cost_layers`/FIFO costing: schema + model only, no service logic — weighted average is the only costing method with real behaviour, which section 1.2 explicitly permits ("FIFO...implemented if time allows, otherwise left as a clean strategy interface").
- `export_jobs`: schema only, no real export logic yet.
- Rule-engine-driven approval for adjustments ("approval if a rule matches," section 8) is not wired up — the rule engine itself has no evaluator yet (carried over from Phase 1's "schema only" rule tables). Every `StockAdjustmentService::post()` call posts immediately; `approved_by` stays unset until that lands.
- No HTTP controllers/routes were added for any Phase 2 service — they're unit/feature-tested directly against the service layer, consistent with the work order in rule 1.11 (services before API); the API surface for catalogue/inventory is deferred to whichever phase adds the screens that need it.
- The scheduled "integrity job" that re-verifies `stock_balances.quantity == SUM(stock_ledger)` system-wide (section 7) is not built — Phase 2's tests prove the invariant holds for the operations exercised, but there's no standing background check yet (that's explicitly a Phase 8 "monitoring" concern, not Phase 2).

## 7. Remaining work / next phase

Phase 2's exit gate — "Balance always equals ledger sum. Two concurrent decrements of the last unit cannot both succeed. Import reports every rejected row" — is met and demonstrated by the tests above, including a real MySQL concurrency proof rather than a simulated one.

Phase 3 per section 12: customers/groups, suppliers, ledgers, collections + allocation, loyalty basics, shifts, cash movements, expenses. Exit gate: ledger balances equal entry sums; shift expected-cash matches the formula; closed shifts are immutable. Work begins immediately following this report.
