<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CostLayer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'warehouse_id', 'product_id', 'variant_id', 'batch_id', 'quantity_remaining', 'unit_cost', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
