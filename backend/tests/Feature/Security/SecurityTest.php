<?php

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalogue\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Str;

/**
 * section 13.1 "Security": SQL injection, XSS, IDOR, token rotation and
 * replay, rate limiting, lockout, secrets never in responses. Lockout and
 * basic tenant isolation already have dedicated coverage
 * (tests/Feature/Auth/LoginTest.php, tests/Feature/Tenancy/
 * TenantIsolationTest.php) - this file covers the remaining ground. A
 * self-contained onboarding helper is used (not LoginTest.php's own) so
 * this file runs correctly in isolation, not just as part of the full suite.
 */
function onboardSecurityTestTenant(string $shopCode): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'Security Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
}

test('refreshing a token revokes the OLD access token - it cannot be replayed afterward', function () {
    $tenant = onboardSecurityTestTenant('SHOP-SEC-1');
    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $beforeRefresh = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/auth/me');
    $beforeRefresh->assertOk();

    test()->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk();

    $replay = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/auth/me');
    $replay->assertStatus(401);
});

test('the OLD refresh token cannot be reused after it has already been rotated once', function () {
    $tenant = onboardSecurityTestTenant('SHOP-SEC-2');
    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    test()->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertOk();

    $replay = test()->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token']]);
    $replay->assertStatus(401);
});

test('the login endpoint rate-limits repeated attempts', function () {
    // An unknown shop_code every time (section 8 "generic message on bad
    // credentials, no user enumeration") never touches any real user's
    // failed_login_count, so this exercises ONLY the throttle middleware,
    // not the separately-tested account-lockout mechanism.
    for ($i = 0; $i < 20; $i++) {
        test()->postJson('/api/v1/auth/login', ['shop_code' => 'NO-SUCH-SHOP', 'username' => 'nobody', 'password' => 'wrong'])->assertStatus(401);
    }

    $throttled = test()->postJson('/api/v1/auth/login', ['shop_code' => 'NO-SUCH-SHOP', 'username' => 'nobody', 'password' => 'wrong']);
    $throttled->assertStatus(429);
});

test('a login attempt with SQL-injection-shaped input is rejected like any other bad credential, never a server error or a bypass', function () {
    $tenant = onboardSecurityTestTenant('SHOP-SEC-4');

    $response = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => "owner' OR '1'='1", 'password' => "' OR '1'='1",
    ]);

    $response->assertStatus(401); // not 200 (bypass), not 500 (injection broke the query)
});

test('a product name containing script-tag-shaped input round-trips unexecuted, as plain data', function () {
    $tenant = onboardSecurityTestTenant('SHOP-SEC-5');
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);

    $product = app(ProductService::class)->create($tenant->id, [
        'sku' => 'SKU-XSS', 'name' => '<script>alert(1)</script>', 'base_unit_id' => $unit->id,
    ], $owner->id);

    // Stored and returned byte-for-byte as data (a JSON API has no HTML
    // context to inject into) - escaping/encoding is the eventual HTML
    // renderer's job (React escapes by default), never the API's to assume.
    expect($product->refresh()->name)->toBe('<script>alert(1)</script>');
});

test('GET /auth/me never includes the password hash or any token secret', function () {
    $tenant = onboardSecurityTestTenant('SHOP-SEC-6');
    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/auth/me');

    $response->assertOk();
    expect($response->json('data'))->not->toHaveKey('password');
    expect(json_encode($response->json()))->not->toContain('correct-password');
});

test('a tenant cannot read another tenant\'s sale by ID - it is treated as not found, not forbidden (no existence leak)', function () {
    $fixtureA = setUpIdorFixture('SHOP-SEC-7');
    $fixtureB = setUpIdorFixture('SHOP-SEC-8');

    $response = test()->withHeader('Authorization', 'Bearer '.$fixtureB['login']['access_token'])
        ->postJson("/api/v1/sales/{$fixtureA['sale']->id}/cancel", ['reason' => 'IDOR probe']);

    // Sale::withoutTenantScope()->where('tenant_id', $tenantId)->find($id) used throughout
    // this codebase's services (SaleCancellationService et al.) returns nothing for a
    // foreign tenant's ID, surfacing as NOT_FOUND - never leaking that the row exists
    // under a different tenant.
    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('NOT_FOUND');
});

function setUpIdorFixture(string $shopCode): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: $shopCode, tenantName: 'IDOR Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    app(SettingsService::class)->set($tenant->id, 'sales.shift_required', false);
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-IDOR', 'name' => 'IDOR Product', 'base_unit_id' => $unit->id, 'is_active' => true]);
    $priceList = PriceList::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true]);
    ProductPrice::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_key' => 0, 'price_list_id' => $priceList->id, 'unit_id' => $unit->id, 'price' => '10.0000']);
    app(StockService::class)->recordMovement($tenant->id, ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '5.0000', 'source_type' => 'opening_stock']);
    $cash = PaymentMethod::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'cash', 'name' => 'Cash', 'confirmation_mode' => 'instant', 'allows_overpayment' => true]);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $saleResponse = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/sales', [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id, 'price_list_id' => $priceList->id,
            'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '1.0000']],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => '10.0000']],
        ])->assertStatus(201);

    $sale = Sale::withoutTenantScope()->find($saleResponse->json('data.id'));

    return compact('tenant', 'login', 'sale');
}
