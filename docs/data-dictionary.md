# Data dictionary — conventions

This is the cross-cutting column reference every migration must follow (`docs/MASTER_PROMPT.md` section 7). It is not a per-table column list — that lives in the migration files themselves, which are the source of truth. As each phase adds tables, add one short entry per new table to the **Table index** below (name, domain, one-line purpose, link to its migration) so this stays a usable map without duplicating the schema.

## Universal columns

| Column | Type | Applies to | Rule |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | every table | primary key |
| `tenant_id` | `BIGINT UNSIGNED`, FK → `tenants.id` | every tenant-owned table | never trusted from request; resolved from the authenticated identity only (G2) |
| `uuid` | `CHAR(36)`, unique per tenant | anything creatable offline or synced | client-generated, idempotent identity across the sync boundary |
| `row_version` | `UNSIGNED INT`, default `1` | editable master records | incremented on every update; a stale value on write returns `VERSION_CONFLICT` (G17) |
| `archived_at` | `TIMESTAMP NULL` | editable master records | soft-archive instead of hard delete (G9) |
| `created_at`, `updated_at` | `TIMESTAMP`, UTC | every table | Laravel default timestamps, always UTC (G8); display conversion happens in the API resource / client, never in storage |
| `created_by` | `BIGINT UNSIGNED`, FK → `users.id` | every tenant-owned table | audit provenance |

## Money and quantity

Every money or quantity column is `DECIMAL(18,4)` (G3). Never `FLOAT`/`DOUBLE`. The PHP layer never performs arithmetic on these as native floats — either `bcmath`/`brick/money`-style string-safe arithmetic server-side, or passed through untouched. The JS layer only ever touches them via `decimal.js`, and the wire format for money/quantity is always a decimal **string**, never a JSON number (avoids float round-tripping through `JSON.parse`).

## Append-only ledger tables

`stock_ledger`, `customer_ledger`, `supplier_ledger`, `cash_movements`, `audit_logs`, `change_log` (G5, G9):

- No `UPDATE` or `DELETE` grant for the application DB user on these tables; a migration-time trigger or privilege REVOKE enforces it (exact mechanism decided and recorded per-table in `docs/DECISIONS.md` when each table is created, since MySQL privilege semantics vs. trigger-based enforcement is an implementation choice per table).
- A correction is a new row with the opposite sign/direction, referencing the row it reverses — never an edit.
- Any "balance" (`stock_balances.quantity`, a customer's outstanding, a shift's expected cash) is a cached/derived value that a scheduled integrity job re-derives from the ledger and alerts on mismatch (section 7, "Integrity jobs").

## Tenant-scoped uniqueness

Unique keys that look global in a single-tenant mental model are **always** `(tenant_id, ...)` composite uniques here — e.g. `sales` is unique on `(tenant_id, uuid)`, not `(uuid)` alone; `invoices` is unique on `(tenant_id, series, fiscal_year, number)`. A lookup by a non-tenant-scoped key is itself a tenant-isolation bug.

## Locking order (deadlock avoidance)

Any operation that locks more than one `stock_balances` row (`SELECT ... FOR UPDATE`) must acquire them in a **consistent order**: `warehouse_id, product_id, variant_id, batch_id` (nulls first), regardless of the order items appear in the request. This is enforced in the stock service layer (Phase 2), not left to caller discipline.

## Table index

Filled in as each phase's migrations land. Format: `table_name` — domain — one line — `backend/database/migrations/<file>`.

### Phase 1

All in `backend/database/migrations/2025_01_01_*`, in this file order:

