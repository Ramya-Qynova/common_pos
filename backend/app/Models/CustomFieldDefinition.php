<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CustomFieldDefinition extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'entity_type', 'code', 'label', 'type', 'required',
        'default_value', 'validation', 'visible_when', 'searchable',
        'show_in_list', 'show_on_receipt', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'default_value' => 'array',
            'validation' => 'array',
            'visible_when' => 'array',
            'searchable' => 'boolean',
            'show_in_list' => 'boolean',
            'show_on_receipt' => 'boolean',
        ];
    }
}
