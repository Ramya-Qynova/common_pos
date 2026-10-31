<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SerialNumber extends Model
{
    use BelongsToTenant;

    protected $table = 'serial_numbers';

    protected $fillable = ['tenant_id', 'product_id', 'variant_id', 'warehouse_id', 'serial_number', 'status'];
}
