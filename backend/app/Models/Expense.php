<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $amount DECIMAL(18,4) as a string (G3).
 */
class Expense extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'expense_category_id', 'amount', 'description', 'cashier_shift_id', 'paid_by', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
