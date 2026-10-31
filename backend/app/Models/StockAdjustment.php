<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'warehouse_id', 'type', 'reason', 'created_by', 'approved_by', 'posted_at'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }
}
