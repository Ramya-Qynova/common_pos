<?php

use App\Http\Middleware\EnsurePermission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function onboardForPermissions(): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PERM',
        tenantName: 'Permission Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
}

test('the owner role (seeded with *) resolves to every permission code in the catalogue', function () {
    $tenant = onboardForPermissions();

    $login = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    $me = $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->getJson('/api/v1/auth/me')->assertOk()->json('data');

    expect($me['permissions'])->toContain('core.users.manage', 'pos.sales.create', 'finance.reopen');
});

test('a custom role behaves exactly like a seeded standard role (section 13.1)', function () {
    $tenant = onboardForPermissions();

    // Tenant admin creates a brand new, non-system role with exactly one permission.
    $customRole = Role::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'stock_checker', 'name' => 'Stock Checker', 'is_system' => false,
    ]);
    RolePermission::create(['role_id' => $customRole->id, 'permission_code' => 'inventory.view']);

    $user = User::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'username' => 'checker',
        'email' => 'checker@example.test', 'password' => 'checker-pass', 'is_active' => true,
    ]);
    UserRole::create(['user_id' => $user->id, 'role_id' => $customRole->id]);

    expect($user->hasPermission('inventory.view'))->toBeTrue();
    expect($user->hasPermission('core.users.manage'))->toBeFalse();
});

test('EnsurePermission middleware blocks a request lacking the required permission code and allows one that has it', function () {
    $tenant = onboardForPermissions();

    $limitedRole = Role::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'code' => 'limited', 'name' => 'Limited', 'is_system' => false,
    ]);
    RolePermission::create(['role_id' => $limitedRole->id, 'permission_code' => 'inventory.view']);

    $user = User::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'username' => 'limiteduser',
        'email' => 'limited@example.test', 'password' => 'pass', 'is_active' => true,
    ]);
    UserRole::create(['user_id' => $user->id, 'role_id' => $limitedRole->id]);

    $middleware = new EnsurePermission;
    $request = Request::create('/test');
    $request->setUserResolver(fn ($guard = null) => $user);

    $blocked = $middleware->handle($request, fn () => 'should not reach here', 'core.users.manage');
    expect($blocked->getStatusCode())->toBe(403);

    $allowed = $middleware->handle($request, fn () => response('ok'), 'inventory.view');
    expect($allowed->getContent())->toBe('ok');
});
