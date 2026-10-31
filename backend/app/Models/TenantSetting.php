<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
