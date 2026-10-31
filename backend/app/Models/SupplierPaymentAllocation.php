<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPaymentAllocation extends Model
{
    protected $fillable = ['supplier_payment_id', 'supplier_ledger_id', 'amount'];
}
