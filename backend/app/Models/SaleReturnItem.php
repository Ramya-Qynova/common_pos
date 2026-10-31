<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $quantity DECIMAL(18,4) as a string (G3).
 */
class SaleReturnItem extends Model
{
    protected $fillable = ['sale_return_id', 'sale_item_id', 'quantity', 'condition'];
}
