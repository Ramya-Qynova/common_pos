# ADVANCED UNIVERSAL POS: AUTONOMOUS MASTER BUILD PROMPT (v3.0)

Paste this entire document to the agent as the first and only instruction. Save it in the repository as `docs/MASTER_PROMPT.md` and treat it as permanent context.

---

## 0. YOUR ROLE AND MISSION

You are the principal engineer, architect, QA lead and release manager of this project. Build the complete **Advanced Universal POS**, a multi-tenant, multi-branch, online + offline point-of-sale for any type of shop, from an empty repository to a release-ready system, **without waiting for human approval at any point**.

**Stack (fixed):** React + JavaScript (JSX, no TypeScript) PWA with Vite · Laravel (latest stable that runs on the installed PHP; PHP 8.5.x target, 8.3 minimum) REST API at `/api/v1` · MySQL 8 InnoDB · Redis in production (database drivers locally) · Laravel queue workers · IndexedDB via Dexie for offline.
**Dev machine:** Windows, PowerShell, PHP CLI NTS, Node 24, Git. Write all commands for PowerShell. Use forward slashes in code and config.

**Core promise:** every rule, label, tax, menu, number format and policy is data in the database and editable without a code deployment. Nothing business-related is static.

If `docs/MASTER_SPEC.md` (Master Build Specification v2.0) exists in the repo, treat it as the detailed reference. **This prompt wins on any conflict.** This prompt is complete enough to build from even if that file is absent.

---

## 1. AUTONOMY CHARTER (highest priority; overrides every "stop and ask" rule in earlier documents)

1. **Never ask for approval. Never pause to ask a question.** Do not end a turn with "shall I continue?" or "please confirm". Continue until every phase is complete and the final report is written.
2. **Decide like an owner.** When the specification is silent, ambiguous, or you see a clearly better approach, make the decision yourself, implement it, and record it in `docs/DECISIONS.md`. `OPEN_QUESTIONS.md` is no longer a stopping point. It is only a list of low-risk items for a human to review later.
3. **Decision hierarchy** (higher wins):
   1. Security and tenant isolation
   2. Correctness of money, stock and ledgers
   3. Global rules G1–G17 and the Dynamic-Only Rule (Sections 3–4)
   4. Offline reliability: a completed sale is never lost
   5. Explicit text of this prompt
   6. Simplicity and maintainability
   7. Performance
4. **When in doubt, choose** the option that is most conservative with money/stock/security, stores the choice as data (a setting) rather than code, is reversible, and is the simplest thing that satisfies the requirement.
5. **You may improve on this prompt** if you find a bug, gap or contradiction in it, provided you do not violate priorities 1–4. Log it as `Type: Deviation` with the reason. Never silently skip a requirement. Never add large features that are not listed. Small supporting features needed to make a listed feature work are fine.
6. **Things you cannot get** (payment-provider credentials, hardware you cannot test, hosting accounts, third-party API keys): build the real adapter interface and a fully working, tested implementation against the interface. Use a configurable generic provider with HMAC signature verification, plus the manual and cash adapters. Hardware that cannot be tested (thermal printer, scale) gets the adapter, a simulator used **only in tests**, and a documented manual test checklist. Log it and continue.
7. **Environment problems:** if a command or package fails, try at least three different fixes (version pin, alternative package, alternative approach). Then log the workaround and continue. Never downgrade PHP silently; record any pin in `DECISIONS.md`.
8. **Self-gating replaces human gates.** A phase is complete only when its exit-gate tests are **actually executed and pass**. If a test fails, fix the code (not the test) and re-run. Allow yourself many fix cycles. If an exit-gate item truly cannot pass after serious effort, record it as a Known Issue with severity and a workaround. Continue to the next phase only if the failing item does not block it. Finish with an honest status.
9. **Honesty rule:** never claim a test passed, a feature works, or a check ran unless you ran it and saw the result. Paste real output summaries (counts, failures) into the phase report. Never fabricate results.
10. **Resumability:** you may run out of context or be restarted. Keep `docs/PROGRESS.md` updated after every meaningful step with: current phase, done list, next 5 steps, how to run tests, and known issues. On start, always read `docs/PROGRESS.md` and `docs/DECISIONS.md` first and continue from there. Commit to Git after every working step (one branch per phase, merged into `main` only after the phase self-gate passes, tag `phase-N-complete`).
11. **Work order inside a phase:** migrations → tests for money/stock/tenancy/permission/sync rules (write the test first) → services → API → UI → static checks → phase report.
12. **Never edit an earlier phase's migration.** Add a new migration with rollback notes.
13. **No mock data in production code. No hardcoded business values.** Test data lives only in factories and fixtures under `tests/`. Seeders insert real default data rows (permissions, settings, templates, translations).

### 1.1 DECISIONS.md format (append-only)

```
## D-0001 | Phase N | Type: Assumption | Deviation | Improvement | Workaround
Context: ...
Options considered: A, B, C
Decision: ...
Reason: ...
Reversibility / how to change: (setting key, migration, etc.)
```

### 1.2 Pre-resolved decisions (do not ask; already decided)

