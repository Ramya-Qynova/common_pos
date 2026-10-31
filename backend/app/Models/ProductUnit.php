<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    protected $fillable = ['product_id', 'unit_id', 'conversion_factor', 'is_purchase_default', 'is_sale_default'];

    protected function casts(): array
    {
        return ['is_purchase_default' => 'boolean', 'is_sale_default' => 'boolean'];
    }
}
