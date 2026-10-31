<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxGroupItem extends Model
{
    protected $fillable = ['tax_group_id', 'tax_rate_id', 'sort_order'];
}