| Topic | Decision |
|---|---|
| First template | Vegetable / Grocery on the generic template engine |
| Languages to ship | English (`en`), Tamil (`ta`), Hindi (`hi`) as seeded translation rows; more via admin. Machine-draft translations are acceptable. Flag `needs_review` in DECISIONS |
| Regional preset | India preset as seed data (INR, GST with CGST/SGST/IGST, HSN, financial-year numbering, UPI). Fully editable. Other regions are other seed presets |
| Payment providers | Adapters: cash, credit on account, manual UPI/bank confirm, and one generic provider adapter (create intent, status poll, signed webhook, refund). Real providers plug in later via the interface |
| Hardware | Browser print (default), ESC/POS thermal via WebUSB/WebSerial and local print-service adapter, keyboard-wedge scanner, WebSerial scale. Configured in `hardware_profiles` |
| Costing | Weighted average in Release 1. FIFO via `cost_layers` is implemented if time allows, otherwise left as a clean strategy interface |
| Hosting | Docker Compose (nginx, php-fpm, mysql, redis, queue worker, scheduler), HTTPS-ready, daily dump + binary-log PITR script, runbook. RPO 15 min, RTO 4 h |
| Negative stock | Blocked by default. Offline sales whose goods already left are accepted and flagged |
| Login | Shop code + username by default. Phone/e-mail can be enabled per tenant |
| Scale | Shared tables with `tenant_id`, indexed by tenant/branch/date, partition-ready |

---

## 2. SCOPE AND RELEASE

Build **Release 1 (MVP)** completely (Phases 0–8). Then, if capacity remains, build Release 2 items in this order: stock transfers and reconciliation, FIFO, Textile/Medical/Wholesale templates, two-factor auth, advanced reports, integrations (webhooks, accounting CSV export). Release 3 is out of scope except for a documented roadmap. Do not stop after Release 1 if you still have capacity.

---

## 3. GLOBAL ENGINEERING RULES (apply to every line of code)

- **G1** Backend is authoritative for prices, tax, discounts, totals, stock, invoice numbers, permissions. Client values are previews only.
- **G2** Tenant ID comes only from the authenticated user or registered device, never from body, URL or headers.
- **G3** Money and quantity are `DECIMAL(18,4)`. No floats anywhere. The client uses a decimal library (decimal.js) and sends money/quantity as strings. Display precision and rounding mode come from settings.
- **G4** Every critical write runs in one DB transaction, with its audit entry written in the same transaction.
- **G5** Ledgers (stock, customer, supplier, cash) are append-only (trigger or privilege blocks UPDATE/DELETE). Corrections are reversing entries. Balances are derived and verifiable against the ledger.
- **G6** Every mutating POS and sync request carries an idempotency key or operation UUID, unique on `(tenant_id, key)`, with a request hash. Same key + same payload replays the original response (HTTP 200, `Idempotent-Replay: true`). Same key + different payload returns `IDEMPOTENCY_KEY_REUSED`.
- **G7** Every status is a state machine with an allowed-transitions definition. Invalid transitions are rejected. Each transition is audited. One entity's status never silently changes another's.
- **G8** Timestamps stored in UTC. Display uses tenant/branch timezone. Business-day cutoff is a setting.
- **G9** Records with history are never hard-deleted. Archive or cancel.
- **G10** All lists use server-side pagination, filtering and sorting, with bounded date ranges and indexed filters.
- **G11** Authorization uses permission codes only, never role names.
- **G12** All user-visible text is a translation key. No literal UI text in code.
- **G13** Every endpoint and business rule has automated tests.
- **G14** Files, exports, reports and sync are tenant-scoped. A foreign-tenant ID returns 404.
- **G15** Secrets only in environment config or an encrypted store, never returned by any API or logged.
- **G16** Schema changes are versioned migrations with rollback notes. Seeders insert data rows, not code constants.
- **G17** Editable master records carry `row_version`. A stale version returns `VERSION_CONFLICT`.

---

## 4. THE DYNAMIC-ONLY RULE (nothing static)

If a business owner might ever want to change a value, label, list, rule or behaviour, store it as data, editable from the app. Never a literal in code.

**Stored as data:** enabled modules and menu · roles and role→permission mapping · tax rates/groups/components and inclusive/exclusive mode · currency, decimals, rounding · invoice prefix/format/reset period · payment methods and behaviour · units and conversions · custom fields · lookup values · UI text and languages · stock policy · discount limits and approval thresholds · loyalty rules · alert thresholds · receipt/invoice layouts · hardware profiles · offline policies per device and operation · plan limits and modules · feature flags · report parameters · status labels and colours.

**Forbidden in application code:** tax percentages, currency symbols/codes, decimal counts or date formats as literals · role names in conditions · business-type checks such as `template == grocery` (use module flags and custom fields) · fixed payment methods, units, categories, prefixes, branch/tenant/user IDs · hardcoded product/customer/report data · magic numbers for limits, timeouts, thresholds (read from settings with a seeded default) · literal UI text.

**Allowed constants:** state-machine status codes, permission codes, error codes, HTTP codes, and the schema of setting keys.

**Settings resolution:** `SettingsService.get(key, context)` resolves branch override → tenant value → plan default → platform default. Cached with version-based invalidation, so a change applies on the next request. The offline client receives resolved settings in its snapshot.

**Enforcement you must build in Phase 0:** a CI/static check (PowerShell + PHP/Node script) that fails the build on role-name comparisons, currency/tax literals, and text literals in UI components. Prove it fails on a deliberate violation. Add behaviour tests per setting group (change the setting → behaviour changes with no code change; disable a module → its menu and API vanish).

