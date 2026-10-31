<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentAllocation extends Model
{
    protected $fillable = ['customer_payment_id', 'customer_ledger_id', 'amount'];
}
