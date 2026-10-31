<?php

namespace App\Services\Hardware;

use App\Models\HardwareProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;

/**
 * section 9 "Adapter interfaces (no vendor hardcoded)": ReceiptPrinter,
 * WeighingScale, BarcodeScanner, etc. are all configured as a `type` +
 * opaque `config` JSON blob here - this service never branches on a
 * vendor or model name; the frontend adapter that actually talks to the
 * device is the only place that interprets `config`.
 */
class HardwareProfileService
{
    public function upsert(int $tenantId, string $code, string $type, array $config, ?int $branchId, ?int $registerId, int $userId): HardwareProfile
    {
        $profile = HardwareProfile::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenantId, 'code' => $code],
            ['branch_id' => $branchId, 'register_id' => $registerId, 'type' => $type, 'config' => $config, 'is_active' => true],
        );

        AuditLogger::log($tenantId, $userId, 'hardware_profile.saved', 'HardwareProfile', $profile->id, null, ['code' => $code, 'type' => $type]);

        return $profile;
    }

    /**
     * @return Collection<int,HardwareProfile>
     */
    public function listFor(int $tenantId, ?int $branchId = null, ?int $registerId = null): Collection
    {
        return HardwareProfile::withoutTenantScope()
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->when($registerId, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('register_id')->orWhere('register_id', $registerId)))
            ->get();
    }
}