---

## 5. ARCHITECTURE

**Request pipeline (strict order):** route + HTTPS + rate limit → authentication (token + device) → tenant & branch resolution (from identity only) → tenant status & subscription check → module-enabled check → authorization (permission code + branch access) → validation (Form Request, may read settings) → thin controller → service layer (all business logic) → tenant-scoped repository/model → DB transaction incl. audit → API Resource. Authorization runs before validation.

**Identity:** tenant users and platform users are separate tables with separate guards. Tenant global scope never applies to platform users, and platform users cannot read tenant business data. Support access is an explicit, time-limited, audited grant. Access token short-lived, refresh token rotating and stored hashed. Offline grace via `auth.offline_grace_hours`. Passwords Argon2id. Tenant lifecycle: draft → setup → active → suspended → closed (owner may log in during setup).

**Org model:** tenant → branches → warehouses (each branch has a default sales warehouse) → registers (a register has its own cash drawer; a shift belongs to a register).

**Standard response:** success `{ success, data, meta:{page,per_page,total} }`; error `{ success:false, error:{ code, message_key, message, fields, details } }`.
**Error codes:** VALIDATION_FAILED 422 · UNAUTHENTICATED 401 · FORBIDDEN 403 · NOT_FOUND 404 · INSUFFICIENT_STOCK 409 · IDEMPOTENCY_KEY_REUSED 422 · CONFLICT 409 · VERSION_CONFLICT 409 · APPROVAL_REQUIRED 403 · SHIFT_REQUIRED 409 · CREDIT_LIMIT_EXCEEDED 409 · PAYMENT_FAILED 402 · MODULE_DISABLED 403 · TENANT_SUSPENDED 403 · SUBSCRIPTION_EXPIRED 402 · RATE_LIMITED 429 · SERVER_ERROR 500 (with correlation ID).

**Frontend:** React + Vite, vite-plugin-pwa, React Router, TanStack Query, Dexie, React Hook Form + Zod (validate every API response and every outbox operation at runtime), decimal.js. JavaScript only, with JSDoc and `checkJs` for the pricing engine, API client and sync layer. Dates as ISO-8601 UTC strings. Money/quantity as decimal strings.
**Backend tests:** Pest or PHPUnit, Larastan, Pint. **Frontend tests:** Vitest, React Testing Library, Playwright (including offline/reconnect). **Quality:** ESLint, Prettier.

**Repository layout**
```
pos/
  docs/        MASTER_PROMPT.md, DECISIONS.md, PROGRESS.md, OPEN_QUESTIONS.md,
               openapi.yaml, ERD, data-dictionary, threat-model, sync-design,
               TRACEABILITY.md, RUNBOOK.md
  backend/     Laravel app
  frontend/    React PWA
  shared/golden-vectors/   JSON pricing/tax/rounding cases (used by PHP and JS)
  scripts/     check-env.ps1, setup, reset
```
First task: `scripts/check-env.ps1` verifying PHP version and extensions (pdo_mysql, mbstring, openssl, fileinfo, intl, curl, zip, bcmath, sodium, gd, OPcache), Composer, MySQL connection and charset, Node 24 and npm, with clear pass/fail output. Use `.gitattributes` for line endings. CORS origins from env. Local: API `http://localhost:8000`, React `http://localhost:5173`.

---

## 6. DYNAMIC CONFIGURATION ENGINE

One core engine (sales, stock, ledgers, shifts, sync). A business type is configuration, not code, built from four data blocks: **modules, templates, custom fields, rules.**

**Modules** (`module_definitions`, each with code, dependencies, permissions, menu entries): core, catalog, inventory, pos, sales_mgmt, purchases, customers, finance, reports, offline, batch_expiry, serial_tracking, variants, weighing, restaurant, price_lists_advanced, notifications, integrations. A disabled module has no menu entry and its API returns `MODULE_DISABLED`.

**Templates** are versioned data bundles (modules, custom fields, default roles/permission maps, default settings, units, categories, lookups, receipt layouts, UI hints). At onboarding the template is **copied** into the tenant (`tenant_template_snapshot`). Later template updates never change a live tenant silently. The owner sees a change preview and chooses to apply. Presets: General Retail, **Vegetable/Grocery (first)**, Textile, Medical, Electronics, Wholesale, Restaurant. Add new business types by adding a template row, never code.

**Custom fields:** `custom_field_definitions` per entity (product, variant, customer, supplier, sale, sale line, purchase line, expense). Types: text, number, decimal, date, boolean, select, multi_select, file. Includes required/default/validation JSON, `visible_when`, searchable (indexed side table `custom_field_index`), show_in_list, show_on_receipt. Values stored in a JSON `custom_fields` column, validated by the backend. The UI renders forms from the definitions.

**Rule engine:** rows with a JSON condition and an action: `discount_rules`, `approval_rules`, `alert_rules`, `loyalty_rules`, `price_rules`. Condition shape `{ all:[{field,op,value}], any:[...] }` with ops `equals, not_equals, gt, gte, lt, lte, in, not_in, between, contains`. The engine never executes arbitrary code.

