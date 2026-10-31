<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBranchAccess;
use App\Models\UserRole;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

/**
 * section 9: device registration happens implicitly at login (Phase 1,
 * TenantAuthService::login()); this phase adds the management half -
 * listing and revoking a tenant's registered devices.
 */
test('listing devices shows every device registered for this tenant, and revoking one marks it revoked', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-DEV-1', tenantName: 'Device Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );

    test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
        'device_uuid' => 'DEV-LIST-1', 'device_name' => 'Counter Tablet',
    ]);
    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data'); // the owner's own session (no device) for making the management calls
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    $list = test()->withHeaders($auth)->getJson('/api/v1/devices');
    expect(collect($list->json('data'))->pluck('uuid'))->toContain('DEV-LIST-1');

    $device = Device::withoutTenantScope()->where('tenant_id', $tenant->id)->where('uuid', 'DEV-LIST-1')->first();
    $revoke = test()->withHeaders($auth)->postJson("/api/v1/devices/{$device->id}/revoke");

    $revoke->assertStatus(200);
    expect($revoke->json('data.is_revoked'))->toBeTrue();
    expect(Device::withoutTenantScope()->find($device->id)->is_revoked)->toBeTrue();
});

test('a user without core.users.manage cannot revoke a device', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-DEV-2', tenantName: 'Device Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password', 'device_uuid' => 'DEV-LIST-2',
    ]);
    $device = Device::withoutTenantScope()->where('tenant_id', $tenant->id)->where('uuid', 'DEV-LIST-2')->first();

    $cashierRole = Role::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'cashier')->first();
    $cashier = User::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'username' => 'cashier1',
        'email' => 'cashier1@example.test', 'password' => 'cashier-pass', 'is_active' => true,
    ]);
    UserRole::create(['user_id' => $cashier->id, 'role_id' => $cashierRole->id]);
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    UserBranchAccess::create(['user_id' => $cashier->id, 'branch_id' => $branch->id]);

    $cashierLogin = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'cashier1', 'password' => 'cashier-pass',
    ])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$cashierLogin['access_token'])->postJson("/api/v1/devices/{$device->id}/revoke");

    $response->assertStatus(403);
    expect(Device::withoutTenantScope()->find($device->id)->is_revoked)->toBeFalse();
});
