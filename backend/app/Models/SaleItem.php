<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $product_id
 * @property string $quantity DECIMAL(18,4) as a string (G3).
 * @property string $unit_price DECIMAL(18,4) as a string (G3).
 * @property string $line_total DECIMAL(18,4) as a string (G3).
 * @property string $cost_snapshot DECIMAL(18,4) as a string (G3).
 * @property string $taxable_amount DECIMAL(18,4) as a string (G3).
 * @property string $tax_amount DECIMAL(18,4) as a string (G3).
 * @property int|null $variant_id
 * @property int|null $batch_id
 */
class SaleItem extends Model
{
    protected $fillable = [
        'sale_id', 'product_id', 'variant_id', 'batch_id', 'unit_id', 'quantity', 'unit_price',
        'line_discount_amount', 'taxable_amount', 'tax_amount', 'line_total', 'cost_snapshot', 'price_source',
    ];

    public function taxes(): HasMany
    {
        return $this->hasMany(SaleItemTax::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
