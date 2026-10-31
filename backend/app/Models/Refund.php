<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $amount DECIMAL(18,4) as a string (G3).
 */
class Refund extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'sale_id', 'sale_return_id', 'sale_cancellation_id', 'amount', 'method',
        'payment_method_id', 'status', 'processed_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
