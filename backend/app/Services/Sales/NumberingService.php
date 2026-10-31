<?php

namespace App\Services\Sales;

use App\Models\NumberingSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * section 7 "invoices": "generated under a locked sequence row." Locking
 * the numbering_sequences row (not a global counter) means two sales in
 * different series/fiscal years never contend, while two sales in the
 * SAME series serialize correctly even under real concurrency - the lock
 * is held until the caller's outer transaction commits (Laravel's nested
 * "transactions" are savepoints; the underlying row lock survives a
 * savepoint release and is only freed at the real outer COMMIT/ROLLBACK).
 */
class NumberingService
{
    public function next(int $tenantId, string $series, ?string $fiscalYear = null, ?int $deviceId = null): array
    {
        return DB::transaction(function () use ($tenantId, $series, $fiscalYear, $deviceId) {
            $fiscalYearKey = $fiscalYear ?? '';
            $deviceKey = $deviceId ?? 0;

            $query = fn () => NumberingSequence::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('series', $series)
                ->where('fiscal_year_key', $fiscalYearKey)->where('device_key', $deviceKey);

            $sequence = $query()->lockForUpdate()->first();

            if (! $sequence) {
                try {
                    $sequence = NumberingSequence::withoutTenantScope()->create([
                        'tenant_id' => $tenantId, 'series' => $series, 'fiscal_year' => $fiscalYear,
                        'fiscal_year_key' => $fiscalYearKey, 'device_id' => $deviceId, 'device_key' => $deviceKey,
                        'next_number' => 1,
                    ]);
                } catch (QueryException $e) {
                    $sequence = $query()->lockForUpdate()->first();
                    if (! $sequence) {
                        throw $e;
                    }
                }
            }

            $number = $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return ['number' => $number, 'prefix' => $sequence->prefix, 'series' => $series, 'fiscal_year' => $fiscalYear];
        });
    }
}
