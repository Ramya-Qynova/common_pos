<?php

use App\Models\PriceList;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalogue\PriceListService;
use App\Services\Catalogue\ProductService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

/**
 * section 9 "Snapshot and pull": "the first login downloads a full
 * snapshot... GET /sync/pull?since=version returns authorized changes
 * from change_log in version order."
 */
test('a full pull (no since) returns every active product with its current price and barcodes', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PULL-1', tenantName: 'Pull Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $priceList = PriceList::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true]);

    $product = app(ProductService::class)->create($tenant->id, ['sku' => 'SKU-PULL', 'name' => 'Pull Product', 'base_unit_id' => $unit->id], $owner->id);
    app(PriceListService::class)->setPrice($tenant->id, $priceList->id, $product->id, null, $unit->id, '75.0000');

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->getJson('/api/v1/sync/pull');

    $response->assertStatus(200);
    expect($response->json('data.full'))->toBeTrue();
    $products = $response->json('data.products');
    expect($products)->toHaveCount(1);
    expect($products[0]['sku'])->toBe('SKU-PULL');
    expect(bccomp($products[0]['prices'][0]['price'], '75.0000', 4))->toBe(0);
    expect($response->json('data.version'))->toBeGreaterThan(0);
});

test('an incremental pull (since=version) returns only what changed after that version', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PULL-2', tenantName: 'Pull Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $priceList = PriceList::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true]);

    $productA = app(ProductService::class)->create($tenant->id, ['sku' => 'SKU-PULL-A', 'name' => 'A', 'base_unit_id' => $unit->id], $owner->id);
    app(PriceListService::class)->setPrice($tenant->id, $priceList->id, $productA->id, null, $unit->id, '10.0000');

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];

    $baseline = test()->withHeaders($auth)->getJson('/api/v1/sync/pull')->json('data.version');

    // Only product B changes AFTER the baseline version.
    $productB = app(ProductService::class)->create($tenant->id, ['sku' => 'SKU-PULL-B', 'name' => 'B', 'base_unit_id' => $unit->id], $owner->id);

    $incremental = test()->withHeaders($auth)->getJson('/api/v1/sync/pull?since='.$baseline);

    expect($incremental->json('data.full'))->toBeFalse();
    $products = $incremental->json('data.products');
    expect($products)->toHaveCount(1);
    expect($products[0]['sku'])->toBe('SKU-PULL-B');
    expect($incremental->json('data.version'))->toBeGreaterThan($baseline);
});

test('an incremental pull also picks up a product whose PRICE changed, even if the product itself did not', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PULL-3', tenantName: 'Pull Test Shop 3', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $priceList = PriceList::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true]);
    $product = app(ProductService::class)->create($tenant->id, ['sku' => 'SKU-PULL-C', 'name' => 'C', 'base_unit_id' => $unit->id], $owner->id);
    app(PriceListService::class)->setPrice($tenant->id, $priceList->id, $product->id, null, $unit->id, '10.0000');

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');
    $auth = ['Authorization' => 'Bearer '.$login['access_token']];
    $baseline = test()->withHeaders($auth)->getJson('/api/v1/sync/pull')->json('data.version');

    app(PriceListService::class)->setPrice($tenant->id, $priceList->id, $product->id, null, $unit->id, '15.0000'); // daily rate update, no product change

    $incremental = test()->withHeaders($auth)->getJson('/api/v1/sync/pull?since='.$baseline);
    $products = $incremental->json('data.products');

    expect($products)->toHaveCount(1);
    expect($products[0]['sku'])->toBe('SKU-PULL-C');
    expect(bccomp($products[0]['prices'][0]['price'], '15.0000', 4))->toBe(0);
});
