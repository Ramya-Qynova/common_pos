<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleDiscount extends Model
{
    protected $fillable = ['sale_id', 'type', 'sale_item_id', 'discount_rule_id', 'amount', 'reason', 'approved_by'];
}
