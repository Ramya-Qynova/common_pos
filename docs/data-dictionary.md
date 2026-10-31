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

_(Empty at the end of Phase 0 — no business tables exist yet. Phase 1 adds the platform/tenant/identity tables; this index is updated in that phase's commit.)_
