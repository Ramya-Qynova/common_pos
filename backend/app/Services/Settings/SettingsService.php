<?php

namespace App\Services\Settings;

use App\Models\PlanSetting;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Cache;

/**
 * docs/MASTER_PROMPT.md section 4 "Settings resolution":
 * branch override -> tenant value -> plan default -> platform default.
 * Cached with VERSION-based invalidation (not key deletion): every read
 * for a tenant is cached under the tenant's current version number, and
 * writing a setting bumps that version - so the next read simply misses
 * the old cache entries rather than needing to know every key to evict.
 */
class SettingsService
{
    private const TTL_SECONDS = 3600;

    public function get(string $key, ?int $tenantId = null, ?int $branchId = null): mixed
    {
        if ($tenantId === null) {
            // No tenant context (e.g. platform console) - resolve the platform default only.
            return $this->platformDefault($key);
        }

        $version = $this->currentVersion($tenantId);
        $cacheKey = "settings:v{$version}:t{$tenantId}:b".($branchId ?? 'null').":{$key}";

        return Cache::remember($cacheKey, self::TTL_SECONDS, fn () => $this->resolve($key, $tenantId, $branchId));
    }

    /**
     * Writes always target the tenant layer (branch override when $branchId
     * is given, otherwise the tenant-wide value) - plan/platform layers are
     * platform-managed, not written through this method.
     */
    public function set(int $tenantId, string $key, mixed $value, ?int $branchId = null): void
    {
        TenantSetting::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenantId, 'branch_id' => $branchId, 'key' => $key],
            ['value' => $value],
        );

        $this->bumpVersion($tenantId);
    }

    private function resolve(string $key, int $tenantId, ?int $branchId): mixed
    {
        if ($branchId !== null) {
            $branchRow = TenantSetting::withoutTenantScope()
                ->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('key', $key)->first();
            if ($branchRow) {
                return $branchRow->value;
            }
        }

        $tenantRow = TenantSetting::withoutTenantScope()
            ->where('tenant_id', $tenantId)->whereNull('branch_id')->where('key', $key)->first();
        if ($tenantRow) {
            return $tenantRow->value;
        }

        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if ($tenant?->plan_id) {
            $planRow = PlanSetting::where('plan_id', $tenant->plan_id)->where('key', $key)->first();
            if ($planRow) {
                return $planRow->value;
            }
        }

        return $this->platformDefault($key);
    }

    private function platformDefault(string $key): mixed
    {
        $row = PlatformSetting::where('key', $key)->first();

        if (! $row) {
            throw new \RuntimeException("Unknown setting key: {$key}");
        }

        return $row->value;
    }

    private function currentVersion(int $tenantId): int
    {
        return (int) Cache::get("settings:version:t{$tenantId}", 1);
    }

    private function bumpVersion(int $tenantId): void
    {
        Cache::put("settings:version:t{$tenantId}", $this->currentVersion($tenantId) + 1, now()->addDays(30));
    }
}
