<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $points_balance DECIMAL(18,4) as a string (G3).
 */
class LoyaltyAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'customer_id', 'points_balance'];
}
