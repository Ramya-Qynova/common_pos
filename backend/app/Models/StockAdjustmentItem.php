<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    protected $fillable = ['stock_adjustment_id', 'product_id', 'variant_id', 'batch_id', 'direction', 'quantity', 'unit_cost'];
}
