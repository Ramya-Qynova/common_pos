<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CustomFieldIndexEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'custom_field_index';

    protected $fillable = ['tenant_id', 'entity_type', 'entity_id', 'field_code', 'value_text', 'value_number'];
}
