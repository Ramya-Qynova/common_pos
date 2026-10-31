<?php

use App\Models\Branch;
use App\Models\DailyClosing;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBranchAccess;
use App\Models\UserRole;
use App\Services\Finance\DailyClosingService;
use App\Services\Finance\ShiftService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

/**
 * section 8 "End of day": "all shifts closed ... finance daily close with
 * approval ... touching a closed day needs finance.reopen." Business day
 * state machine: open -> closed -> reopened.
 */
test('the business day cannot be closed while a shift is still open on that branch', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-EOD-1', tenantName: 'EOD Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    app(ShiftService::class)->open($tenant->id, $register->id, '0.0000', $owner->id);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->postJson('/api/v1/daily-closings/close', ['branch_id' => $branch->id, 'business_date' => now()->toDateString()]);

    $response->assertStatus(409);
    expect($response->json('error.code'))->toBe('OPEN_SHIFTS_REMAIN');
});

test('closing the business day succeeds once all shifts on the branch are closed', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-EOD-2', tenantName: 'EOD Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $shift = app(ShiftService::class)->open($tenant->id, $register->id, '0.0000', $owner->id);
    app(ShiftService::class)->close($tenant->id, $shift->id, '0.0000', $owner->id);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->postJson('/api/v1/daily-closings/close', ['branch_id' => $branch->id, 'business_date' => now()->toDateString()]);

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('closed');
});

test('a user without finance.reopen cannot reopen a closed business day', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-EOD-3', tenantName: 'EOD Test Shop 3', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $closing = app(DailyClosingService::class)->close($tenant->id, $branch->id, now()->toDateString(), $owner->id);

    $cashierRole = Role::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'cashier')->first();
    $cashier = User::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'username' => 'cashier1',
        'email' => 'cashier1@example.test', 'password' => 'cashier-pass', 'is_active' => true,
    ]);
    UserRole::create(['user_id' => $cashier->id, 'role_id' => $cashierRole->id]);
    UserBranchAccess::create(['user_id' => $cashier->id, 'branch_id' => $branch->id]);

    $cashierLogin = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'cashier1', 'password' => 'cashier-pass'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$cashierLogin['access_token'])
        ->postJson("/api/v1/daily-closings/{$closing->id}/reopen", ['reason' => 'need to post a late expense']);

    $response->assertStatus(403);
    expect(DailyClosing::withoutTenantScope()->find($closing->id)->status)->toBe('closed'); // untouched
});

test('the owner (who has finance.reopen) can reopen a closed business day', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-EOD-4', tenantName: 'EOD Test Shop 4', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $closing = app(DailyClosingService::class)->close($tenant->id, $branch->id, now()->toDateString(), $owner->id);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->postJson("/api/v1/daily-closings/{$closing->id}/reopen", ['reason' => 'late correction needed']);

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe('reopened');
});
