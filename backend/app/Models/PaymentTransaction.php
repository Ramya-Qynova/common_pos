<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'sale_payment_id', 'provider_reference', 'status'];
}
