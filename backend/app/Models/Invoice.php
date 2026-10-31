<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $full_number
 */
class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sale_id', 'series', 'fiscal_year', 'number', 'prefix', 'full_number', 'status'];
}
