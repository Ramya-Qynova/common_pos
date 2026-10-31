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
