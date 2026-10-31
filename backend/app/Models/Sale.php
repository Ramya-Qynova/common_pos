<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $line_subtotal DECIMAL(18,4) as a string (G3).
 * @property string $discount_total DECIMAL(18,4) as a string (G3).
 * @property string $taxable_amount DECIMAL(18,4) as a string (G3).
 * @property string $tax_total DECIMAL(18,4) as a string (G3).
 * @property string $grand_total DECIMAL(18,4) as a string (G3).
 */
class Sale extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'uuid', 'branch_id', 'warehouse_id', 'register_id', 'cashier_shift_id', 'customer_id',
        'status', 'payment_status', 'line_subtotal', 'discount_total', 'taxable_amount', 'tax_total', 'grand_total',
        'is_test', 'origin', 'device_id', 'client_created_at', 'server_created_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_test' => 'boolean', 'client_created_at' => 'datetime', 'server_created_at' => 'datetime'];
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @return HasMany<SaleDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(SaleDiscount::class);
    }
}
