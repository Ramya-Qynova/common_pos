<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'type', 'status', 'file_path', 'requested_by', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
