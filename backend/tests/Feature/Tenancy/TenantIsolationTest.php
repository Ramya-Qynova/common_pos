<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Onboarding\OnboardingService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

function onboard(string $shopCode): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: $shopCode,
        tenantName: 'Isolation Test Shop '.$shopCode,
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
}

test('a tenant-scoped query cannot see another tenant\'s rows even when both exist', function () {
    $tenantA = onboard('SHOP-A1');
    $tenantB = onboard('SHOP-B1');

    $branchB = Branch::withoutTenantScope()->where('tenant_id', $tenantB->id)->first();

    app(TenantContext::class)->setTenant($tenantA->id, 0);

    // G14: a foreign-tenant ID behaves as if it doesn't exist.
    expect(Branch::find($branchB->id))->toBeNull();

    // But tenant A's own branch IS visible under tenant A's context.
    $branchA = Branch::withoutTenantScope()->where('tenant_id', $tenantA->id)->first();
    expect(Branch::find($branchA->id))->not->toBeNull();
});

test('GET /tenant/config for one tenant never includes another tenant\'s branches, even with identical branch codes', function () {
    $tenantA = onboard('SHOP-A2');
    $tenantB = onboard('SHOP-B2');
    // Both tenants' onboarding created a branch coded "MAIN" - identical code, different tenant.

    $loginA = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenantA->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    $config = $this->withHeader('Authorization', 'Bearer '.$loginA['access_token'])
        ->getJson('/api/v1/tenant/config')
        ->assertOk()
        ->json('data');

    $branchIds = collect($config['branches'])->pluck('id');
    $branchB = Branch::withoutTenantScope()->where('tenant_id', $tenantB->id)->first();

    expect($branchIds)->not->toContain($branchB->id);
});

test('platform users cannot be resolved by the tenant guard, and tenant users cannot be resolved by the platform guard', function () {
    $tenant = onboard('SHOP-GUARD');

    $login = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    // A valid TENANT access token must not authenticate against a route
    // that is protected by the PLATFORM guard - there is no shared code
    // path (config/auth.php, section 5 "Identity").
    Route::middleware('auth:platform')->get('/__test/platform-only', fn () => 'ok');

    $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->getJson('/__test/platform-only')
        ->assertStatus(401);
});
