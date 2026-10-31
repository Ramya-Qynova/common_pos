<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'uuid', 'supplier_id', 'amount', 'payment_method_id', 'reference', 'paid_by', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }
}
