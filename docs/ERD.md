# ERD — Advanced Universal POS

Domain-level entity relationship diagram. Tables are introduced progressively per `docs/MASTER_PROMPT.md` section 7 and the phase table in section 12 — this is the structural map, not a final schema; each phase's migrations are the source of truth once written (see `docs/reports/phase-N.md` for what actually landed).

Every box below is implicitly scoped by `tenant_id` unless marked **(platform)**.

```mermaid
erDiagram
    TENANTS ||--o{ BRANCHES : has
    BRANCHES ||--o{ WAREHOUSES : has
    BRANCHES ||--o{ REGISTERS : has
    WAREHOUSES ||--o{ REGISTERS : "default sales warehouse of"

    TENANTS ||--o{ USERS : employs
    USERS ||--o{ USER_ROLES : assigned
    ROLES ||--o{ USER_ROLES : grants
    ROLES ||--o{ ROLE_PERMISSIONS : grants
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : granted_via
    USERS ||--o{ USER_BRANCH_ACCESS : scoped_to
    USERS ||--o{ DEVICES : registers

    TENANTS ||--o{ TENANT_SETTINGS : configures
    TENANTS ||--|| TENANT_TEMPLATE_SNAPSHOT : "copied from"
    TEMPLATES ||--o{ TENANT_TEMPLATE_SNAPSHOT : "snapshot of (platform)"
    TENANTS ||--o{ TENANT_MODULES : enables

    PRODUCTS ||--o{ PRODUCT_VARIANTS : has
    PRODUCTS }o--o{ CATEGORIES : "classified under"
    PRODUCTS ||--o{ BARCODES : identified_by
    PRODUCTS ||--o{ PRODUCT_PRICES : priced_in
    PRICE_LISTS ||--o{ PRICE_LIST_ITEMS : contains
    PRODUCT_PRICES }o--|| PRICE_LISTS : "belongs to"

    WAREHOUSES ||--o{ STOCK_BALANCES : holds
    PRODUCTS ||--o{ STOCK_BALANCES : "balance of"
    STOCK_BALANCES ||--o{ STOCK_LEDGER : "derived from (append-only)"
    PRODUCTS ||--o{ BATCHES : "tracked in"
    PRODUCTS ||--o{ SERIAL_NUMBERS : "tracked in"

    REGISTERS ||--o{ CASHIER_SHIFTS : opens
    CASHIER_SHIFTS ||--o{ SALES : "recorded during"
    SALES ||--o{ SALE_ITEMS : contains
    SALE_ITEMS ||--o{ SALE_ITEM_TAXES : taxed_by
    SALES ||--o{ SALE_DISCOUNTS : discounted_by
    SALES ||--|| INVOICES : numbered_as
    SALES ||--o{ SALE_PAYMENTS : paid_by
    SALE_PAYMENTS ||--o{ PAYMENT_TRANSACTIONS : settled_via
    SALES ||--o{ SALE_RETURNS : "returned (partial)"
    SALE_RETURNS ||--o{ REFUNDS : refunded_via
    STOCK_LEDGER }o--|| SALE_ITEMS : "moved by"

    CUSTOMERS ||--o{ SALES : "billed to"
    CUSTOMERS ||--o{ CUSTOMER_LEDGER : "balance derived from"
    CUSTOMERS ||--o{ LOYALTY_ACCOUNTS : earns

    SUPPLIERS ||--o{ PURCHASE_ORDERS : "ordered from"
    PURCHASE_ORDERS ||--o{ PURCHASES : received_as
    PURCHASES ||--o{ STOCK_LEDGER : "moved by"
    SUPPLIERS ||--o{ SUPPLIER_LEDGER : "balance derived from"

    DEVICES ||--o{ SYNC_OPERATIONS : submits
    SYNC_OPERATIONS ||--o{ SYNC_CONFLICTS : "may raise"
    CHANGE_LOG ||--o{ SYNC_OPERATIONS : "drives pull for"
```

## Notes

- **Append-only ledgers** (`stock_ledger`, `customer_ledger`, `supplier_ledger`, `cash_movements`, `audit_logs`, `change_log`) have no update/delete edge into themselves on this diagram by design (G5, G9) — balances are always a derived `SUM()`, never a stored, independently-editable column.
- **Platform vs tenant**: `TENANTS`, `TEMPLATES`, `MODULE_DEFINITIONS`, `PLANS`, `PLATFORM_USERS` live outside any `tenant_id` scope and use a separate guard (section 5, "Identity"). No edge from a platform table reaches into tenant business data directly — only through the one-time, copy-on-onboarding `TENANT_TEMPLATE_SNAPSHOT` edge.
- **Held bills** (`held_sales`) are a separate table from `sales`, not a status on `sales` (section 7) — omitted above for brevity, same shape as `SALES` → `SALE_ITEMS` until resumed.
- The full column-level shape of each table is defined by its migration, not by this document; see `docs/data-dictionary.md` for the cross-cutting column conventions every table follows.
