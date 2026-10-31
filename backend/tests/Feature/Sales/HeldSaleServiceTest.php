<?php

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Register;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Onboarding\OnboardingService;
use App\Services\Sales\HeldSaleService;
use Illuminate\Support\Str;

test('a held bill stores its lines and can be resumed, returning them with no stored price (G1)', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-HOLD-1', tenantName: 'Held Bill Test Shop', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'HOLD-1', 'name' => 'Held Product', 'base_unit_id' => $unit->id]);

    $service = app(HeldSaleService::class);

    $held = $service->hold($tenant->id, [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '3.0000']],
    ], $owner->id);

    expect($held->status)->toBe('held');
    expect($held->items)->toHaveCount(1);

    $resumedLines = $service->resume($tenant->id, $held->id);

    expect($resumedLines)->toHaveCount(1);
    expect($resumedLines[0]['product_id'])->toBe($product->id);
    expect($resumedLines[0])->not->toHaveKey('unit_price'); // no frozen price - G1

    $held->refresh();
    expect($held->status)->toBe('resumed');
});

test('a held bill cannot be resumed twice', function () {
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-HOLD-2', tenantName: 'Held Bill Test Shop 2', planCode: 'standard',
        templateCode: 'general_retail', ownerUsername: 'owner', ownerPassword: 'correct-password', ownerEmail: 'owner@example.test',
    );
    $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $branch = Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $register = Register::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'HOLD-2', 'name' => 'Held Product 2', 'base_unit_id' => $unit->id]);

    $service = app(HeldSaleService::class);
    $held = $service->hold($tenant->id, [
        'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'register_id' => $register->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $unit->id, 'quantity' => '1.0000']],
    ], $owner->id);

    $service->resume($tenant->id, $held->id);

    expect(fn () => $service->resume($tenant->id, $held->id))->toThrow(ApiException::class);
});
