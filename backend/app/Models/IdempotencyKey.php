<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'key', 'request_hash', 'status', 'response_body', 'http_status'];

    protected function casts(): array
    {
        return ['response_body' => 'array'];
    }
}
