<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductRecipe extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'component_product_id', 'component_variant_id', 'quantity', 'unit_id'];
}
