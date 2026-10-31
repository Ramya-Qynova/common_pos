<?php

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Support\Str;

test('a balance at or below its reorder point is reported as low stock, and one above it is not', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-LOWSTOCK-'.uniqid(),
        tenantName: 'Low Stock Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $low = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'LOW', 'name' => 'Low', 'base_unit_id' => $unit->id]);
    $healthy = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'OK', 'name' => 'Healthy', 'base_unit_id' => $unit->id]);

    $service = app(StockService::class);
    $service->recordMovement($tenant->id, ['warehouse_id' => $warehouse->id, 'product_id' => $low->id, 'direction' => 'in', 'quantity' => '2.0000', 'unit_cost' => '1.0000', 'source_type' => 'opening_stock']);
    $service->recordMovement($tenant->id, ['warehouse_id' => $warehouse->id, 'product_id' => $healthy->id, 'direction' => 'in', 'quantity' => '50.0000', 'unit_cost' => '1.0000', 'source_type' => 'opening_stock']);

    StockBalance::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $low->id)->update(['reorder_point' => '5.0000']);
    StockBalance::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $healthy->id)->update(['reorder_point' => '5.0000']);

    $lowStock = $service->lowStockBalances($tenant->id);

    expect($lowStock->pluck('product_id')->all())->toBe([$low->id]);
});
