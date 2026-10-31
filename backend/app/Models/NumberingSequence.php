<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class NumberingSequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'series', 'device_id', 'fiscal_year', 'prefix', 'next_number'];
}
