<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleDefinition extends Model
{
    protected $fillable = ['code', 'name', 'description', 'dependencies', 'permissions', 'menu', 'is_core'];

    protected function casts(): array
    {
        return ['dependencies' => 'array', 'permissions' => 'array', 'menu' => 'array', 'is_core' => 'boolean'];
    }
}