- `platform_roles`, `platform_users`, `platform_sessions` — platform — the platform guard's identity, separate from every tenant table (`2025_01_01_000001_create_platform_identity_tables.php`)
- `plans`, `module_definitions`, `plan_modules`, `plan_limits`, `templates`, `template_versions`, `languages`, `feature_flags` — platform catalogue — what a tenant is built from at onboarding (`2025_01_01_000002_create_plan_module_template_tables.php`)
- `tenants`, `tenant_subscriptions`, `tenant_modules`, `tenant_template_snapshot` — tenant/config — the tenant record and its copy-on-onboard template snapshot (`2025_01_01_000003_create_tenant_core_tables.php`)
- `platform_settings`, `plan_settings`, `tenant_settings` — tenant/config — the three resolvable layers behind `SettingsService` (`2025_01_01_000004_create_settings_tables.php`)
- `branches`, `warehouses`, `registers` — org — section 5's org model (`2025_01_01_000005_create_org_tables.php`)
- `permissions`, `roles`, `role_permissions`, `users`, `user_roles`, `user_branch_access` — identity/security — the tenant guard's RBAC (`2025_01_01_000006_create_identity_tables.php`)
- `devices`, `device_sessions`, `security_events` — identity/security — device-bound token sessions + append-only security log (`2025_01_01_000007_create_device_session_security_tables.php`)
- `audit_logs` — identity/security — append-only audit trail (`2025_01_01_000008_create_audit_logs_table.php`)
- `numbering_sequences`, `tax_rates`, `tax_groups`, `tax_group_items`, `payment_methods` — tenant/config — schema only this phase, no service logic yet (that's Phase 2/4) (`2025_01_01_000009_create_numbering_tax_payment_tables.php`)
- `lookup_types`, `lookup_values`, `custom_field_definitions`, `custom_field_index` — tenant/config — schema only this phase (`2025_01_01_000010_create_lookup_customfield_tables.php`)
- `platform_translations`, `translations` — tenant/config — seeded with a starter en/ta/hi dictionary (`2025_01_01_000011_create_translation_tables.php`); not yet wired into `GET /tenant/config` (see `docs/PROGRESS.md`)
- `document_templates`, `hardware_profiles`, `offline_policies` — tenant/config — schema only this phase (`2025_01_01_000012_create_document_hardware_offline_tables.php`)
- `discount_rules`, `approval_rules`, `alert_rules`, `loyalty_rules`, `price_rules` — tenant/config — schema only this phase, rule engine itself lands when something evaluates them (`2025_01_01_000013_create_rule_tables.php`)
- `status_definitions`, `report_definitions` — tenant/config — schema only this phase (`2025_01_01_000014_create_status_report_tables.php`)
- `approval_requests`, `approvals` — identity/security — schema only this phase, no rule triggers an approval yet (`2025_01_01_000015_create_approval_tables.php`)

Also: the Phase 0 default `users` migration was removed and replaced by the tenant-aware `users` table above (see `docs/DECISIONS.md` D-0012); `sessions`/`password_reset_tokens` (framework-only, not business data) kept at their original timestamp slot.

### Phase 2

All in `backend/database/migrations/2025_02_01_*`, in this file order:

- `categories`, `brands`, `units` — catalogue — tenant's lookup catalogues (`2025_02_01_000001_create_catalogue_lookup_tables.php`)
- `products`, `product_units`, `product_variants`, `barcodes`, `product_recipes` — catalogue — the product master and its unit/variant/barcode/kit structure (`2025_02_01_000002_create_product_tables.php`)
- `price_lists`, `price_list_items`, `product_prices` — catalogue — `price_list_items` is the append-style price history, `product_prices` the current-price lookup cache kept in sync by `PriceListService` (`2025_02_01_000003_create_price_list_tables.php`)
- `batches`, `serial_numbers`, `stock_balances`, `stock_ledger`, `cost_layers` — inventory — the balance/ledger pair `StockService` maintains, plus batch/serial tracking and the FIFO-ready (schema-only) `cost_layers` table (`2025_02_01_000004_create_stock_tables.php`)
- `stock_adjustments`, `stock_adjustment_items` — inventory — opening stock/adjustment/damage/wastage records (`2025_02_01_000005_create_stock_adjustment_tables.php`)
- `import_jobs`, `export_jobs` — operations — `ProductImportService`'s per-row accept/reject report; export is schema-only this phase (`2025_02_01_000006_create_import_export_job_tables.php`)

**`stock_balances`/`product_prices` `variant_key`/`batch_key` columns**: see `docs/DECISIONS.md` D-0013 — these, not the nullable `variant_id`/`batch_id`, carry the actual uniqueness constraint, because NULL can't be uniquely constrained across MySQL/MariaDB/SQLite.

### Phase 3

All in `backend/database/migrations/2025_03_01_*`, in this file order:

- `customer_groups`, `customers` — customers — master data (`2025_03_01_000001_create_customer_tables.php`)
- `customer_ledger`, `customer_payments`, `customer_payment_allocations` — customers — append-only ledger + collections, allocating directly against the ledger's own debit rows (D-0016) (`2025_03_01_000002_create_customer_ledger_tables.php`)
- `loyalty_accounts`, `loyalty_transactions` — customers — basic manual earn/redeem; sale-driven accrual lands in Phase 4 (`2025_03_01_000003_create_loyalty_tables.php`)
- `suppliers`, `supplier_ledger`, `supplier_payments`, `supplier_payment_allocations` — purchases — the payable-side mirror of the customer ledger pair above (`2025_03_01_000004_create_supplier_tables.php`)
- `cashier_shifts`, `cash_movements` — sales/finance — the expected-cash formula's typed-row source; see D-0018 for `open_register_marker` (`2025_03_01_000005_create_shift_cash_tables.php`)
- `expense_categories`, `expenses` — finance — `ExpenseService` optionally links an expense to an open shift, creating a matching `cash_movements` row (`2025_03_01_000006_create_expense_tables.php`)

**`cashier_shifts.open_register_marker`**: see `docs/DECISIONS.md` D-0018 — a nullable-unique column (NULL when closed, the register's own ID when open) that turns "one open shift per register" into a real, race-proof DB constraint rather than an application-level check.

### Phase 4

- `numbering_sequences` uniqueness fix — a NEW migration (`2025_04_01_000000_add_uniqueness_to_numbering_sequences.php`), not an edit to the Phase 1 file (rule 1.12) — adds `fiscal_year_key`/`device_key` bucket columns and the unique constraint that table should have had from the start (D-0020).
- `idempotency_keys` — operations — G6's key+request-hash+stored-response table (`2025_04_01_000001_create_idempotency_keys_table.php`)
- `sales`, `sale_items`, `sale_item_taxes`, `sale_discounts` — sales — server-computed only; no column here is ever filled from a client-submitted price/total (G1) (`2025_04_01_000002_create_sales_tables.php`)
- `invoices`, `sale_payments`, `payment_transactions` — sales — invoice numbers from the locked `numbering_sequences` row; `payment_transactions` tracks provider-facing status separately from the payment amount itself (`2025_04_01_000003_create_invoice_payment_tables.php`)
- `held_sales`, `held_sale_items` — sales — line selections only, never a price (resuming re-prices through the normal `SaleService::create()` path) (`2025_04_01_000004_create_held_sales_tables.php`)

All in `backend/database/migrations/2025_04_01_*`, in this file order (the uniqueness-fix migration is numbered `000000` to sort before the others, since it patches a Phase 1 table used by this phase's own `NumberingService`).

### Phase 5

All in `backend/database/migrations/2025_05_01_*`, in this file order:

- `sale_cancellations`, `sale_returns`, `sale_return_items`, `refunds` — sales — cancellation reverses a whole sale within `sales.cancel_window`; returns are a requested/inspected/completed/rejected state machine with a refund computed from the original sale_item values (`2025_05_01_000001_create_sale_return_cancellation_refund_tables.php`)
- `purchase_orders`, `purchase_order_items`, `purchases`, `purchase_items`, `purchase_returns`, `purchase_return_items` — purchases — optional PO → receiving posts stock in at received cost plus a `supplier_ledger` debit (closing D-0016/D-0022's loop); purchase returns reverse stock and post a supplier credit (`2025_05_01_000002_create_purchasing_receiving_tables.php`)
- `daily_closings` — finance — one row per branch per business date; `open`/`closed`/`reopened`, reopening gated by `finance.reopen` (`2025_05_01_000003_create_daily_closings_table.php`)

No new tables for reports — `ReportService` reads existing sales/returns/ledger/stock tables only (section 8 "Reports read committed records only"). See `docs/DECISIONS.md` D-0024–D-0028 for this phase's scope decisions (damaged-stock write-off instead of a dedicated location, no exchange/export/notification delivery, no ageing buckets, PO-line matching by product+variant).

### Phase 6

- `barcodes.type` — catalogue — new column (`fixed` default | `variable_weight`), not a new table (`2025_06_01_000001_add_type_to_barcodes_table.php`). A `variable_weight` row stores only the barcode's fixed prefix (prefix + product code); the weight-encoded suffix is decoded per-scan by `VariableWeightBarcodeService`, never stored.

No other new tables this phase — the grocery template, hardware profiles, and the daily-rate bulk update all run on schema that already existed (`template_versions`/`tenant_template_snapshot` since Phase 1, `hardware_profiles` since Phase 1, `price_list_items`/`product_prices` since Phase 2). See `docs/DECISIONS.md` D-0029–D-0033 for this phase's scope decisions (template-application mechanism lives in `TemplateUpdateService`, not onboarding itself; no EAN-13 check-digit validation; hardware adapters are server-config-only, frontend device code deferred; permission codes reused rather than added).
