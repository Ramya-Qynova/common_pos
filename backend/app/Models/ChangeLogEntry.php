<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ChangeLogEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'change_log';

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'entity_type', 'entity_id', 'action', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
