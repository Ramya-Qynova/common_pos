<?php

namespace App\Services\Onboarding;

use App\Exceptions\ApiException;
use App\Models\Category;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TenantTemplateSnapshot;
use App\Models\Unit;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 12 Phase 6 "template snapshot + update preview". One mechanism
 * serves two real needs identically: (1) a tenant pulling in catalogue
 * data its OWN template defines but onboarding didn't create yet (modules
 * + roles only - see docs/DECISIONS.md), and (2) previewing/applying what
 * a NEWER published template version adds. Both are just "what does the
 * latest published version of my template have that I don't yet" - always
 * additive (firstOrCreate throughout), never touches or removes anything
 * the tenant already has, however it got there.
 */
class TemplateUpdateService
{
    public function __construct(private SettingsService $settings) {}

    public function preview(int $tenantId): array
    {
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        if (! $tenant) {
            throw new ApiException('NOT_FOUND', 'Tenant not found.', 404);
        }

        $snapshot = TenantTemplateSnapshot::withoutTenantScope()->where('tenant_id', $tenantId)->first();
        $latestVersion = Template::find($tenant->template_id)->latestPublished();
        $target = $latestVersion->data;

        $existingModules = TenantModule::withoutTenantScope()->where('tenant_id', $tenantId)->pluck('module_code')->all();
        $existingUnitCodes = Unit::withoutTenantScope()->where('tenant_id', $tenantId)->pluck('code')->all();
        $existingCategoryCodes = Category::withoutTenantScope()->where('tenant_id', $tenantId)->pluck('code')->all();
        $existingLookupCodes = LookupType::withoutTenantScope()->where('tenant_id', $tenantId)->pluck('code')->all();
        $existingRoleCodes = Role::withoutTenantScope()->where('tenant_id', $tenantId)->pluck('code')->all();
        $existingSettingKeys = TenantSetting::withoutTenantScope()->where('tenant_id', $tenantId)->whereNull('branch_id')->pluck('key')->all();

        return [
            'current_version' => $snapshot?->template_version,
            'target_version' => $latestVersion->version,
            'added_modules' => array_values(array_diff($target['modules'] ?? [], $existingModules)),
            'added_units' => array_values(array_filter($target['units'] ?? [], fn ($u) => ! in_array($u['code'], $existingUnitCodes, true))),
            'added_categories' => array_values(array_filter($target['categories'] ?? [], fn ($c) => ! in_array($c['code'], $existingCategoryCodes, true))),
            'added_lookups' => array_values(array_filter($target['lookups'] ?? [], fn ($l) => ! in_array($l['code'], $existingLookupCodes, true))),
            'added_roles' => array_values(array_filter($target['default_roles'] ?? [], fn ($r) => ! in_array($r['code'], $existingRoleCodes, true))),
            'added_settings' => array_diff_key($target['default_settings'] ?? [], array_flip($existingSettingKeys)),
        ];
    }

    public function apply(int $tenantId, int $userId): array
    {
        $diff = $this->preview($tenantId);

        return DB::transaction(function () use ($tenantId, $userId, $diff) {
            foreach ($diff['added_modules'] as $code) {
                TenantModule::withoutTenantScope()->firstOrCreate(['tenant_id' => $tenantId, 'module_code' => $code], ['is_enabled' => true]);
            }

            foreach ($diff['added_units'] as $unit) {
                Unit::withoutTenantScope()->firstOrCreate(['tenant_id' => $tenantId, 'code' => $unit['code']], ['name' => $unit['name']]);
            }

            foreach ($diff['added_categories'] as $category) {
                Category::withoutTenantScope()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $category['code']],
                    ['uuid' => (string) Str::uuid(), 'name' => $category['name'], 'is_active' => true],
                );
            }

            foreach ($diff['added_lookups'] as $lookup) {
                $lookupType = LookupType::withoutTenantScope()->firstOrCreate(['tenant_id' => $tenantId, 'code' => $lookup['code']], ['name' => $lookup['name']]);
                foreach (array_values($lookup['values'] ?? []) as $index => $value) {
                    LookupValue::firstOrCreate(
                        ['lookup_type_id' => $lookupType->id, 'code' => $value['code']],
                        ['label' => $value['label'], 'sort_order' => $index, 'is_active' => true],
                    );
                }
            }

            foreach ($diff['added_roles'] as $roleDef) {
                $role = Role::withoutTenantScope()->create([
                    'tenant_id' => $tenantId, 'code' => $roleDef['code'], 'name' => $roleDef['name'], 'is_system' => $roleDef['is_system'] ?? true,
                ]);
                $permissionCodes = $roleDef['permissions'] === ['*'] ? Permission::pluck('code')->all() : $roleDef['permissions'];
                foreach ($permissionCodes as $permissionCode) {
                    RolePermission::create(['role_id' => $role->id, 'permission_code' => $permissionCode]);
                }
            }

            foreach ($diff['added_settings'] as $key => $value) {
                $this->settings->set($tenantId, $key, $value);
            }

            $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
            $latestVersion = Template::find($tenant->template_id)->latestPublished();
            TenantTemplateSnapshot::withoutTenantScope()->where('tenant_id', $tenantId)->update([
                'template_version' => $latestVersion->version, 'data' => $latestVersion->data, 'applied_at' => now(),
            ]);

            AuditLogger::log($tenantId, $userId, 'template.applied', 'TenantTemplateSnapshot', null, null, [
                'target_version' => $diff['target_version'],
                'added_modules' => $diff['added_modules'],
                'added_units' => array_column($diff['added_units'], 'code'),
                'added_categories' => array_column($diff['added_categories'], 'code'),
                'added_lookups' => array_column($diff['added_lookups'], 'code'),
                'added_roles' => array_column($diff['added_roles'], 'code'),
                'added_settings' => array_keys($diff['added_settings']),
            ]);

            return $diff;
        });
    }
}
