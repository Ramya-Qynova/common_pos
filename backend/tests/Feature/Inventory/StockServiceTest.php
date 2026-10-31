<?php

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Str;

/**
 * SQLite (the default test connection) has no true DECIMAL type - it
 * normalizes an exact-integer-valued NUMERIC like "70.0000" down to "70"
 * on storage, which real MySQL's DECIMAL(18,4) never does. That's a test-
 * environment storage quirk, not a precision loss (G3 is about never
 * doing float arithmetic, which StockService never does - see bcmath
 * throughout), so assertions compare numeric value via bccomp rather than
 * exact string formatting.
 */
function expectDecimal(string $actual, string $expected): void
{
    expect(bccomp($actual, $expected, 4))->toBe(0);
}

function setUpStockFixture(): array
{
    $tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-STOCK-'.uniqid(),
        tenantName: 'Stock Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );

    $unit = Unit::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'code' => 'pcs', 'name' => 'Pieces']);
    $product = Product::withoutTenantScope()->create([
        'tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-1', 'name' => 'Test Product',
        'base_unit_id' => $unit->id, 'is_active' => true,
    ]);
    $warehouse = Warehouse::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

    return [$tenant, $product, $warehouse];
}

test('receiving stock then selling some leaves a balance that exactly equals the sum of the ledger', function () {
    [$tenant, $product, $warehouse] = setUpStockFixture();
    $service = app(StockService::class);

    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '100.0000', 'unit_cost' => '10.0000', 'source_type' => 'opening_stock',
    ]);
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'out', 'quantity' => '30.0000', 'source_type' => 'sale',
    ]);

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->first();
    expectDecimal($balance->quantity, '70.0000');

    $ledgerSum = StockLedger::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $product->id)
        ->get()
        ->reduce(fn ($carry, $entry) => bcadd($carry, $entry->direction === 'in' ? $entry->quantity : bcmul($entry->quantity, '-1', 4), 4), '0.0000');

    expectDecimal($ledgerSum, $balance->quantity);
});

test('weighted-average cost recalculates correctly across two purchases at different prices', function () {
    [$tenant, $product, $warehouse] = setUpStockFixture();
    $service = app(StockService::class);

    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '10.0000', 'source_type' => 'purchase',
    ]);
    // (10 * 10) = 100 total value, 10 qty -> avg 10.00
    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '10.0000', 'unit_cost' => '20.0000', 'source_type' => 'purchase',
    ]);
    // +(10*20)=200 -> total value 300, qty 20 -> avg 15.00

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->first();
    expectDecimal($balance->average_cost, '15.0000');

    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'out', 'quantity' => '5.0000', 'source_type' => 'sale',
    ]);
    $saleLedger = StockLedger::withoutTenantScope()->where('tenant_id', $tenant->id)->where('direction', 'out')->first();
    // outgoing stock is costed at the CURRENT average, not the original purchase price (section 7 "sale_items stores cost_snapshot").
    expectDecimal($saleLedger->unit_cost, '15.0000');
});

test('selling more than is in stock is blocked with INSUFFICIENT_STOCK when negative stock is disallowed', function () {
    [$tenant, $product, $warehouse] = setUpStockFixture();
    $service = app(StockService::class);

    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'in', 'quantity' => '1.0000', 'unit_cost' => '10.0000', 'source_type' => 'opening_stock',
    ]);

    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'out', 'quantity' => '1.0000', 'source_type' => 'sale',
    ]);

    expect(fn () => $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'out', 'quantity' => '1.0000', 'source_type' => 'sale',
    ]))->toThrow(ApiException::class);

    // Nothing was written by the rejected attempt - balance/ledger unchanged (G4: failure leaves nothing).
    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->first();
    expectDecimal($balance->quantity, '0.0000');
    expect(StockLedger::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('negative stock is allowed when the inventory.allow_negative_stock setting is turned on', function () {
    [$tenant, $product, $warehouse] = setUpStockFixture();
    app(SettingsService::class)->set($tenant->id, 'inventory.allow_negative_stock', true);
    $service = app(StockService::class);

    $service->recordMovement($tenant->id, [
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'direction' => 'out', 'quantity' => '5.0000', 'source_type' => 'sale',
    ]);

    $balance = StockBalance::withoutTenantScope()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->first();
    expectDecimal($balance->quantity, '-5.0000');
});

test('a batch of movements across two products is applied in a consistent (sorted) lock order regardless of input order', function () {
    [$tenant, , $warehouse] = setUpStockFixture();
    $unit = Unit::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
    $productA = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-A', 'name' => 'A', 'base_unit_id' => $unit->id]);
    $productB = Product::withoutTenantScope()->create(['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'sku' => 'SKU-B', 'name' => 'B', 'base_unit_id' => $unit->id]);

    $service = app(StockService::class);

    // Deliberately given in DESCENDING product order (B before A) - the
    // service must still lock/process A before B (ascending product_id),
    // the same order a reversed-input request would also land in, which is
    // exactly what prevents a two-line deadlock (section 13.1).
    $entries = $service->recordMovements($tenant->id, [
        ['warehouse_id' => $warehouse->id, 'product_id' => $productB->id, 'direction' => 'in', 'quantity' => '5.0000', 'unit_cost' => '1.0000', 'source_type' => 'opening_stock'],
        ['warehouse_id' => $warehouse->id, 'product_id' => $productA->id, 'direction' => 'in', 'quantity' => '5.0000', 'unit_cost' => '1.0000', 'source_type' => 'opening_stock'],
    ]);

    expect($entries[0]->product_id)->toBe($productA->id);
    expect($entries[1]->product_id)->toBe($productB->id);
});
