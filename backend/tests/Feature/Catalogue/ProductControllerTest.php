<?php

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

/**
 * section 10 "Catalogue" / section 11 "always server-side paginated" - the
 * POS screen's product search. `ProductService`/the `products` table have
 * existed since Phase 2; this is the first HTTP route for reading them.
 */
test('GET /products searches by name/SKU, paginates, and includes current price + stock when asked', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PROD-1', tenantName: 'Product Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $priceList = PriceList::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true]);

    $apple = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-APPLE', 'name' => 'Apple', 'base_unit_id' => $unit->id, 'is_active' => true]);
    Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-BANANA', 'name' => 'Banana', 'base_unit_id' => $unit->id, 'is_active' => true]);

    ProductPrice::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'product_id' => $apple->id, 'variant_key' => 0, 'price_list_id' => $priceList->id, 'unit_id' => $unit->id, 'price' => '30.0000']);
    app(StockService::class)->recordMovement($tenant->id, ['warehouse_id' => $warehouse->id, 'product_id' => $apple->id, 'direction' => 'in', 'quantity' => '15.0000', 'unit_cost' => '20.0000', 'source_type' => 'opening_stock']);

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    $response = test()->withHeaders($auth)->getJson('/api/v1/products?'.http_build_query([
        'search' => 'apple', 'price_list_id' => $priceList->id, 'warehouse_id' => $warehouse->id,
    ]));

    $response->assertOk();
    $items = $response->json('data');
    expect($items)->toHaveCount(1);
    expect($items[0]['sku'])->toBe('SKU-APPLE');
    expect(bccomp($items[0]['prices'][0]['price'], '30.0000', 4))->toBe(0);
    expect(bccomp($items[0]['stock_quantity'], '15.0000', 4))->toBe(0);
    expect($response->json('meta.total'))->toBe(1);
});

test('a product search returns nothing for another tenant\'s product (tenant isolation)', function () {
    $tenantA = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PROD-2', tenantName: 'A', planCode: 'standard', templateCode: 'general_retail',
        ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $tenantB = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PROD-3', tenantName: 'B', planCode: 'standard', templateCode: 'general_retail',
        ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $unitB = Unit::withoutTenantScope()->create(['tenant_id' => $tenantB->id, 'code' => 'pcs', 'name' => 'Pieces']);
    Product::withoutTenantScope()->create(['tenant_id' => $tenantB->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-ONLY-B', 'name' => 'Only In B', 'base_unit_id' => $unitB->id, 'is_active' => true]);

    $loginA = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenantA->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$loginA['access_token'])->getJson('/api/v1/products?search=Only');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});
