<?php

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalogue\PriceListService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('changing a price closes the old history row and keeps the current-price lookup in sync', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-PRICE-'.uniqid(),
        tenantName: 'Price Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'P-1', 'name' => 'Priced', 'base_unit_id' => $unit->id]);
    $priceList = PriceList::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'code' => 'retail', 'name' => 'Retail', 'is_default' => true]);

    $service = app(PriceListService::class);

    $first = $service->setPrice($tenant->id, $priceList->id, $product->id, null, $unit->id, '100.0000');
    // SQLite (the default test connection) normalizes "100.0000" to "100"
    // on storage - a storage-format quirk, not a precision loss (real
    // MySQL's DECIMAL(18,4) keeps the scale) - so compare numeric value.
    expect(bccomp($service->currentPrice($tenant->id, $priceList->id, $product->id, null, $unit->id), '100.0000', 4))->toBe(0);

    $service->setPrice($tenant->id, $priceList->id, $product->id, null, $unit->id, '120.0000');
    expect(bccomp($service->currentPrice($tenant->id, $priceList->id, $product->id, null, $unit->id), '120.0000', 4))->toBe(0);

    // History is preserved, not overwritten.
    $first->refresh();
    expect($first->effective_to)->not->toBeNull();
    expect(PriceListItem::withoutTenantScope()->where('product_id', $product->id)->count())->toBe(2);
});
