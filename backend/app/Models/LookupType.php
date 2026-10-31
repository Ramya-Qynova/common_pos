<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LookupType extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'name'];

    public function values(): HasMany
    {
        return $this->hasMany(LookupValue::class);
    }
}
