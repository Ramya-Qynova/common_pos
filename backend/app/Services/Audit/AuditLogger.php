<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * G4: callers write this inside the same DB transaction as the business
 * write it documents. G5/G9/G15: append-only, never updated, never
 * carries secret values in `before`/`after` - callers are responsible for
 * redacting before passing data in.
 */
class AuditLogger
{
    public static function log(
        ?int $tenantId,
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?int $platformUserId = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'platform_user_id' => $platformUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'correlation_id' => request()->header('X-Correlation-ID') ?? (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }
}
