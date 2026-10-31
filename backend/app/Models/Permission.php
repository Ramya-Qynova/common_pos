<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Permission codes (G11) - the only thing authorization ever checks.
 * Platform-wide catalogue, not tenant-owned: a code like `sales.create`
 * means the same thing in every tenant.
 */
class Permission extends Model
{
    protected $fillable = ['code', 'module_code', 'description'];
}
