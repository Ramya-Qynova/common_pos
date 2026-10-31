<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $opening_cash DECIMAL(18,4) as a string (G3).
 * @property string|null $closing_cash_counted DECIMAL(18,4) as a string (G3).
 * @property string|null $expected_cash DECIMAL(18,4) as a string (G3).
 * @property string|null $cash_difference DECIMAL(18,4) as a string (G3).
 */
class CashierShift extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'register_id', 'opened_by', 'closed_by', 'opening_cash',
        'closing_cash_counted', 'expected_cash', 'cash_difference', 'status',
        'open_register_marker', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'cashier_shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * section 8/13.1: "Closed shifts are immutable." Blocks any save() on
     * a row whose DB-loaded status was already 'closed' - the one save()
     * that legitimately transitions open -> closed is unaffected, since at
     * that point getOriginal('status') is still 'open'.
     */
    protected static function booted(): void
    {
        static::updating(function (CashierShift $shift) {
            if ($shift->getOriginal('status') === 'closed') {
                throw new \RuntimeException('Closed shifts are immutable.');
            }
        });
    }
}
