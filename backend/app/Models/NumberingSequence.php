<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class NumberingSequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'series', 'device_id', 'device_key', 'fiscal_year', 'fiscal_year_key', 'prefix', 'next_number'];
}
