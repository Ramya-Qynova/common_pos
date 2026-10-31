<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_active'];

    /**
     * @return HasMany<TemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    public function latestPublished(): ?TemplateVersion
    {
        return $this->versions()->where('is_published', true)->orderByDesc('version')->first();
    }
}
