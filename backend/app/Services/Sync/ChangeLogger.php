<?php

namespace App\Services\Sync;

use App\Models\ChangeLogEntry;

/**
 * section 9 "Snapshot and pull": `GET /sync/pull?since=version` reads this
 * table for an incremental diff. Mirrors AuditLogger's call shape - write
 * inside the same transaction as the business change it documents (G4).
 * Deliberately scoped to the entities the offline snapshot stores actually
 * need (products, prices) rather than retrofitted onto every mutable
 * table - see docs/DECISIONS.md.
 */
class ChangeLogger
{
    public static function log(int $tenantId, string $entityType, int $entityId, string $action, array $data): ChangeLogEntry
    {
        return ChangeLogEntry::create([
            'tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'action' => $action, 'data' => $data, 'created_at' => now(),
        ]);
    }
}