**Settings catalogue (seed all as data, typed with allowed-value schema and scope):**
`auth.login_identifiers, auth.access_token_minutes, auth.offline_grace_hours, auth.max_failed_logins, locale.currency_code, locale.currency_decimals, locale.rounding_mode, locale.cash_rounding_step, locale.timezone, locale.business_day_cutoff, locale.languages, tax.price_mode, tax.require_hsn, inventory.allow_negative_stock, inventory.costing_method, inventory.batch_pick_order, inventory.expiry_block_days, sales.shift_required, sales.discount_requires_reason, sales.allow_credit_sales, sales.hold_expiry_hours, sales.return_window_days, sales.cancel_window, returns.require_inspection, shift.cash_difference_tolerance, shift.close_requires_empty_sync_queue, numbering.invoice_format, numbering.reset_period, numbering.offline_mode, offline.enabled, offline.allowed_operations, offline.max_pending_operations, offline.snapshot_refresh_minutes, offline.snapshot_max_age_hours, offline.price_mismatch_policy, notifications.receipt_channels, security.session_idle_minutes, security.pin_for_approval, reports.max_range_days`, plus any additional keys you need (register them in the code-generated catalogue and in DECISIONS).

---

## 7. DATA MODEL

**Conventions:** every tenant-owned table has `tenant_id, created_at, updated_at, created_by`. Editable masters add `row_version, archived_at`. Anything created offline or synced has a client-generated `uuid`, unique per tenant. Unique keys are tenant-scoped. Use FKs, NOT NULL and CHECK constraints wherever the rule is known. Held bills live in `held_sales`, never in `sales`.

**Tables by domain** (create all needed; names as below):
- Platform: platform_users, platform_roles, plans, plan_modules, plan_limits, module_definitions, templates, template_versions, feature_flags, languages
- Tenant/config: tenants, tenant_subscriptions, tenant_modules, tenant_template_snapshot, tenant_settings, numbering_sequences, tax_rates, tax_groups, tax_group_items, payment_methods, lookup_types, lookup_values, custom_field_definitions, custom_field_index, translations, document_templates, hardware_profiles, offline_policies, discount_rules, approval_rules, alert_rules, loyalty_rules, price_rules, status_definitions, report_definitions
- Org: branches, warehouses, registers
- Identity/security: users, roles, permissions, role_permissions, user_roles, user_branch_access, sessions, devices, device_sessions, security_events, approval_requests, approvals, audit_logs
- Catalogue: products, product_variants, categories, brands, units, product_units, barcodes, price_lists, price_list_items, product_prices, product_recipes
- Inventory: stock_balances, stock_ledger, stock_adjustments(+items), stock_reconciliations(+items), stock_transfers(+items), batches, serial_numbers, cost_layers
- Sales: cashier_shifts, sales, sale_items, sale_item_taxes, sale_discounts, invoices, sale_payments, payment_transactions, held_sales(+items), sale_cancellations, sale_returns(+items), refunds, exchanges, quotations
- Purchases: suppliers, purchase_orders(+items), purchases(+items), purchase_returns(+items), supplier_ledger, supplier_payments, supplier_payment_allocations
- Customers: customers, customer_groups, customer_ledger, customer_payments, customer_payment_allocations, loyalty_accounts, loyalty_transactions
- Finance: expenses, expense_categories, cash_movements, daily_closings
- Restaurant (Release 2/3): dining_tables, orders, order_items, kitchen_tickets
- Operations: idempotency_keys, sync_operations, sync_conflicts, change_log, import_jobs, export_jobs, notifications, webhooks, webhook_deliveries, api_keys, background_job_runs

**Critical table rules**
- `stock_balances`: unique on `(tenant, warehouse, product, variant?, batch?)`. Upsert on first movement. Lock rows `SELECT … FOR UPDATE` in a **consistent order** (warehouse, product, variant, batch) to prevent deadlocks.
- `stock_ledger`: append-only; columns include direction, quantity, **unit_cost (mandatory)**, source_type/id/line_id, balance_after, user, occurred_at, uuid.
- `sales`: unique `(tenant, uuid)`, status `completed|cancelled`, `payment_status` derived, `is_test`, origin online/offline, device_id, client_created_at, server_created_at.
- `sale_items`: stores `cost_snapshot` at sale time (returns and profit never use current cost) and `price_source`.
- `invoices`: unique `(tenant, series, fiscal_year, number)`, generated under a locked sequence row. Supports provisional numbers.
- `payment_transactions`: status pending/succeeded/failed/reversed. Provider events idempotent on `provider_reference`.
- `cashier_shifts`: one open shift per register. Closed rows immutable.
- `audit_logs` and `change_log`: append-only. `change_log` drives incremental sync by version cursor.

**Integrity jobs (scheduled):** every stock balance equals the sum of its ledger. Every customer/supplier balance equals the sum of its ledger. Alert on any mismatch. `is_test` sales excluded from reports, valuation and numbering after go-live cleanup.

---

## 8. WORKFLOWS

**State machines:** Tenant (draft, setup, active, suspended, closed) · Sale (completed, cancelled) · Held sale (held, resumed, expired, deleted) · Payment status (pending, partial, paid, failed, partially_refunded, refunded) · Payment transaction (pending, succeeded, failed, reversed) · Return (requested, inspected, completed, rejected; stock moves at completed; skip inspection if `returns.require_inspection` is false) · Refund (pending, completed, failed) · Purchase order (draft, ordered, partially_received, received, cancelled) · Stock transfer (draft, in_transit, partially_received, received, cancelled) · Shift (open, closed) · Business day (open, closed, reopened; reopen needs `finance.reopen`) · Approval (pending, approved, rejected, expired) · Sync operation (pending, accepted, rejected, conflict, resolved).

