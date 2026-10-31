<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $quantity DECIMAL(18,4) as a string (G3).
 */
class PurchaseReturnItem extends Model
{
    protected $fillable = ['purchase_return_id', 'purchase_item_id', 'quantity'];
}
