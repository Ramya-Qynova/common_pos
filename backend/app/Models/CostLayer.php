<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostLayer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'variant_id',
        'batch_id',
        'quantity_remaining',
        'unit_cost',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_remaining' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'received_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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
