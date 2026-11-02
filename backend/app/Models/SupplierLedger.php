<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $amount DECIMAL(18,4) as a string (G3).
 * @property string $allocated_amount DECIMAL(18,4) as a string (G3).
 * @property string $balance_after DECIMAL(18,4) as a string (G3).
 */
class SupplierLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_ledger';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'uuid', 'supplier_id', 'direction', 'amount', 'allocated_amount',
        'source_type', 'source_id', 'balance_after', 'reason', 'user_id', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
