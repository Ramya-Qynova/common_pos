<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'code', 'name', 'is_active'];
}
