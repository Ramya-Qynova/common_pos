<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $product_id
 * @property string $quantity DECIMAL(18,4) as a string (G3).
 * @property string $unit_cost DECIMAL(18,4) as a string (G3).
 * @property string $received_quantity DECIMAL(18,4) as a string (G3).
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'variant_id', 'unit_id', 'quantity', 'unit_cost', 'received_quantity',
    ];
}
