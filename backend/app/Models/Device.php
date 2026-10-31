<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'uuid', 'name', 'platform', 'is_revoked', 'revoked_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['is_revoked' => 'boolean', 'revoked_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
