<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array{modules?:array<int,string>,default_roles?:array<int,array{code:string,name:string,is_system?:bool,permissions:array<int,string>}>,default_settings?:array,units?:array,categories?:array,lookups?:array,receipt_layouts?:array,ui_hints?:array} $data
 */
class TemplateVersion extends Model
{
    protected $fillable = ['template_id', 'version', 'data', 'is_published', 'published_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'is_published' => 'boolean', 'published_at' => 'datetime'];
    }
}
