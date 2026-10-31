<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SyncOperation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'device_id', 'user_id', 'type', 'branch_id', 'register_id',
        'client_created_at', 'received_at', 'status', 'reject_reason', 'result_payload', 'hash',
    ];

    protected function casts(): array
    {
        return ['client_created_at' => 'datetime', 'received_at' => 'datetime', 'result_payload' => 'array'];
    }
}
