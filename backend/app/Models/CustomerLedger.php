<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (G5, G9). balance_after is a point-in-time snapshot; the
 * customer's live balance is always SUM(debit) - SUM(credit) over this
 * table, never a separately-typed-in column (section 8 "Ledgers and
 * collections").
 *
 * @property string $amount DECIMAL(18,4) as a string (G3).
 * @property string $allocated_amount DECIMAL(18,4) as a string (G3).
 * @property string $balance_after DECIMAL(18,4) as a string (G3).
 */
class CustomerLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_ledger';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'uuid', 'customer_id', 'direction', 'amount', 'allocated_amount',
        'source_type', 'source_id', 'balance_after', 'reason', 'user_id', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
