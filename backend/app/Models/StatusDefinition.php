<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StatusDefinition extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'entity_type', 'status_code', 'label', 'color', 'sort_order'];
}
