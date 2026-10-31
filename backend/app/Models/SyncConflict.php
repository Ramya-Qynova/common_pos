<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sync_operation_id', 'type', 'details', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'resolved_at' => 'datetime'];
    }
}
