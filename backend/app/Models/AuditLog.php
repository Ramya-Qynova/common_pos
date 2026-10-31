<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (G5, G9). Written in the same DB transaction as the write it
 * documents (G4) - see AuditLogger service. No tenant global scope for the
 * same reason as SecurityEvent above.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'platform_user_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'correlation_id', 'created_at'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }
}