**Onboarding:** platform admin creates the tenant (draft) with plan, template and owner in **one transaction** (tenant, subscription, modules, owner, default roles/permissions, first branch/warehouse/register, default settings, audit) → status setup. The owner completes a resumable wizard (ON-01..10). Go-live requires regional settings, tax setup, ≥1 payment method, ≥1 product with stock, and one verified test sale. The test sale uses `is_test=true` and a separate TEST numbering series. At go-live a job reverses test stock/ledger entries with reversing entries and excludes test records from reports. Then the tenant becomes active.

**Login:** rate limit per IP and account → find user via configured identifiers → verify hash → check user active, tenant in setup/active, subscription valid or in grace → force-change-password token (AUTH-04) or pending 2FA token if applicable → unregistered offline-capable device gets a token valid only for device registration → issue access + rotating refresh token, record security event → client calls `GET /tenant/config` and receives only what this user may see (modules/menu, branches, settings, tax rules, units, payment methods, permissions, translations, document templates, hardware profiles, offline policy). Generic message on bad credentials. Temporary lockout after repeated failures. Logout revokes tokens and warns about unsynced operations.

**Master data:** validate against settings, custom field definitions and tenant-scoped uniqueness. Price/tax changes keep history and audit. Products with transactions are archived, never deleted. Bulk import is a background job: validate each row, save valid rows, return a downloadable error report. Nothing silently skipped.

**Stock operations (one pattern):** validate → lock balance rows in consistent order → insert ledger row (with unit_cost) → update balance → audit, all in one transaction. Opening stock/adjustment/damage/wastage need `inventory.adjust` plus a mandatory reason, and approval if a rule matches. Reconciliation freezes expected quantity at count start, approver posts variances as adjustment ledger entries. Transfers: send = out-ledger at source + in-transit; receive = in-ledger at destination; shortages are adjustments with reasons. Weighted-average cost recalculates under the balance lock. Costing method cannot change after first transaction except via controlled revaluation job.

**Purchasing:** optional PO → receiving is one transaction (validate supplier/items/cost/tax, create purchase, lock, ledger rows at received cost, batch/serial records where modules require, update PO status, compute payable; paid → supplier payment, unpaid → supplier ledger payable). Purchase returns reverse stock at original cost and create supplier credit.

**POS sale (online), backend in this order:**
1. Insert idempotency key (`in_progress`). Replay if same hash and done. Reject if hash differs.
2. Authenticate, authorize (`sales.create`, branch access, module `pos`), check open shift if `sales.shift_required`.
3. Load products, variants, prices, tax groups, rules from DB. Ignore all client prices and totals.
4. Calculate line prices (price rules/lists), discounts (permission `sales.discount` + discount_rules, or create an approval request), tax, rounding, grand total.
5. Validate payments: sum of lines equals grand total after rounding. Only methods that allow overpayment (cash) may exceed it (record change). Enforce credit limit on credit lines.
6. Verify non-instant payment methods.
7. Begin transaction.
8. Lock stock balances in consistent order. Apply stock policy. `INSUFFICIENT_STOCK` if blocked.
9. Generate invoice number under a locked sequence row.
10. Insert sales, sale_items (with cost_snapshot), sale_item_taxes, sale_discounts, invoices, sale_payments, payment_transactions.
11. Insert stock ledger row per line and update balances (batches in `inventory.batch_pick_order`).
12. Insert customer ledger and loyalty entries where applicable.
13. Audit, store idempotency response, commit.
14. Return invoice and totals. Fire notifications via queue after commit.
A failure in steps 7–13 rolls back everything. Retrying with the same key returns the original result.

**Payment confirmation modes:** `instant` (cash, credit), `manual_confirm` (permission required, optional reference, e.g. static UPI QR), `provider_confirm` (create intent via `POST /payments/intents`, dynamic QR/terminal, status via signed webhook or polling, then submit the sale with succeeded intent references; failed/expired intents never create a sale; webhooks idempotent on `provider_reference`).

**Cancel / Return / Refund / Exchange:** Cancel reverses the whole sale in one transaction (reversing stock, payment, customer-ledger entries; invoice number stays in series marked cancelled), allowed only within `sales.cancel_window` with permission and approval. Return validates quantity ≤ sold − already returned, records reason and condition, resellable items re-enter stock at original `cost_snapshot`, damaged go to a damaged-stock location. Refund amount is computed from **original sale values** including proportional discount and tax. Method per refund rules (original method default). Cash refunds need an open shift. Provider refunds stay pending until confirmed. Generate a credit note. Exchange = return + new sale in one transaction, with the difference settled by payment or refund.

**Approvals:** a matching rule returns `APPROVAL_REQUIRED` and creates an `approval_request`. An approver (by permission, PIN or remote) approves. The action then executes **exactly once**, with the approver in the audit log.

**Ledgers and collections:** balances are derived, never typed in. Collections create payment transaction + ledger entry and may be allocated to invoices (over-allocation rejected). Advances stored as unallocated credit.

**Shifts and cash:** one open shift per register. Expected cash = opening cash + cash received (net of change) − cash refunds − cash expenses + cash in − cash out. Close is blocked until the device's sync queue is empty (when the setting is true). Difference above tolerance creates approval + alert. Closed shifts are immutable.

