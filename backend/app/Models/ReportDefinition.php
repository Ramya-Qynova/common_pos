<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDefinition extends Model
{
    protected $fillable = ['tenant_id', 'code', 'name', 'parameters'];

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }
}
