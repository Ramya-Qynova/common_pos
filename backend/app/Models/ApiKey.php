<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'uuid',
        'name',
        'key_hash',
        'prefix',
        'secret_preview',
        'permissions',
        'is_active',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
