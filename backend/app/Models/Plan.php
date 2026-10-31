<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_active'];

    public function modules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(PlanSetting::class);
    }
}
