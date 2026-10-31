<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'shop_code', 'name', 'status', 'plan_id', 'template_id', 'timezone'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function isModuleEnabled(string $moduleCode): bool
    {
        return $this->modules()->where('module_code', $moduleCode)->where('is_enabled', true)->exists();
    }
}
