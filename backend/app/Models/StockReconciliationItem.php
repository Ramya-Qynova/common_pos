<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReconciliationItem extends Model
{
    protected $fillable = [
        'stock_reconciliation_id',
        'product_id',
        'variant_id',
        'batch_id',
        'system_quantity',
        'counted_quantity',
        'difference_quantity',
        'unit_cost',
        'variance_value',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'difference_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'variance_value' => 'decimal:4',
        ];
    }

    public function stockReconciliation(): BelongsTo
    {
        return $this->belongsTo(StockReconciliation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
