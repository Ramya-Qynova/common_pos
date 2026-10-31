<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\Onboarding\OnboardingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

function onboardForModules(): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-MOD',
        tenantName: 'Module Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
}

test('disabling a module removes its menu entry from GET /tenant/config with no code change', function () {
    $tenant = onboardForModules();

    $login = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    $before = $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->getJson('/api/v1/tenant/config')->assertOk()->json('data');

    expect($before['modules'])->toContain('finance');
    expect(collect($before['menu'])->pluck('code'))->toContain('finance');

    // An admin disabling a module is a DATA change, nothing more.
    TenantModule::withoutTenantScope()->where('tenant_id', $tenant->id)->where('module_code', 'finance')->update(['is_enabled' => false]);

    $after = $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->getJson('/api/v1/tenant/config')->assertOk()->json('data');

    expect($after['modules'])->not->toContain('finance');
    expect(collect($after['menu'])->pluck('code'))->not->toContain('finance');
});

test('EnsureModuleEnabled middleware returns MODULE_DISABLED for a disabled module and passes through an enabled one', function () {
    $tenant = onboardForModules();
    app(TenantContext::class)->setTenant($tenant->id, 1);

    TenantModule::withoutTenantScope()->where('tenant_id', $tenant->id)->where('module_code', 'purchases')->update(['is_enabled' => false]);

    $middleware = new EnsureModuleEnabled;
    $request = Request::create('/test');

    $blocked = $middleware->handle($request, fn () => response('should not reach here'), 'purchases');
    expect($blocked->getStatusCode())->toBe(403);
    expect(json_decode($blocked->getContent(), true)['error']['code'])->toBe('MODULE_DISABLED');

    $allowed = $middleware->handle($request, fn () => response('ok'), 'pos');
    expect($allowed->getContent())->toBe('ok');
});
