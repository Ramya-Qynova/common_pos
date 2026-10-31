<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class PriceListItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'price_list_id', 'product_id', 'variant_id', 'unit_id', 'price', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }
}
