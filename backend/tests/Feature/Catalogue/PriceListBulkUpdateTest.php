<?php

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalogue\PriceListService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\TemplateUpdateService;
use Illuminate\Support\Str;

/**
 * section 12 Phase 6 "daily rate update": a generic bulk price change any
 * tenant can call - grocery owners happen to use it every morning when
 * vegetable rates move, but PriceListService::bulkSetPrices() has no idea
 * what template is calling it.
 */
test('a bulk price update changes every listed product in one call and preserves price history for each', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-RATE-1', tenantName: 'Rate Update Shop', planCode: 'standard',
        templateCode: 'grocery', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    app(TemplateUpdateService::class)->apply($tenant->id, $owner->id);

    $kg = Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'kg')->first();
    $priceList = PriceList::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true,
    ]);

    $tomato = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-TOM', 'name' => 'Tomato', 'base_unit_id' => $kg->id]);
    $onion = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-ON', 'name' => 'Onion', 'base_unit_id' => $kg->id]);
    app(PriceListService::class)->setPrice($tenant->id, $priceList->id, $tomato->id, null, $kg->id, '30.0000');
    app(PriceListService::class)->setPrice($tenant->id, $priceList->id, $onion->id, null, $kg->id, '25.0000');

    $login = test()->postJson('/api/v1/auth/login', ['shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password'])->json('data');

    $response = test()->withHeader('Authorization', 'Bearer '.$login['access_token'])->postJson("/api/v1/price-lists/{$priceList->id}/bulk-update", [
        'updates' => [
            ['product_id' => $tomato->id, 'unit_id' => $kg->id, 'price' => '45.0000'], // tomato rate jumped overnight
            ['product_id' => $onion->id, 'unit_id' => $kg->id, 'price' => '20.0000'],
        ],
    ]);

    $response->assertStatus(201);

    expect(bccomp(app(PriceListService::class)->currentPrice($tenant->id, $priceList->id, $tomato->id, null, $kg->id), '45.0000', 4))->toBe(0);
    expect(bccomp(app(PriceListService::class)->currentPrice($tenant->id, $priceList->id, $onion->id, null, $kg->id), '20.0000', 4))->toBe(0);

    // History preserved - 2 rows per product (the original + the rate update), not overwritten.
    expect(PriceListItem::withoutTenantScope()->where('product_id', $tomato->id)->count())->toBe(2);
    expect(PriceListItem::withoutTenantScope()->where('product_id', $onion->id)->count())->toBe(2);

    expect(ProductPrice::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $tomato->id)->count())->toBe(1);
});