**Reports (defined once):** Gross sales (line amounts before discount, excl. tax, completed sales) · Net sales (gross − discounts − returns, excl. tax) · Tax collected (sale_item_taxes of completed sales − tax on returns) · COGS (cost_snapshot × qty − same for resellable returns) · Gross profit (net sales − COGS) · Stock valuation (qty × cost under costing method) · Outstanding (ledger sums with ageing buckets from settings) · Expected cash (as above). Reports read committed records only, scoped by permission, tenant and branch. Large reports run as `export_jobs` visible only to the requester. Day boundaries use `locale.business_day_cutoff` and branch timezone.

**End of day:** all devices sync → all shifts closed and reconciled → finance daily close with approval (day becomes closed; later corrections are dated today via reversing entries; touching a closed day needs `finance.reopen`) → daily reports → scheduled backups and periodic restore tests (system jobs).

**Subscription expiry:** grace period (setting) with a banner, then read-only (reports and exports still work, new billing blocked). Offline sales already completed are **always** accepted on sync.

---

## 9. OFFLINE-FIRST DESIGN

**Principles:** billing never depends on the network. Every offline operation is an immutable UUID record in a local outbox, removed only after server acknowledgement. A sale completed offline is a fact; sync records it and flags differences for review, and never discards or silently reverses it. Server is the single source of truth for shared totals and balances.

**Build the POS client on the outbox abstraction from the start**, so online mode is the same pipeline flushed immediately.

**IndexedDB stores:** snapshot_products, snapshot_barcodes, snapshot_prices, snapshot_price_lists, snapshot_customers, snapshot_config (resolved settings, tax groups, payment methods, units, translations, document templates, permissions, offline policy), outbox, held_bills, local_receipts, sync_state. Encrypt sensitive fields with a device key (Web Crypto). Auto-lock on inactivity with PIN unlock. On device revocation, wipe local data at next contact.

**Snapshot and pull:** the first login downloads a full snapshot for the branch/warehouse. `GET /sync/pull?since=version` returns authorized changes from `change_log` in version order. If the snapshot is older than `offline.snapshot_max_age_hours`, show a stale warning. Policy decides whether billing continues.

**One pricing engine, tested twice:** pricing, discount, tax and rounding are a pure function with no network access. Write a JavaScript implementation (client ES module) and a PHP implementation (server). A shared `shared/golden-vectors/*.json` runs in **both** test suites. A change that makes them disagree fails the build. The server result is authoritative.

**Operation envelope:** `{ operation_uuid, type, device_id, user_id, client_created_at, branch_id, register_id, snapshot_version, numbering:{series,number}, payload, hash }`. Operations from a device are sent in order. An operation referencing another offline record is sent after it and uses its uuid.

**Offline numbering (`numbering.offline_mode`):** `device_series` (default: each registered device owns a series with its own prefix and counter, the printed number is final and never renumbered, stored in `numbering_sequences` with `device_id`) or `provisional_then_final`.

**Sync push (`POST /sync/push`, batch, each operation in its own transaction):**
authenticate user and device → processed UUID returns the earlier result → recalculate with the server engine and compare totals → apply:
- totals match → accepted
- prices differ → per `offline.price_mismatch_policy` (`accept_device_price` default, or `accept_server_price`), difference recorded
- stock insufficient → accepted with `STOCK_SHORTFALL` conflict (negative stock allowed for this case)
- item archived/price list changed → accepted with `DATA_CHANGED` flag
- invalid structure, tampered hash, or type not allowed offline → rejected with reason, kept visible
- device revoked before the op was created → rejected for review. Ops created before revocation → accepted
- tenant suspended or expired → stored and held for review, never lost
Outcomes go to `sync_operations` and conflicts to `sync_conflicts`. The client marks an item synced only after acknowledgement. Rejected/conflicting items stay visible until resolved.

**Offline operation policy defaults (rows in `offline_policies`, editable per device/tenant):** sale (cash, credit within snapshot limit, manual-confirm) allowed · hold/resume allowed · quick-add customer allowed (merge by phone on sync) · price check allowed · cash in/out allowed · shift open allowed · shift close needs empty queue · refund/return/cancel online only · stock transfer/adjustment/purchase receiving online only · user/role/price/tax/settings changes online only always.

**Reliability:** exponential backoff with jitter. Outbox survives restarts and crashes. Server stores both client timestamp and receipt time and flags clock skew beyond a setting. "Sync Now" button plus background sync. Connection badge (Online / Offline / Syncing / Sync Error) with pending count on every screen.

---

## 10. API

Base `/api/v1`, JSON only. Generate `docs/openapi.yaml` from code. Contract tests fail if responses differ. Headers: `Authorization`, `Idempotency-Key` (required on `POST /sales`, `POST /payments/intents`, all sync pushes), `Accept-Language`, `X-Correlation-ID` (returned always). Lists: `page, per_page (max from settings), sort, filter[field], search, date_from, date_to`. Masters update with `row_version`. Branch comes from the user's selected branch validated against `user_branch_access`.

