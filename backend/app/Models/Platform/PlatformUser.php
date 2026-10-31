<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform guard's user model. Structurally separate from the tenant
 * `App\Models\User` - no tenant_id, no FK into any tenant-owned table.
 * Section 5 "Identity": "platform users cannot read tenant business data".
 */
class PlatformUser extends Model
{
    protected $fillable = ['platform_role_id', 'name', 'email', 'password', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(PlatformRole::class, 'platform_role_id');
    }
}
