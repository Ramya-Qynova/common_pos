<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $access_expires_at
 * @property Carbon $refresh_expires_at
 * @property Carbon|null $revoked_at
 */
class PlatformSession extends Model
{
    protected $fillable = [
        'platform_user_id', 'access_token_hash', 'refresh_token_hash',
        'access_expires_at', 'refresh_expires_at', 'revoked_at', 'rotated_from_id',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['access_expires_at' => 'datetime', 'refresh_expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
