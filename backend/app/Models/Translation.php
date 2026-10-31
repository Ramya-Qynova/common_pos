<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Translation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'language_code', 'key', 'value', 'needs_review'];

    protected function casts(): array
    {
        return ['needs_review' => 'boolean'];
    }
}
