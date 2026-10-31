<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'variant_id', 'variant_key', 'price_list_id', 'unit_id', 'price', 'row_version'];
}
