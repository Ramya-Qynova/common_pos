<?php

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Catalogue\ProductService;
use App\Services\Onboarding\OnboardingService;

function onboardForCatalogue(): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-CAT-'.uniqid(),
        tenantName: 'Catalogue Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
}

test('creating a product with a duplicate SKU is rejected', function () {
    $tenant = onboardForCatalogue();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $service = app(ProductService::class);

    $service->create($tenant->id, ['sku' => 'DUP-1', 'name' => 'First', 'base_unit_id' => $unit->id]);

    expect(fn () => $service->create($tenant->id, ['sku' => 'DUP-1', 'name' => 'Second', 'base_unit_id' => $unit->id]))
        ->toThrow(ApiException::class);
});

test('archiving a product soft-archives it rather than deleting it (G9)', function () {
    $tenant = onboardForCatalogue();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $service = app(ProductService::class);

    $product = $service->create($tenant->id, ['sku' => 'ARC-1', 'name' => 'Archivable', 'base_unit_id' => $unit->id]);
    $service->archive($product);

    $product->refresh();
    expect($product->archived_at)->not->toBeNull();
    expect($product->is_active)->toBeFalse();
    expect(Product::withoutTenantScope()->where('id', $product->id)->exists())->toBeTrue();
});
