<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Barcode extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'variant_id', 'unit_id', 'barcode', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
