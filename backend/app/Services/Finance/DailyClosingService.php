<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\CashierShift;
use App\Models\DailyClosing;
use App\Models\Register;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "End of day": "all shifts closed and reconciled -> finance
 * daily close with approval (day becomes closed; later corrections are
 * dated today via reversing entries; touching a closed day needs
 * finance.reopen)." Business day (open, closed, reopened) per the
 * section 8 state-machine list.
 */
class DailyClosingService
{
    public function close(int $tenantId, int $branchId, string $businessDate, int $userId): DailyClosing
    {
        return DB::transaction(function () use ($tenantId, $branchId, $businessDate, $userId) {
            $existing = DailyClosing::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('business_date', $businessDate)
                ->lockForUpdate()->first();

            if ($existing && $existing->status === 'closed') {
                throw new ApiException('CONFLICT', 'This business day is already closed.', 409);
            }

            $registerIds = Register::withoutTenantScope()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->pluck('id');
            $openShifts = CashierShift::withoutTenantScope()
                ->where('tenant_id', $tenantId)->whereIn('register_id', $registerIds)->where('status', 'open')->exists();

            if ($openShifts) {
                throw new ApiException('OPEN_SHIFTS_REMAIN', 'All shifts on this branch must be closed before the business day can close.', 409);
            }

            $closing = $existing ?? DailyClosing::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'branch_id' => $branchId,
                'business_date' => $businessDate, 'status' => 'open',
            ]);

            $closing->status = 'closed';
            $closing->closed_by = $userId;
            $closing->closed_at = now();
            $closing->save();

            AuditLogger::log($tenantId, $userId, 'daily_closing.closed', 'DailyClosing', $closing->id, null, ['business_date' => $businessDate]);

            return $closing;
        });
    }

    public function reopen(int $tenantId, int $dailyClosingId, string $reason, int $userId): DailyClosing
    {
        $closing = DailyClosing::withoutTenantScope()->where('tenant_id', $tenantId)->find($dailyClosingId);
        if (! $closing) {
            throw new ApiException('NOT_FOUND', 'Business day closing not found.', 404);
        }
        if ($closing->status !== 'closed') {
            throw new ApiException('CONFLICT', 'Only a closed business day can be reopened.', 409);
        }

        $closing->status = 'reopened';
        $closing->reopened_by = $userId;
        $closing->reopened_at = now();
        $closing->reopen_reason = $reason;
        $closing->save();

        AuditLogger::log($tenantId, $userId, 'daily_closing.reopened', 'DailyClosing', $closing->id, null, ['reason' => $reason]);

        return $closing;
    }
}
