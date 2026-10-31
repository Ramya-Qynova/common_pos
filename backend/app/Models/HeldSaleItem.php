<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $unit_id
 * @property string $quantity DECIMAL(18,4) as a string (G3).
 */
class HeldSaleItem extends Model
{
    protected $fillable = ['held_sale_id', 'product_id', 'variant_id', 'unit_id', 'quantity'];
}
