<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per issued access+refresh token pair for the tenant guard
 * (section 5: "access token short-lived, refresh token rotating and
 * stored hashed"). Rotation creates a new row (rotated_from_id points
 * back) and revokes the old one rather than mutating it in place, so the
 * token history stays inspectable.
 *
 * @property Carbon $access_expires_at
 * @property Carbon $refresh_expires_at
 * @property Carbon|null $revoked_at
 */
class DeviceSession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'device_id', 'user_id', 'access_token_hash', 'refresh_token_hash',
        'access_expires_at', 'refresh_expires_at', 'revoked_at', 'rotated_from_id',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['access_expires_at' => 'datetime', 'refresh_expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isAccessValid(): bool
    {
        return $this->revoked_at === null && $this->access_expires_at->isFuture();
    }

    public function isRefreshValid(): bool
    {
        return $this->revoked_at === null && $this->refresh_expires_at->isFuture();
    }
}
