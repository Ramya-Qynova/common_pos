<?php

namespace App\Services\Sync;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\SecurityEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * section 9: "On device revocation, wipe local data at next contact."
 * Revoking a device does NOT revoke its already-issued tokens outright -
 * POST /sync/push deliberately still accepts that device's PRE-revocation
 * operations (section 13.2), so the token must keep working long enough
 * for one final flush. "Next contact" is that same sync call: its
 * response always reports the device's current revoked status, which is
 * what the client acts on to wipe (see docs/DECISIONS.md for the
 * narrower, token-preserving scope this takes instead of a hard
 * block-everything-immediately revocation).
 */
class DeviceService
{
    /**
     * @return Collection<int,Device>
     */
    public function listFor(int $tenantId): Collection
    {
        return Device::withoutTenantScope()->where('tenant_id', $tenantId)->get();
    }

    public function revoke(int $tenantId, int $deviceId, int $revokedByUserId): Device
    {
        $device = Device::withoutTenantScope()->where('tenant_id', $tenantId)->find($deviceId);
        if (! $device) {
            throw new ApiException('NOT_FOUND', 'Device not found.', 404);
        }

        return DB::transaction(function () use ($tenantId, $device, $revokedByUserId) {
            $device->is_revoked = true;
            $device->revoked_at = now();
            $device->save();

            SecurityEvent::create([
                'tenant_id' => $tenantId, 'user_id' => $revokedByUserId, 'event_type' => 'device_revoked',
                'created_at' => now(),
            ]);
            AuditLogger::log($tenantId, $revokedByUserId, 'device.revoked', 'Device', $device->id, null, ['uuid' => $device->uuid]);

            return $device;
        });
    }

    public function status(int $tenantId, string $deviceUuid): ?Device
    {
        return Device::withoutTenantScope()->where('tenant_id', $tenantId)->where('uuid', $deviceUuid)->first();
    }
}
