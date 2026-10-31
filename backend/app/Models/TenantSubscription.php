<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'grace_ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'grace_ends_at' => 'datetime'];
    }
}
