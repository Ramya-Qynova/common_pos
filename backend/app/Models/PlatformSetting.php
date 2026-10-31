<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'allowed_values', 'description'];

    protected function casts(): array
    {
        return ['value' => 'array', 'allowed_values' => 'array'];
    }
}
