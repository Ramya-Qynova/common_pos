<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HardwareProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'register_id', 'code', 'type', 'config', 'is_active'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
