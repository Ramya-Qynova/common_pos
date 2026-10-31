<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'batch_number', 'expiry_date', 'received_at'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'received_at' => 'datetime'];
    }
}
