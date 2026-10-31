<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'uuid', 'sku', 'name', 'attributes', 'is_active'];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }
}
