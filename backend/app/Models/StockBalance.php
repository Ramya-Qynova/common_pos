<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Derived/cached from stock_ledger (G5) - a scheduled integrity job
 * re-verifies quantity == SUM(stock_ledger) and alerts on mismatch
 * (docs/data-dictionary.md "Integrity jobs"); not built this phase.
 *
 * @property string $quantity DECIMAL(18,4) as a string (G3) - always handled via bcmath, never cast to float.
 * @property string $average_cost DECIMAL(18,4) as a string (G3).
 * @property string|null $reorder_point DECIMAL(18,4) as a string (G3).
 */
class StockBalance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'product_id', 'variant_id', 'batch_id',
        'variant_key', 'batch_key', 'quantity', 'average_cost', 'reorder_point',
    ];
}
