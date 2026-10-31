<?php

namespace App\Services\Onboarding;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Register;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\TenantSubscription;
use App\Models\TenantTemplateSnapshot;
use App\Models\User;
use App\Models\UserBranchAccess;
use App\Models\UserRole;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * section 8 "Onboarding": "platform admin creates the tenant (draft) with
 * plan, template and owner in ONE transaction (tenant, subscription,
 * modules, owner, default roles/permissions, first branch/warehouse/
 * register, default settings, audit)". The template is copied into
 * tenant_template_snapshot, never referenced live (section 6 "Templates").
 */
class OnboardingService
{
    public function createTenant(string $shopCode, string $tenantName, string $planCode, string $templateCode, string $ownerUsername, string $ownerPassword, string $ownerEmail): Tenant
    {
        return DB::transaction(function () use ($shopCode, $tenantName, $planCode, $templateCode, $ownerUsername, $ownerPassword, $ownerEmail) {
            $plan = Plan::where('code', $planCode)->firstOrFail();
            $template = Template::where('code', $templateCode)->firstOrFail();
            $templateVersion = $template->latestPublished();
            if (! $templateVersion) {
                throw new \RuntimeException("Template [{$templateCode}] has no published version.");
            }

            $tenant = Tenant::create([
                'uuid' => (string) Str::uuid(),
                'shop_code' => $shopCode,
                'name' => $tenantName,
                'status' => 'draft',
                'plan_id' => $plan->id,
                'template_id' => $template->id,
            ]);

            TenantSubscription::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);

            foreach ($templateVersion->data['modules'] ?? [] as $moduleCode) {
                TenantModule::withoutTenantScope()->create([
                    'tenant_id' => $tenant->id,
                    'module_code' => $moduleCode,
                    'is_enabled' => true,
                ]);
            }

            TenantTemplateSnapshot::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'template_id' => $template->id,
                'template_version' => $templateVersion->version,
                'data' => $templateVersion->data,
                'applied_at' => now(),
            ]);

            /** @var array<string,int> $roleIdByCode */
            $roleIdByCode = [];
            foreach ($templateVersion->data['default_roles'] ?? [] as $roleDef) {
                $role = Role::withoutTenantScope()->create([
                    'tenant_id' => $tenant->id,
                    'code' => $roleDef['code'],
                    'name' => $roleDef['name'],
                    'is_system' => $roleDef['is_system'] ?? true,
                ]);
                $roleIdByCode[$roleDef['code']] = $role->id;

                $permissionCodes = $roleDef['permissions'] === ['*']
                    ? Permission::pluck('code')->all()
                    : $roleDef['permissions'];

                foreach ($permissionCodes as $permissionCode) {
                    RolePermission::create(['role_id' => $role->id, 'permission_code' => $permissionCode]);
                }
            }

            $branch = Branch::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'uuid' => (string) Str::uuid(),
                'code' => 'MAIN',
                'name' => 'Main Branch',
                'timezone' => 'Asia/Kolkata',
                'is_active' => true,
            ]);

            $warehouse = Warehouse::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'uuid' => (string) Str::uuid(),
                'code' => 'MAIN-WH',
                'name' => 'Main Warehouse',
                'is_default_sales' => true,
                'is_active' => true,
            ]);

            Register::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'uuid' => (string) Str::uuid(),
                'code' => 'REG-1',
                'name' => 'Register 1',
                'is_active' => true,
            ]);

            $owner = User::withoutTenantScope()->create([
                'tenant_id' => $tenant->id,
                'uuid' => (string) Str::uuid(),
                'username' => $ownerUsername,
                'email' => $ownerEmail,
                'password' => $ownerPassword,
                'is_active' => true,
            ]);

            if (isset($roleIdByCode['owner'])) {
                UserRole::create(['user_id' => $owner->id, 'role_id' => $roleIdByCode['owner']]);
            }

            UserBranchAccess::create(['user_id' => $owner->id, 'branch_id' => $branch->id]);

            $tenant->status = 'setup';
            $tenant->save();

            AuditLogger::log($tenant->id, $owner->id, 'tenant.onboarded', 'Tenant', $tenant->id, null, [
                'shop_code' => $shopCode, 'plan' => $planCode, 'template' => $templateCode,
            ]);

            return $tenant->refresh();
        });
    }
}
