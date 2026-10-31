<?php

namespace App\Services\Customers;

use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Ledgers and collections": "balances are derived, never typed
 * in. Collections create payment transaction + ledger entry and may be
 * allocated to invoices (over-allocation rejected)." Allocation here
 * targets customer_ledger's own debit rows directly (see
 * docs/DECISIONS.md) since sales invoices don't exist until Phase 4 - a
 * sale's debit row will land in this exact same table and be allocatable
 * the same way once Phase 4 wires it in.
 */
class CustomerLedgerService
{
    public function recordEntry(int $tenantId, int $customerId, string $direction, string $amount, string $sourceType, ?int $sourceId = null, ?string $reason = null, ?int $userId = null): CustomerLedger
    {
        return DB::transaction(function () use ($tenantId, $customerId, $direction, $amount, $sourceType, $sourceId, $reason, $userId) {
            // Locking the customer row serializes concurrent ledger writes
            // for this customer, so balance_after is always computed from
            // a consistent "latest row" read (no two entries can race to
            // read the same previous balance).
            Customer::withoutTenantScope()->where('id', $customerId)->lockForUpdate()->first();

            $previousBalance = CustomerLedger::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('customer_id', $customerId)
                ->orderByDesc('id')->value('balance_after') ?? '0.0000';

            $delta = $direction === 'debit' ? $amount : bcmul($amount, '-1', 4);
            $newBalance = bcadd($previousBalance, $delta, 4);

            $entry = CustomerLedger::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'customer_id' => $customerId,
                'direction' => $direction, 'amount' => $amount, 'allocated_amount' => '0.0000',
                'source_type' => $sourceType, 'source_id' => $sourceId, 'balance_after' => $newBalance,
                'reason' => $reason, 'user_id' => $userId, 'occurred_at' => now(), 'created_at' => now(),
            ]);

            AuditLogger::log($tenantId, $userId, 'customer_ledger.entry', 'CustomerLedger', $entry->id, null, [
                'direction' => $direction, 'amount' => $amount, 'balance_after' => $newBalance,
            ]);

            return $entry;
        });
    }

    public function collectPayment(int $tenantId, int $customerId, string $amount, ?int $paymentMethodId, ?string $reference, int $receivedBy): CustomerPayment
    {
        return DB::transaction(function () use ($tenantId, $customerId, $amount, $paymentMethodId, $reference, $receivedBy) {
            $payment = CustomerPayment::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'customer_id' => $customerId,
                'amount' => $amount, 'payment_method_id' => $paymentMethodId, 'reference' => $reference,
                'received_by' => $receivedBy, 'received_at' => now(),
            ]);

            $this->recordEntry($tenantId, $customerId, 'credit', $amount, 'payment', $payment->id, null, $receivedBy);

            return $payment;
        });
    }

    /**
     * Allocates part (or all) of a payment against a specific outstanding
     * debit row. Rejects anything that would over-allocate either side
     * (section 8: "over-allocation rejected").
     */
    public function allocate(int $customerPaymentId, int $customerLedgerId, string $amount): CustomerPaymentAllocation
    {
        return DB::transaction(function () use ($customerPaymentId, $customerLedgerId, $amount) {
            $payment = CustomerPayment::withoutTenantScope()->where('id', $customerPaymentId)->lockForUpdate()->first();
            $debit = CustomerLedger::withoutTenantScope()->where('id', $customerLedgerId)->lockForUpdate()->first();

            $alreadyAllocated = (string) CustomerPaymentAllocation::where('customer_payment_id', $payment->id)->sum('amount');
            $paymentRemaining = bcsub($payment->amount, $alreadyAllocated, 4);
            $debitOutstanding = bcsub($debit->amount, $debit->allocated_amount, 4);

            if (bccomp($amount, $paymentRemaining, 4) > 0) {
                throw new ApiException('VALIDATION_FAILED', "Allocation of {$amount} exceeds the payment's unallocated remainder of {$paymentRemaining}.", 422);
            }
            if (bccomp($amount, $debitOutstanding, 4) > 0) {
                throw new ApiException('VALIDATION_FAILED', "Allocation of {$amount} exceeds the ledger entry's outstanding amount of {$debitOutstanding}.", 422);
            }

            $allocation = CustomerPaymentAllocation::create([
                'customer_payment_id' => $payment->id, 'customer_ledger_id' => $debit->id, 'amount' => $amount,
            ]);

            $debit->allocated_amount = bcadd($debit->allocated_amount, $amount, 4);
            $debit->save();

            return $allocation;
        });
    }

    public function currentBalance(int $tenantId, int $customerId): string
    {
        return CustomerLedger::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('customer_id', $customerId)
            ->orderByDesc('id')->value('balance_after') ?? '0.0000';
    }
}
