<?php

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('posting an opening-stock adjustment creates the adjustment record and moves real stock through the ledger', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-ADJ-'.uniqid(),
        tenantName: 'Adjustment Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $product = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'ADJ-1', 'name' => 'Adjustable', 'base_unit_id' => $unit->id]);
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    $adjustment = app(StockAdjustmentService::class)->post(
        $tenant->id, $warehouse->id, 'opening_stock', 'Initial stock count',
        [['product_id' => $product->id, 'direction' => 'in', 'quantity' => '25.0000', 'unit_cost' => '4.0000']],
        $owner->id,
    );

    expect($adjustment->items)->toHaveCount(1);

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->first();
    expect(bccomp($balance->quantity, '25.0000', 4))->toBe(0);
});

test('a stock adjustment without a reason is rejected', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-ADJ2-'.uniqid(),
        tenantName: 'Adjustment Test Shop 2',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    expect(fn () => app(StockAdjustmentService::class)->post($tenant->id, $warehouse->id, 'adjustment', '  ', [], $owner->id))
        ->toThrow(InvalidArgumentException::class);
});
