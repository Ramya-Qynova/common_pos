<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $credit_limit DECIMAL(18,4) as a string (G3).
 */
class Customer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'code', 'name', 'phone', 'email', 'customer_group_id', 'credit_limit', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
