<?php

use App\Models\Branch;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBranchAccess;
use App\Models\UserRole;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

/**
 * `ShiftService` has had full coverage since Phase 3 - this is its first
 * HTTP route, needed by the POS screen to open/close a register's shift.
 */
test('GET /shifts/current is null before a shift is opened, then reflects the opened shift, then null again after closing', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-SHIFT-1', tenantName: 'Shift Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    $before = test()->withHeaders($auth)->getJson('/api/v1/shifts/current?register_id='.$register->id);
    expect($before->json('data'))->toBeNull();

    $opened = test()->withHeaders($auth)->postJson('/api/v1/shifts/open', ['register_id' => $register->id, 'opening_cash' => '200.0000']);
    $opened->assertStatus(201);
    $shiftId = $opened->json('data.id');

    $during = test()->withHeaders($auth)->getJson('/api/v1/shifts/current?register_id='.$register->id);
    expect($during->json('data.id'))->toBe($shiftId);

    $closed = test()->withHeaders($auth)->postJson("/api/v1/shifts/{$shiftId}/close", ['counted_cash' => '200.0000']);
    $closed->assertStatus(200);
    expect(bccomp($closed->json('data.cash_difference'), '0.0000', 4))->toBe(0);

    $after = test()->withHeaders($auth)->getJson('/api/v1/shifts/current?register_id='.$register->id);
    expect($after->json('data'))->toBeNull();
});

test('a user without pos.shift.open cannot open a shift', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-SHIFT-2', tenantName: 'Shift Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $noPermRole = Role::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'no-perm', 'name' => 'No Permissions', 'is_system' => false]);
    $limited = User::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'username' => 'limited',
        'email' => 'limited@example.test', 'password' => 'limited-pass', 'is_active' => true,
    ]);
    UserRole::create(['user_id' => $limited->id, 'role_id' => $noPermRole->id]);
    UserBranchAccess::create(['user_id' => $limited->id, 'branch_id' => $branch->id]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'limited', 'password' => 'limited-pass'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->postJson('/api/v1/shifts/open', ['register_id' => $register->id, 'opening_cash' => '0.0000']);

    $response->assertStatus(403);
});
