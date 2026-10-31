<?php

namespace App\Services\Purchasing;

use App\Exceptions\ApiException;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mirror of CustomerLedgerService for the payable side: debit = we owe
 * more (opening balance now; a purchase receipt from Phase 5), credit =
 * we owe less (a payment we made).
 */
class SupplierLedgerService
{
    public function recordEntry(int $tenantId, int $supplierId, string $direction, string $amount, string $sourceType, ?int $sourceId = null, ?string $reason = null, ?int $userId = null): SupplierLedger
    {
        return DB::transaction(function () use ($tenantId, $supplierId, $direction, $amount, $sourceType, $sourceId, $reason, $userId) {
            Supplier::withoutTenantScope()->where('id', $supplierId)->lockForUpdate()->first();

            $previousBalance = SupplierLedger::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('supplier_id', $supplierId)
                ->orderByDesc('id')->value('balance_after') ?? '0.0000';

            $delta = $direction === 'debit' ? $amount : bcmul($amount, '-1', 4);
            $newBalance = bcadd($previousBalance, $delta, 4);

            $entry = SupplierLedger::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'supplier_id' => $supplierId,
                'direction' => $direction, 'amount' => $amount, 'allocated_amount' => '0.0000',
                'source_type' => $sourceType, 'source_id' => $sourceId, 'balance_after' => $newBalance,
                'reason' => $reason, 'user_id' => $userId, 'occurred_at' => now(), 'created_at' => now(),
            ]);

            AuditLogger::log($tenantId, $userId, 'supplier_ledger.entry', 'SupplierLedger', $entry->id, null, [
                'direction' => $direction, 'amount' => $amount, 'balance_after' => $newBalance,
            ]);

            return $entry;
        });
    }

    public function makePayment(int $tenantId, int $supplierId, string $amount, ?int $paymentMethodId, ?string $reference, int $paidBy): SupplierPayment
    {
        return DB::transaction(function () use ($tenantId, $supplierId, $amount, $paymentMethodId, $reference, $paidBy) {
            $payment = SupplierPayment::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'supplier_id' => $supplierId,
                'amount' => $amount, 'payment_method_id' => $paymentMethodId, 'reference' => $reference,
                'paid_by' => $paidBy, 'paid_at' => now(),
            ]);

            $this->recordEntry($tenantId, $supplierId, 'credit', $amount, 'payment', $payment->id, null, $paidBy);

            return $payment;
        });
    }

    public function allocate(int $supplierPaymentId, int $supplierLedgerId, string $amount): SupplierPaymentAllocation
    {
        return DB::transaction(function () use ($supplierPaymentId, $supplierLedgerId, $amount) {
            $payment = SupplierPayment::withoutTenantScope()->where('id', $supplierPaymentId)->lockForUpdate()->first();
            $debit = SupplierLedger::withoutTenantScope()->where('id', $supplierLedgerId)->lockForUpdate()->first();

            $alreadyAllocated = (string) SupplierPaymentAllocation::where('supplier_payment_id', $payment->id)->sum('amount');
            $paymentRemaining = bcsub($payment->amount, $alreadyAllocated, 4);
            $debitOutstanding = bcsub($debit->amount, $debit->allocated_amount, 4);

            if (bccomp($amount, $paymentRemaining, 4) > 0) {
                throw new ApiException('VALIDATION_FAILED', "Allocation of {$amount} exceeds the payment's unallocated remainder of {$paymentRemaining}.", 422);
            }
            if (bccomp($amount, $debitOutstanding, 4) > 0) {
                throw new ApiException('VALIDATION_FAILED', "Allocation of {$amount} exceeds the ledger entry's outstanding amount of {$debitOutstanding}.", 422);
            }

            $allocation = SupplierPaymentAllocation::create([
                'supplier_payment_id' => $payment->id, 'supplier_ledger_id' => $debit->id, 'amount' => $amount,
            ]);

            $debit->allocated_amount = bcadd($debit->allocated_amount, $amount, 4);
            $debit->save();

            return $allocation;
        });
    }

    public function currentBalance(int $tenantId, int $supplierId): string
    {
        return SupplierLedger::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('supplier_id', $supplierId)
            ->orderByDesc('id')->value('balance_after') ?? '0.0000';
    }
}
