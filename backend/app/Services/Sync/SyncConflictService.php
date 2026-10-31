<?php

namespace App\Services\Sync;

use App\Exceptions\ApiException;
use App\Models\SyncConflict;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;

/**
 * section 13.2: "rejected items stay visible until resolved." Conflicts
 * (STOCK_SHORTFALL/PRICE_MISMATCH/DATA_CHANGED/CLOCK_SKEW) and rejected
 * sync_operations both stay queryable - this service only ever moves a
 * conflict from open to resolved, never deletes one.
 */
class SyncConflictService
{
    /**
     * @return Collection<int,SyncConflict>
     */
    public function listOpen(int $tenantId): Collection
    {
        return SyncConflict::withoutTenantScope()->where('tenant_id', $tenantId)->where('status', 'open')->get();
    }

    public function resolve(int $tenantId, int $conflictId, int $userId): SyncConflict
    {
        $conflict = SyncConflict::withoutTenantScope()->where('tenant_id', $tenantId)->find($conflictId);
        if (! $conflict) {
            throw new ApiException('NOT_FOUND', 'Sync conflict not found.', 404);
        }

        $conflict->status = 'resolved';
        $conflict->resolved_by = $userId;
        $conflict->resolved_at = now();
        $conflict->save();

        AuditLogger::log($tenantId, $userId, 'sync_conflict.resolved', 'SyncConflict', $conflict->id, null, ['type' => $conflict->type]);

        return $conflict;
    }
}
