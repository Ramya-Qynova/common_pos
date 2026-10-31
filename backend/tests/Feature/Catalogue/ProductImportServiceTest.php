<?php

use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalogue\ProductImportService;
use App\Services\Onboarding\OnboardingService;

test('import accepts valid rows and reports every rejected row with a reason, never silently skipping one', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-IMPORT-'.uniqid(),
        tenantName: 'Import Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);

    $rows = [
        ['sku' => 'IMP-1', 'name' => 'Good Product', 'base_unit_code' => 'pcs'],
        ['sku' => '', 'name' => 'Missing SKU', 'base_unit_code' => 'pcs'],
        ['sku' => 'IMP-2', 'name' => 'Unknown Unit', 'base_unit_code' => 'does-not-exist'],
        ['sku' => 'IMP-1', 'name' => 'Duplicate of row 1', 'base_unit_code' => 'pcs'],
        ['sku' => 'IMP-3', 'name' => 'Also Good', 'base_unit_code' => 'pcs'],
    ];

    $job = app(ProductImportService::class)->import($tenant->id, $rows);

    expect($job->total_rows)->toBe(5);
    expect($job->accepted_rows)->toBe(2);
    expect($job->rejected_rows)->toBe(3);
    expect($job->errors)->toHaveCount(3);
    expect(collect($job->errors)->pluck('row')->all())->toBe([2, 3, 4]);

    expect(Product::withoutTenantScope()->where('tenant_id', $tenant->id)->where('sku', 'IMP-1')->exists())->toBeTrue();
    expect(Product::withoutTenantScope()->where('tenant_id', $tenant->id)->where('sku', 'IMP-3')->exists())->toBeTrue();
    expect(Product::withoutTenantScope()->where('tenant_id', $tenant->id)->where('sku', 'IMP-2')->exists())->toBeFalse();
});
