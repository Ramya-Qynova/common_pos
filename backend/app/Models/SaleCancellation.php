<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SaleCancellation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'sale_id', 'reason', 'requested_by', 'approved_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return ['cancelled_at' => 'datetime'];
    }
}
