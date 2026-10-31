<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformTranslation extends Model
{
    protected $fillable = ['language_code', 'key', 'value', 'needs_review'];

    protected function casts(): array
    {
        return ['needs_review' => 'boolean'];
    }
}
