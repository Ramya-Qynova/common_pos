<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HeldSale extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'branch_id', 'warehouse_id', 'register_id', 'customer_id', 'created_by', 'status', 'held_at', 'expires_at'];

    protected function casts(): array
    {
        return ['held_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /**
     * @return HasMany<HeldSaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(HeldSaleItem::class);
    }
}
