<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $subtotal DECIMAL(18,4) as a string (G3).
 * @property string $tax_total DECIMAL(18,4) as a string (G3).
 * @property string $grand_total DECIMAL(18,4) as a string (G3).
 */
class Purchase extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'purchase_order_id', 'supplier_id', 'warehouse_id',
        'subtotal', 'tax_total', 'grand_total', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    /**
     * @return HasMany<PurchaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
