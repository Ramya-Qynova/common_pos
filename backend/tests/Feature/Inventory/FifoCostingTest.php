
<?php

use App\Models\CostLayer;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Str;

function expectFifoDecimal(string $actual, string $expected): void
{
    expect(bccomp($actual, $expected, 4))->toBe(0);
}

function setUpFifoFixture(string $costingMethod = 'fifo'): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-FIFO-'.uniqid(),
        tenantName: 'FIFO Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );

    app(SettingsService::class)->set($tenant->id, 'inventory.costing_method', $costingMethod);

    $unit = Unit::withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'code' => 'pcs',
        'name' => 'Pieces',
    ]);

    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id,
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-FIFO-1',
        'name' => 'FIFO Product',
        'base_unit_id' => $unit->id,
        'is_active' => true,
    ]);

    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    return [$tenant, $product, $warehouse];
}

test('inflows create cost layers in FIFO mode and outflow depletes layers oldest first', function () {
    [$tenant, $product, $warehouse] = setUpFifoFixture('fifo');
    $service = app(StockService::class);

    // Layer 1: 10 units at 10.0000
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'in',
        'quantity' => '10.0000',
        'unit_cost' => '10.0000',
        'source_type' => 'purchase_receiving',
    ], ['received_at' => now()->subHours(2)]);

    // Layer 2: 10 units at 20.0000
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'in',
        'quantity' => '10.0000',
        'unit_cost' => '20.0000',
        'source_type' => 'purchase_receiving',
    ], ['received_at' => now()->subHour()]);

    $layers = CostLayer::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->orderBy('received_at', 'asc')
        ->get();

    expect($layers)->toHaveCount(2);
    expectFifoDecimal((string) $layers[0]->quantity_remaining, '10.0000');
    expectFifoDecimal((string) $layers[0]->unit_cost, '10.0000');
    expectFifoDecimal((string) $layers[1]->quantity_remaining, '10.0000');
    expectFifoDecimal((string) $layers[1]->unit_cost, '20.0000');

    // Outflow 1: 5 units (should deplete from Layer 1 at 10.0000)
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'out',
        'quantity' => '5.0000',
        'source_type' => 'sale',
    ]);

    $layers[0]->refresh();
    $layers[1]->refresh();
    expectFifoDecimal((string) $layers[0]->quantity_remaining, '5.0000');
    expectFifoDecimal((string) $layers[1]->quantity_remaining, '10.0000');

    $lastLedger = StockLedger::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', 'out')
        ->latest('id')
        ->first();
    expectFifoDecimal((string) $lastLedger->unit_cost, '10.0000');

    // Outflow 2: 10 units (5 from Layer 1 @ 10 + 5 from Layer 2 @ 20 => blended 15.0000)
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'out',
        'quantity' => '10.0000',
        'source_type' => 'sale',
    ]);

    $layers[0]->refresh();
    $layers[1]->refresh();
    expectFifoDecimal((string) $layers[0]->quantity_remaining, '0.0000');
    expectFifoDecimal((string) $layers[1]->quantity_remaining, '5.0000');

    $lastLedger = StockLedger::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', 'out')
        ->latest('id')
        ->first();
    expectFifoDecimal((string) $lastLedger->unit_cost, '15.0000');

    // Outflow 3: 5 units (should exhaust remaining 5 from Layer 2 @ 20.0000)
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'out',
        'quantity' => '5.0000',
        'source_type' => 'sale',
    ]);

    $layers[1]->refresh();
    expectFifoDecimal((string) $layers[1]->quantity_remaining, '0.0000');

    $balance = StockBalance::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->first();
    expectFifoDecimal((string) $balance->quantity, '0.0000');
});

test('weighted_average costing does not create cost layers and averages outflow costs', function () {
    [$tenant, $product, $warehouse] = setUpFifoFixture('weighted_average');
    $service = app(StockService::class);

    // Inflow 1: 10 @ 10.0000
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'in',
        'quantity' => '10.0000',
        'unit_cost' => '10.0000',
        'source_type' => 'purchase_receiving',
    ]);

    // Inflow 2: 10 @ 20.0000
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'in',
        'quantity' => '10.0000',
        'unit_cost' => '20.0000',
        'source_type' => 'purchase_receiving',
    ]);

    $layersCount = CostLayer::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->count();
    expect($layersCount)->toBe(0);

    $balance = StockBalance::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->first();
    expectFifoDecimal((string) $balance->average_cost, '15.0000');

    // Outflow 5 units
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'out',
        'quantity' => '5.0000',
        'source_type' => 'sale',
    ]);

    $outLedger = StockLedger::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', 'out')
        ->first();
    expectFifoDecimal((string) $outLedger->unit_cost, '15.0000');
});

test('GET /inventory/cost-layers endpoint returns remaining layers for warehouse and product', function () {
    [$tenant, $product, $warehouse] = setUpFifoFixture('fifo');
    $service = app(StockService::class);

    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'direction' => 'in',
        'quantity' => '15.0000',
        'unit_cost' => '12.5000',
        'source_type' => 'purchase_receiving',
    ]);

    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ])->json('data');

    $response = test()->getJson("/api/v1/inventory/cost-layers?warehouse_id={$warehouse->id}&product_id={$product->id}", [
        'Authorization' => 'Bearer '.$login['access_token'],
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['product_id'])->toBe($product->id);
    expectFifoDecimal((string) $data[0]['quantity_remaining'], '15.0000');
    expectFifoDecimal((string) $data[0]['unit_cost'], '12.5000');
    expectFifoDecimal((string) $data[0]['total_value'], '187.5000');
});
