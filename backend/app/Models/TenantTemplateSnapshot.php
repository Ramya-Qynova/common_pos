<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantTemplateSnapshot extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_template_snapshot';

    protected $fillable = ['tenant_id', 'template_id', 'template_version', 'data', 'applied_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'applied_at' => 'datetime'];
    }
}
