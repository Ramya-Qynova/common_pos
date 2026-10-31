<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $product_id
 * @property string $quantity DECIMAL(18,4) as a string (G3).
 * @property string $unit_cost DECIMAL(18,4) as a string (G3).
 * @property string $tax_amount DECIMAL(18,4) as a string (G3).
 * @property string $line_total DECIMAL(18,4) as a string (G3).
 * @property string $returned_quantity DECIMAL(18,4) as a string (G3).
 */
class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id', 'product_id', 'variant_id', 'unit_id', 'quantity', 'unit_cost',
        'tax_amount', 'line_total', 'returned_quantity',
    ];
}