**Endpoint groups to implement:** Auth & devices (login, refresh, logout, forgot/reset/change password, verify-otp, me, devices register/list/revoke) · Configuration (`/tenant/config`, settings, tax-rates, tax-groups, payment-methods, lookups, custom-fields, rules/{type}, translations, document-templates, numbering-series, registers, branches, warehouses) · Platform (separate guard: dashboard, tenants, status, templates, modules, plans, health, audit, users, sync-failures) · Setup wizard · Catalogue (products, categories, brands, units, price-lists, lookup, import/export, labels, import-jobs) · Inventory (balances, ledger, batches, serials, low-stock, valuation, opening-stock, adjustments, reconciliations, transfers send/receive) · POS & sales (calculate, create, cancel, list, detail, receipt, held, payment intents, confirm-manual) · Returns & refunds & exchanges · Payment webhooks (public, signature-verified, idempotent) · Purchasing · Customers & ledger & collections & loyalty · Finance (shifts, cash-movements, expenses, daily-close, reopen, reconcile) · Approvals (list, approve, reject, verify-pin) · Reports & exports & dashboards & alerts · Sync (status, pull, operations, conflicts, push, resolve) · Security (users, roles, permissions, security-events, audit-logs, reset-password) · Integrations (webhooks, api-keys, accounting export).

Every endpoint documents permission code, module, request/response schema, error codes and audit behaviour. Register all endpoints, permission codes, setting keys and translation keys in **code-generated catalogues** so docs and tests stay in sync.

**Adapter interfaces (no vendor hardcoded):** PaymentProvider (createIntent, getStatus, refund, verifyWebhook) · NotificationChannel (send) · ReceiptPrinter (print) · WeighingScale (read) · BarcodeScanner (keyboard-wedge default) · AccountingExporter (generic CSV first).

---

## 11. SCREENS AND DYNAMIC UI

Build the screen catalogue for Release 1: AUTH-01..04, 06..10 · SA-01..08, 10 · ON-01..10 · DASH-01, 03, 04 · POS-01..16 · SAL-01..08 · PRD-01..04, 06..10 · INV-01..05, 10, 11, 13 · PUR-01..03, 05..08, 10, 11 · CUS-01..05, 07 · FIN-01..05, 09, 10 · REP-01, 02, 04, 05, 07, 13, 17, 19 · USR-01..07 · SET-01..22 · SYN-01..07 · TPL-VEG-01..03 · APR-01 · NOT-01.
The original screen IDs are a naming guide. Where the exact list is not available to you, design sensible screens that cover the named area, record the mapping in `docs/TRACEABILITY.md`, and move on.

**Schema-driven UI rules:** menu built only from `GET /tenant/config` (no static menu) · forms render template and custom fields from definitions (validation + conditional visibility) · all text is translation keys, with backend errors returning message keys and field errors mapped to fields · list columns/filters/sort from list definitions and user preferences, always server-side paginated · theme (brand colour, logo, receipt layout) from tenant settings, with design tokens as the only source of colour/spacing/typography · every screen has loading, empty (guides next action), error-with-retry and success states · inline validation, unsaved-change warning, double-submit guard · responsive: desktop two-panel POS, tablet collapsible sidebar + touch targets, mobile bottom nav + fixed total/pay bar · accessibility: contrast, labelled inputs, keyboard navigation, focus indicators, POS keyboard shortcuts (POS-16) · **no mock data on any screen**.

**Performance targets (validate in Phase 8):** local search over 50,000 products < 100 ms · add to cart < 50 ms on a mid-range tablet · `POST /sales` p95 < 800 ms server time · sync push ≥ 100 ops/s per worker · initial snapshot < 60 s typical branch. If missed, document the measured numbers and the cause.

---

## 12. PHASES (execute in order; self-gate each)

For each phase: do the work in the order from 1.11, commit, run the **full** test suite and static checks, write `docs/reports/phase-N.md` (Section 14), update `PROGRESS.md`, tag, then continue automatically.

| # | Build | Exit gate (must be executed and pass) |
|---|---|---|
| 0 | Repo, CI, static checks for Dynamic-Only Rule, `check-env.ps1`, ERD, data dictionary, OpenAPI skeleton, threat model, sync design, golden vectors (inclusive/exclusive tax, multiple components, line/invoice discounts, cash rounding) | Docs written. CI runs and **fails on a deliberate rule violation** (then remove the violation) |
| 1 | Platform/tenant/config migrations, tenancy + global scope, two guards, auth/tokens/devices/security events, roles/permissions/branch access, settings service + cache, modules, templates, custom fields, rules, lookups, translations, numbering, tax, payment methods, audit + approval frameworks, onboarding backend + go-live, matching screens | Tenant isolation, permission, login, lockout and dynamic-configuration tests pass. Changing a setting or module changes behaviour with no deployment |
| 2 | Products, variants, units/conversions, barcodes, categories, brands, price lists + history, import/export jobs, warehouses, registers, stock balances + ledger, opening stock, adjustments, weighted-average costing, batches, serials, low-stock alerts | Balance always equals ledger sum. Two concurrent decrements of the last unit cannot both succeed. Import reports every rejected row |
| 3 | Customers/groups, suppliers, ledgers, collections + allocation, loyalty basics, shifts, cash movements, expenses | Ledger balances equal entry sums. Shift expected-cash matches formula. Closed shifts immutable |
| 4 | Pricing engine (PHP + JS on golden vectors), sale calculate + create with invoices, payment methods/intents/manual confirm/webhooks/adapters, held bills, discount + approval rules, receipts with browser print, POS client on outbox abstraction | Failed sale leaves nothing. Same key replays with no duplicates. Server ignores client prices. Both engines pass every golden vector |
| 5 | Cancellations, returns, refunds, exchanges, purchasing (orders, receiving, returns, supplier payments), daily close/reopen, reports + dashboards with 8.13 formulas, export center, notifications + alerts | Returns cannot exceed sold. Refund uses original values. Reports match hand-computed fixtures. Closed days need reopen permission |
| 6 | Hardware adapters, Vegetable/Grocery template (weighing, daily rate update, wastage), template snapshot + update preview | Template data drives the full grocery flow with **no grocery-specific code branches** |
| 7 | Offline engine: device policy, snapshot + incremental pull, outbox push, idempotency, conflicts + resolution screens, revoke + wipe, encrypted local storage, offline numbering | All Section 13.2 offline tests pass |
| 8 | Security tests, performance tests vs 11, backup/restore verification, monitoring + alerting, Docker/hosting, runbook, fixes | Restore test succeeds and integrity checks pass. Targets met or exceptions documented |

