<?php

namespace App\Services\Catalogue;

use App\Models\ImportJob;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Master data": "Bulk import is a background job: validate each
 * row, save valid rows, return a downloadable error report. Nothing
 * silently skipped." Each row is validated and committed independently -
 * one bad row never blocks or rolls back the good ones around it, and
 * every rejected row is recorded with the reason it was rejected, not just
 * a total count.
 */
class ProductImportService
{
    /**
     * @param  array<int,array{sku?:string,name?:string,base_unit_code?:string}>  $rows
     */
    public function import(int $tenantId, array $rows, ?int $userId = null): ImportJob
    {
        $errors = [];
        $accepted = 0;
        $seenSkus = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $error = $this->validateRow($tenantId, $row, $seenSkus);

            if ($error) {
                $errors[] = ['row' => $rowNumber, 'message' => $error];

                continue;
            }

            DB::transaction(function () use ($tenantId, $row) {
                $unit = Unit::withoutTenantScope()->where('tenant_id', $tenantId)->where('code', $row['base_unit_code'])->first();

                Product::withoutTenantScope()->create([
                    'tenant_id' => $tenantId,
                    'uuid' => (string) Str::uuid(),
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'base_unit_id' => $unit->id,
                    'is_active' => true,
                ]);
            });

            $seenSkus[$row['sku']] = true;
            $accepted++;
        }

        return ImportJob::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'uuid' => (string) Str::uuid(),
            'type' => 'products',
            'status' => 'completed',
            'total_rows' => count($rows),
            'accepted_rows' => $accepted,
            'rejected_rows' => count($errors),
            'errors' => $errors,
            'requested_by' => $userId,
            'completed_at' => now(),
        ]);
    }

    private function validateRow(int $tenantId, array $row, array $seenSkus): ?string
    {
        if (empty($row['sku'])) {
            return 'sku is required';
        }
        if (empty($row['name'])) {
            return 'name is required';
        }
        if (empty($row['base_unit_code'])) {
            return 'base_unit_code is required';
        }
        if (isset($seenSkus[$row['sku']])) {
            return "sku [{$row['sku']}] is duplicated earlier in this import";
        }
        if (Product::withoutTenantScope()->where('tenant_id', $tenantId)->where('sku', $row['sku'])->exists()) {
            return "sku [{$row['sku']}] already exists";
        }
        if (! Unit::withoutTenantScope()->where('tenant_id', $tenantId)->where('code', $row['base_unit_code'])->exists()) {
            return "base_unit_code [{$row['base_unit_code']}] is not a known unit for this tenant";
        }

        return null;
    }
}
