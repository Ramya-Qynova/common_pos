<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'code', 'name', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
