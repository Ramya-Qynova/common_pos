<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (same spirit as stock_ledger/customer_ledger) - the
 * expected-cash formula (section 8 "Shifts and cash") is a SUM over these
 * rows by `type`, never a separately-maintained running total.
 *
 * @property string $amount DECIMAL(18,4) as a string (G3).
 */
class CashMovement extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'uuid', 'cashier_shift_id', 'type', 'amount', 'reason', 'source_type', 'source_id', 'user_id', 'occurred_at', 'created_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
