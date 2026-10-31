<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

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
}
