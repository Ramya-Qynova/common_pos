<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (G5, G9) - login success/failure, lockout, token rotation,
 * device revocation. No tenant global scope: a security event must remain
 * readable by a tenant admin investigating their own tenant_id explicitly,
 * and platform-level events have tenant_id null, so the automatic
 * tenant-context scope is intentionally not applied here (filtering is
 * done explicitly by whoever reads this table).
 */
class SecurityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'platform_user_id', 'event_type', 'ip_address', 'user_agent', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
