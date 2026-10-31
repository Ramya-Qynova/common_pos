<?php

namespace App\Services\Finance;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseService
{
    public function __construct(private ShiftService $shifts) {}

    public function record(int $tenantId, int $categoryId, string $amount, ?string $description, ?int $cashierShiftId, int $paidBy): Expense
    {
        return DB::transaction(function () use ($tenantId, $categoryId, $amount, $description, $cashierShiftId, $paidBy) {
            $expense = Expense::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'expense_category_id' => $categoryId,
                'amount' => $amount, 'description' => $description, 'cashier_shift_id' => $cashierShiftId,
                'paid_by' => $paidBy, 'paid_at' => now(),
            ]);

            if ($cashierShiftId) {
                $this->shifts->recordCashMovement($tenantId, $cashierShiftId, 'expense', $amount, $description, $paidBy, 'expense', $expense->id);
            }

            return $expense;
        });
    }
}