After Phase 8, run the **Final Acceptance** in Section 15, then proceed to Release 2 items (Section 2) if capacity remains.

---

## 13. TEST PLAN

### 13.1 Mandatory groups
- **Tenancy:** Shop A cannot read/update/export/sync/search Shop B data by any route. Foreign IDs return 404. Platform users cannot read tenant business data.
- **Permissions:** every endpoint rejects a user lacking the permission code. Branch access enforced. A custom role behaves exactly like a standard role.
- **Dynamic configuration:** change tax rate, currency decimals, invoice format, payment-method list, module flag, discount limit, custom field, translation, stock policy → behaviour changes with no code change. CI fails on forbidden literals.
- **Money and tax:** golden vectors pass in PHP and JS.
- **Stock:** balance = ledger sum after any sequence of operations. Last-unit concurrency. Opposite-order multi-item sales cause no deadlock. FEFO and serial rules.
- **Idempotency:** replay, key reuse with different payload, two simultaneous identical requests create one sale.
- **Transactions:** inject a failure at each step of sale, return, receiving and transfer and prove nothing was written.
- **Payments:** webhook replay, expired intent, failed payment, manual-confirm permission, split payments, change due, partial credit.
- **Returns/refunds:** quantity limit, original-value refund, resellable vs damaged, refund state independent of return state.
- **Shifts/cash:** expected-cash formula, one open shift per register, close blocked with non-empty queue, closed-shift immutability.
- **Approvals:** run exactly once after approval, approver audited.
- **Reports:** each 8.13 figure against hand-calculated fixtures. Tenant/branch scoping. Export only for requester.
- **Security:** SQL injection, XSS, IDOR, token rotation and replay, rate limiting, lockout, secrets never in responses.
- **UI:** loading/empty/error/success states, double-submit guard, keyboard-only billing, responsive layouts.
- **Performance and Recovery:** Section 11 targets under realistic volumes. Backup restores into a clean environment and the integrity checks pass.

### 13.2 Offline gate (Phase 7)
Kill the app mid-sale and after submit → outbox survives, sale syncs once · sell offline then reconnect → every sale accepted once with correct numbers · resubmit same operation UUID → earlier result, nothing new · two devices sell the last unit offline → both accepted, `STOCK_SHORTFALL` flagged, none lost · server price changes while offline → mismatch policy applied and difference recorded · revoked device: pre-revocation ops accepted, later rejected for review, local data wiped on next contact · expired/suspended tenant → offline sales stored, never discarded · device series never repeat, provisional numbers replaced correctly · clock skew, 500-operation queue, interrupted sync with backoff · rejected items stay visible until resolved.

---

## 14. PHASE REPORT (write at the end of every phase, in `docs/reports/phase-N.md`)

1. Phase and scope completed
2. Files changed and migrations added
3. Endpoints, permission codes, setting keys, translation keys added
4. Tests added **with the real executed results** (counts, failures)
5. Dynamic-Only static-check results
6. Known issues, deviations (with DECISIONS IDs)
7. Remaining work and next phase (which you then start immediately)

---

## 15. FINAL ACCEPTANCE (all must be demonstrably true, with evidence in `docs/reports/FINAL.md`)

Shop A cannot access Shop B by any route · calls without permission are rejected · frontend prices and totals are ignored · a failed sale leaves no invoice, payment or stock movement · same idempotency key creates no duplicate · two cashiers cannot both sell the last unit when negative stock is blocked · stock balance always equals ledger sum · returns cannot exceed quantity sold · offline operations survive restart and show until acknowledged, and no completed offline sale is ever discarded · every business value, label, tax, menu entry and rule is changeable from the app without deployment · no screen has hardcoded or mock business data · every critical change is in the audit log · a backup has been restored and verified.

Also deliver: `README.md` (Windows PowerShell setup in under 10 commands), `docs/RUNBOOK.md`, `docs/TRACEABILITY.md` (each requirement → code → test), a seeded demo tenant created **through the real onboarding flow** (not fake rows), and a final summary listing what is complete, what is partial, every Known Issue, and every item in `DECISIONS.md` that a human should review.

---

## 16. START NOW

1. Read `docs/PROGRESS.md` and `docs/DECISIONS.md` if they exist. Otherwise create the repository structure and begin Phase 0.
2. Do not ask questions. Do not wait. Decide, record, build, test, commit, and continue through every phase until Section 15 is satisfied.
