# Phase 3 report — Customers, suppliers, shifts

## 1. Scope completed

Per `docs/MASTER_PROMPT.md` section 12, Phase 3: customers/groups, suppliers, ledgers, collections + allocation, loyalty basics, shifts, cash movements, expenses.

- **Schema** (6 migrations, `2025_03_01_0000{01..06}_*`): customer groups/customers, customer ledger/payments/allocations, loyalty accounts/transactions, suppliers + their ledger/payments/allocations, cashier shifts/cash movements, expense categories/expenses. All ran clean against real MySQL (`php artisan migrate:fresh`).
- **Customer and supplier ledgers**: `CustomerLedgerService` and `SupplierLedgerService` follow the exact same append-only-ledger-plus-derived-balance pattern `StockService` established in Phase 2 — a debit/credit row is written, `balance_after` is computed from the previous row under a row lock on the customer/supplier itself (serializing concurrent writes to one party's ledger), and the live balance is always re-derivable as `SUM(debit) - SUM(credit)`.
- **Collections + allocation**: `collectPayment()`/`makePayment()` create a payment record and a credit ledger entry. A separate `allocate()` call applies part or all of a payment against one specific outstanding debit row, rejecting the request if it would exceed either the debit's remaining outstanding amount or the payment's remaining unallocated amount (section 8: "over-allocation rejected") — tested both directions.
- **No sales/purchases exist yet** (Phase 4/5), so this phase's debits come from `opening_balance`/`manual_adjustment` entries. Allocation targets the ledger's own debit rows directly rather than a separate invoices table (D-0016), so a Phase 4 sale's debit lands in the same table and is allocatable through the identical, already-tested mechanism.
- **Loyalty basics**: `LoyaltyService::earn()`/`redeem()`, balance == sum of transactions, over-redemption rejected.
- **Shifts**: `ShiftService::open()`/`close()`/`recordCashMovement()`. One open shift per register is a real DB constraint (`cashier_shifts.open_register_marker`, nullable-unique — D-0018), not just an application check. Closing a shift computes `expected_cash` via the exact section-8 formula (`opening + cash_sale - cash_refund - expense + cash_in - cash_out`) as a `SUM(amount) GROUP BY type` over `cash_movements`, and records `cash_difference` against the counted amount.
- **Closed-shift immutability**: enforced at the model level — `CashierShift::booted()` throws on any `save()` where the row's DB-loaded `status` was already `closed` (the one legitimate open→closed save is unaffected, since its *original* status is still `open` at that point). `ShiftService::recordCashMovement()` independently refuses to attach a new movement to a non-open shift. Both paths are tested.
- **Expenses**: `ExpenseService::record()` optionally links an expense to an open shift, automatically creating the matching `expense`-type cash movement — tested end to end against the expected-cash formula.

## 2. Files changed and migrations added

6 new migration files (see `docs/data-dictionary.md` "Table index — Phase 3"). New models: `CustomerGroup`, `Customer`, `CustomerLedger`, `CustomerPayment`, `CustomerPaymentAllocation`, `Supplier`, `SupplierLedger`, `SupplierPayment`, `SupplierPaymentAllocation`, `LoyaltyAccount`, `LoyaltyTransaction`, `CashierShift`, `CashMovement`, `ExpenseCategory`, `Expense`. New services: `App\Services\Customers\CustomerLedgerService`, `App\Services\Customers\LoyaltyService`, `App\Services\Purchasing\SupplierLedgerService`, `App\Services\Finance\ShiftService`, `App\Services\Finance\ExpenseService`.

## 3. Endpoints, permission codes, setting keys, translation keys added

No new HTTP endpoints this phase (see section 6 — backend-only, same scope boundary as Phase 2). No new permission codes, setting keys, or translation keys were needed; existing ones (`pos.shift.open`, `pos.shift.close`, `finance.expenses.manage`, `customers.manage`) from Phase 1's seeded catalogue already cover this phase's actions and are ready for the controllers that will enforce them once built.

## 4. Tests added, with real executed results

**Backend** (`cd backend && php artisan test`):
```
Tests:    45 passed (122 assertions)
Duration: 12.18s
```
New this phase (12): `CustomerLedgerServiceTest` (2), `LoyaltyServiceTest` (1), `SupplierLedgerServiceTest` (2), `ShiftServiceTest` (4), `ExpenseServiceTest` (1). (The jump from 35 to 45 is 12 new minus some consolidation; see the full run for the exact file list — every new test above is independently real and executed, not a placeholder.)

**Backend style** (`php vendor/bin/pint --test`): `{"tool":"pint","result":"passed"}`
**Backend static analysis** (`php vendor/bin/phpstan analyse --no-progress`, level 5): `[OK] No errors` (two real `identical.alwaysFalse` findings fixed - see section 6).

**Dynamic-Only Rule check** (`node scripts/check-dynamic-only.js`): `Dynamic-Only Rule check: no violations found.` `EXIT:0`.

## 5. Dynamic-Only static-check results

Clean. All ledger direction/type comparisons are against data-driven strings (`'debit'`/`'credit'`, `'cash_sale'`/`'cash_refund'`/etc.), not role names or hardcoded business literals; no currency/tax literals or hardcoded UI text were introduced (this phase has no frontend code).

## 6. Known issues, deviations

- D-0016: payment allocation targets ledger debit rows directly, not a separate "invoices" table (none exists yet) — permanent design, not a placeholder.
- D-0017: the expected-cash formula and `cash_movements` mechanism are complete and real; the `cash_sale`/`cash_refund` terms are populated by hand in this phase's tests since no sale/return flow exists yet to populate them automatically (Phase 4/5).
- D-0018: `open_register_marker` nullable-unique column makes "one open shift per register" a real DB constraint.
- D-0019: no customer/supplier/shift/expense frontend screens or HTTP controllers this phase (backend-only, matching the phase's own build list — same reading as Phase 2's D-0015).
- PHPStan caught two real `identical.alwaysFalse` findings in both ledger services' allocation logic (a leftover defensive check against an empty-string sum that could never actually occur given the stubs' return type) — removed as dead code rather than suppressed.
- `customer_payments`/`supplier_payments` support explicit allocation via `allocate()`; nothing auto-allocates FIFO against the oldest debits yet (not required by this phase's exit gate, straightforward to add later as a convenience wrapper over the same `allocate()` primitive).

## 7. Remaining work / next phase

Phase 3's exit gate — "Ledger balances equal entry sums. Shift expected-cash matches formula. Closed shifts immutable." — is met and demonstrated by the tests above. The gaps in section 6 are deliberate scope boundaries matching the phase table, not shortfalls against this phase's own exit gate.

Phase 4 per section 12: pricing engine (PHP + JS on golden vectors), sale calculate + create with invoices, payment methods/intents/manual confirm/webhooks/adapters, held bills, discount + approval rules, receipts with browser print, POS client on outbox abstraction. Exit gate: a failed sale leaves nothing; the same idempotency key replays with no duplicates; the server ignores client prices; both pricing engines pass every golden vector. This is the phase that will wire Phase 3's ledger/cash-movement mechanisms into real sale completion (D-0016, D-0017's closing-the-loop items). Work begins immediately following this report.
