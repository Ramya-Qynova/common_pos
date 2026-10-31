<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $points DECIMAL(18,4) as a string (G3).
 * @property string $balance_after DECIMAL(18,4) as a string (G3).
 */
class LoyaltyTransaction extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'uuid', 'loyalty_account_id', 'direction', 'points', 'source_type', 'source_id', 'balance_after', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
