<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItemTax extends Model
{
    protected $fillable = ['sale_item_id', 'tax_code', 'amount'];
}
