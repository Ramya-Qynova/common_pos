<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LookupValue extends Model
{
    protected $fillable = ['lookup_type_id', 'code', 'label', 'sort_order', 'is_active'];
}
