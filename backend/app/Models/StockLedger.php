<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (G5, G9) - no update()/delete() path is exposed by any
 * service; a correction is a new row with the opposite direction.
 */
class StockLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'stock_ledger';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'uuid', 'warehouse_id', 'product_id', 'variant_id', 'batch_id',
        'direction', 'quantity', 'unit_cost', 'source_type', 'source_id', 'source_line_id',
        'balance_after', 'reason', 'user_id', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
