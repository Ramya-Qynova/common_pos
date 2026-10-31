<?php

use App\Models\Branch;
use App\Models\Register;
use App\Models\Role;
use App\Models\TenantModule;
use App\Models\TenantSubscription;
use App\Models\TenantTemplateSnapshot;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Onboarding\OnboardingService;

test('onboarding creates a tenant with subscription, modules, snapshot, roles, org structure, and owner in one call', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-001',
        tenantName: 'Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner1',
        ownerPassword: 'secret-password',
        ownerEmail: 'owner@example.test',
    );

    expect($tenant->status)->toBe('setup');

    expect(TenantSubscription::withoutTenantScope()->where('tenant_id', $tenant->id)->exists())->toBeTrue();

    $enabledModules = TenantModule::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('module_code');
    expect($enabledModules)->toContain('pos', 'catalog', 'inventory');

    expect(TenantTemplateSnapshot::withoutTenantScope()->where('tenant_id', $tenant->id)->exists())->toBeTrue();

    $roles = Role::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('code');
    expect($roles)->toContain('owner', 'admin', 'cashier');

    $ownerRole = Role::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'owner')->first();
    expect($ownerRole->permissionCodes())->not->toBeEmpty();

    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    expect($branch)->not->toBeNull();
    expect(Warehouse::withoutTenantScope()->where('branch_id', $branch->id)->exists())->toBeTrue();
    expect(Register::withoutTenantScope()->where('branch_id', $branch->id)->exists())->toBeTrue();

    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->where('username', 'owner1')->first();
    expect($owner)->not->toBeNull();
    expect($owner->hasBranchAccess($branch->id))->toBeTrue();
});
