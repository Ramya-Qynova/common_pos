<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LoyaltyRule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'name', 'condition', 'action', 'priority', 'is_active'];

    protected function casts(): array
    {
        return ['condition' => 'array', 'action' => 'array'];
    }
}
