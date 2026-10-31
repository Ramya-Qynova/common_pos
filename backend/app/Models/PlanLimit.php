<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanLimit extends Model
{
    protected $fillable = ['plan_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
