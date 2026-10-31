<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'name', 'type', 'layout', 'is_active'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }
}
