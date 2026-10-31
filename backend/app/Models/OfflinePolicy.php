<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OfflinePolicy extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'device_id', 'operation_type', 'is_allowed'];

    protected function casts(): array
    {
        return ['is_allowed' => 'boolean'];
    }
}
