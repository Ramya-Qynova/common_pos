<?php

namespace App\Services\Finance;

use App\Exceptions\ApiException;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Shifts and cash": one open shift per register (enforced both
 * here and by cashier_shifts.open_register_marker's unique index);
 * "Expected cash = opening cash + cash received (net of change) - cash
 * refunds - cash expenses + cash in - cash out" - computed as a SUM over
 * typed cash_movements rows, never a hand-maintained running total.
 */
class ShiftService
{
    public function open(int $tenantId, int $registerId, string $openingCash, int $userId): CashierShift
    {
        return DB::transaction(function () use ($tenantId, $registerId, $openingCash, $userId) {
            $alreadyOpen = CashierShift::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('register_id', $registerId)->where('status', 'open')->exists();

            if ($alreadyOpen) {
                throw new ApiException('CONFLICT', 'A shift is already open on this register.', 409);
            }

            $shift = CashierShift::withoutTenantScope()->create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'register_id' => $registerId,
                'opened_by' => $userId, 'opening_cash' => $openingCash, 'status' => 'open',
                'open_register_marker' => $registerId, 'opened_at' => now(),
            ]);

            CashMovement::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'cashier_shift_id' => $shift->id,
                'type' => 'opening', 'amount' => $openingCash, 'user_id' => $userId,
                'occurred_at' => now(), 'created_at' => now(),
            ]);

            AuditLogger::log($tenantId, $userId, 'shift.opened', 'CashierShift', $shift->id, null, ['register_id' => $registerId, 'opening_cash' => $openingCash]);

            return $shift;
        });
    }

    public function recordCashMovement(int $tenantId, int $shiftId, string $type, string $amount, ?string $reason = null, ?int $userId = null, ?string $sourceType = null, ?int $sourceId = null): CashMovement
    {
        return DB::transaction(function () use ($tenantId, $shiftId, $type, $amount, $reason, $userId, $sourceType, $sourceId) {
            $shift = CashierShift::withoutTenantScope()->where('id', $shiftId)->lockForUpdate()->first();

            if (! $shift || ! $shift->isOpen()) {
                throw new ApiException('CONFLICT', 'This shift is not open.', 409);
            }

            return CashMovement::create([
                'tenant_id' => $tenantId, 'uuid' => (string) Str::uuid(), 'cashier_shift_id' => $shiftId,
                'type' => $type, 'amount' => $amount, 'reason' => $reason, 'source_type' => $sourceType,
                'source_id' => $sourceId, 'user_id' => $userId, 'occurred_at' => now(), 'created_at' => now(),
            ]);
        });
    }

    public function close(int $tenantId, int $shiftId, string $countedCash, int $userId): CashierShift
    {
        return DB::transaction(function () use ($tenantId, $shiftId, $countedCash, $userId) {
            $shift = CashierShift::withoutTenantScope()->where('id', $shiftId)->lockForUpdate()->first();

            if (! $shift || ! $shift->isOpen()) {
                throw new ApiException('CONFLICT', 'This shift is not open.', 409);
            }

            $sums = CashMovement::where('cashier_shift_id', $shift->id)
                ->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');

            $expected = $shift->opening_cash;
            $expected = bcadd($expected, (string) ($sums['cash_sale'] ?? '0'), 4);
            $expected = bcsub($expected, (string) ($sums['cash_refund'] ?? '0'), 4);
            $expected = bcsub($expected, (string) ($sums['expense'] ?? '0'), 4);
            $expected = bcadd($expected, (string) ($sums['cash_in'] ?? '0'), 4);
            $expected = bcsub($expected, (string) ($sums['cash_out'] ?? '0'), 4);

            $shift->status = 'closed';
            $shift->open_register_marker = null;
            $shift->closing_cash_counted = $countedCash;
            $shift->expected_cash = $expected;
            $shift->cash_difference = bcsub($countedCash, $expected, 4);
            $shift->closed_by = $userId;
            $shift->closed_at = now();
            $shift->save();

            AuditLogger::log($tenantId, $userId, 'shift.closed', 'CashierShift', $shift->id, null, [
                'expected_cash' => $expected, 'counted_cash' => $countedCash, 'difference' => $shift->cash_difference,
            ]);

            return $shift;
        });
    }
}
